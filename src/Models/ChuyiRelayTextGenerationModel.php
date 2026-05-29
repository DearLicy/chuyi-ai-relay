<?php
/**
 * 初一 AI 中转 text generation model.
 *
 * @package WordPress\ChuyiAiRelay\Models
 */

declare(strict_types=1);

namespace WordPress\ChuyiAiRelay\Models;

use WordPress\AiClient\Providers\Http\Contracts\RequestAuthenticationInterface;
use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\OpenAiCompatibleImplementation\AbstractOpenAiCompatibleTextGenerationModel;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use WordPress\ChuyiAiRelay\Provider\ChuyiRelayAnthropicApiKeyRequestAuthentication;
use WordPress\ChuyiAiRelay\Settings;

/**
 * Text generation through OpenAI-compatible or Anthropic-compatible relay endpoints.
 */
final class ChuyiRelayTextGenerationModel extends AbstractOpenAiCompatibleTextGenerationModel
{
    /**
     * {@inheritDoc}
     */
    protected function prepareGenerateTextParams(array $prompt): array
    {
        $params = parent::prepareGenerateTextParams($prompt);
        $params = $this->applyGlobalGenerationOptions($params);

        if ($this->isAnthropicMode()) {
            $topK = $this->getConfig()->getTopK();
            if ($topK !== null && !isset($params['top_k'])) {
                $params['top_k'] = $topK;
            }
        }

        return $params;
    }

    /**
     * Applies plugin-wide defaults without touching official AI plugin code.
     *
     * @param array<string,mixed> $params OpenAI-compatible params.
     * @return array<string,mixed>
     */
    private function applyGlobalGenerationOptions(array $params): array
    {
        $maxOutputTokens = Settings::getMaxOutputTokens();
        if ($maxOutputTokens > 0) {
            $params['max_tokens'] = $maxOutputTokens;
        }

        $contextMaxTokens = Settings::getContextMaxTokens();
        if ($contextMaxTokens > 0) {
            $params['metadata'] = isset($params['metadata']) && is_array($params['metadata']) ? $params['metadata'] : array();
            $params['metadata']['chuyi_context_max_tokens'] = $contextMaxTokens;
        }

        $thinkingDepth = Settings::getThinkingDepth();
        if ($thinkingDepth !== Settings::THINKING_DEPTH_OFF) {
            $params['reasoning_effort'] = $thinkingDepth;
            $params['metadata'] = isset($params['metadata']) && is_array($params['metadata']) ? $params['metadata'] : array();
            $params['metadata']['chuyi_thinking_depth'] = $thinkingDepth;
        }

        return $params;
    }

    /**
     * Maps a human setting to a conservative token budget for providers that support thinking.
     */
    private function getThinkingBudget(string $thinkingDepth): int
    {
        if ($thinkingDepth === Settings::THINKING_DEPTH_HIGH) {
            return 4096;
        }
        if ($thinkingDepth === Settings::THINKING_DEPTH_MEDIUM) {
            return 2048;
        }

        return 1024;
    }

    /**
     * {@inheritDoc}
     */
    public function getRequestAuthentication(): RequestAuthenticationInterface
    {
        $requestAuthentication = parent::getRequestAuthentication();
        if (!$this->isAnthropicMode() || !$requestAuthentication instanceof ApiKeyRequestAuthentication) {
            return $requestAuthentication;
        }

        return new ChuyiRelayAnthropicApiKeyRequestAuthentication($requestAuthentication->getApiKey());
    }

    /**
     * {@inheritDoc}
     */
    protected function createRequest(HttpMethodEnum $method, string $path, array $headers = array(), $data = null): Request
    {
        if ($this->isAnthropicMode()) {
            $headers['Content-Type'] = 'application/json';
            $anthropicData = is_array($data) ? $this->prepareAnthropicMessagesParams($data) : $data;

            if (is_array($anthropicData) && isset($anthropicData['output_format'])) {
                $headers['anthropic-beta'] = 'structured-outputs-2025-11-13';
            }

            $url = Settings::urlForProviderId($this->providerMetadata()->getId(), 'messages');
            $this->logRelayTextRequest($url, 'messages');

            return new Request(
                $method,
                $url,
                $headers,
                $anthropicData,
                $this->getRelayRequestOptions()
            );
        }

        $url = Settings::urlForProviderId($this->providerMetadata()->getId(), $path);
        $this->logRelayTextRequest($url, $path);

        return new Request(
            $method,
            $url,
            $headers,
            $data,
            $this->getRelayRequestOptions()
        );
    }

    /**
     * Records the final relay text request target without exposing secrets.
     */
    private function logRelayTextRequest(string $url, string $path): void
    {
        $providerId = $this->providerMetadata()->getId();
        $slotId = Settings::getSlotIdForProviderId($providerId);
        $message = sprintf(
            '[chuyi-ai-relay] text request target provider=%s slot=%s model=%s base_url=%s endpoint=%s timeout=%s',
            $providerId,
            $slotId,
            $this->metadata()->getId(),
            Settings::getBaseUrl($slotId),
            $url,
            (string) Settings::getImageGenerationTimeout()
        );

        error_log($message);
    }

    /**
     * Keeps relay text requests from being cut off by the default WordPress HTTP timeout.
     */
    private function getRelayRequestOptions(): RequestOptions
    {
        $options = $this->getRequestOptions();
        $options = $options instanceof RequestOptions ? clone $options : new RequestOptions();
        $options->setTimeout((float) Settings::getImageGenerationTimeout());

        return $options;
    }

    /**
     * {@inheritDoc}
     */
    protected function parseResponseToGenerativeAiResult(Response $response): GenerativeAiResult
    {
        if (!$this->isAnthropicMode()) {
            return parent::parseResponseToGenerativeAiResult($this->normalizeStructuredJsonResponse($this->normalizeOpenAiTextResponse($response)));
        }

        return parent::parseResponseToGenerativeAiResult($this->normalizeStructuredJsonResponse($this->convertAnthropicResponse($response)));
    }

    /**
     * Converts common non-standard text response envelopes to OpenAI-compatible choices.
     */
    private function normalizeOpenAiTextResponse(Response $response): Response
    {
        $responseData = $response->getData();
        if (!is_array($responseData)) {
            return $response;
        }

        if (!empty($responseData['choices']) && is_array($responseData['choices'])) {
            return $response;
        }

        $content = $this->extractTextFromResponseData($responseData);
        if ($content === '') {
            return $response;
        }

        $responseData['id'] = isset($responseData['id']) && is_string($responseData['id']) ? $responseData['id'] : 'chuyi-relay-' . md5($content);
        $responseData['choices'] = array(array(
            'message'       => array(
                'role'    => 'assistant',
                'content' => $content,
            ),
            'finish_reason' => 'stop',
        ));

        $body = wp_json_encode($responseData);
        if (!is_string($body)) {
            return $response;
        }

        return new Response($response->getStatusCode(), $response->getHeaders(), $body);
    }

    /**
     * Extracts a text answer from known relay response shapes.
     *
     * @param array<string,mixed> $responseData
     */
    private function extractTextFromResponseData(array $responseData): string
    {
        foreach (array('output_text', 'text', 'content', 'response', 'result', 'answer') as $key) {
            if (isset($responseData[$key]) && is_string($responseData[$key]) && trim($responseData[$key]) !== '') {
                return trim($responseData[$key]);
            }
        }

        if (isset($responseData['message'])) {
            if (is_string($responseData['message']) && trim($responseData['message']) !== '') {
                return trim($responseData['message']);
            }

            if (is_array($responseData['message'])) {
                $messageContent = $this->extractTextFromResponseData($responseData['message']);
                if ($messageContent !== '') {
                    return $messageContent;
                }
            }
        }

        if (isset($responseData['data'])) {
            if (is_string($responseData['data']) && trim($responseData['data']) !== '') {
                return trim($responseData['data']);
            }

            if (is_array($responseData['data'])) {
                $dataContent = $this->extractTextFromNestedData($responseData['data']);
                if ($dataContent !== '') {
                    return $dataContent;
                }
            }
        }

        if (isset($responseData['output']) && is_array($responseData['output'])) {
            $outputContent = $this->extractTextFromNestedData($responseData['output']);
            if ($outputContent !== '') {
                return $outputContent;
            }
        }

        return '';
    }

    /**
     * Extracts text from nested response lists or objects.
     *
     * @param array<mixed> $items
     */
    private function extractTextFromNestedData(array $items): string
    {
        foreach ($items as $item) {
            if (is_string($item) && trim($item) !== '') {
                return trim($item);
            }

            if (!is_array($item)) {
                continue;
            }

            $content = $this->extractTextFromResponseData($item);
            if ($content !== '') {
                return $content;
            }
        }

        return '';
    }

    /**
     * Normalizes common structured JSON response drifts from relay models.
     */
    private function normalizeStructuredJsonResponse(Response $response): Response
    {
        $responseData = $response->getData();
        if (!is_array($responseData) || empty($responseData['choices']) || !is_array($responseData['choices'])) {
            return $response;
        }

        $changed = false;
        foreach ($responseData['choices'] as $index => $choice) {
            if (!is_array($choice) || empty($choice['message']) || !is_array($choice['message'])) {
                continue;
            }

            $content = isset($choice['message']['content']) && is_string($choice['message']['content']) ? trim($choice['message']['content']) : '';
            if ($content === '') {
                continue;
            }

            $normalized = $this->normalizeStructuredJsonText($content);
            if ($normalized !== $content) {
                $responseData['choices'][$index]['message']['content'] = $normalized;
                $changed = true;
            }
        }

        if (!$changed) {
            return $response;
        }

        $body = wp_json_encode($responseData);
        if (!is_string($body)) {
            return $response;
        }

        return new Response($response->getStatusCode(), $response->getHeaders(), $body);
    }

    /**
     * Converts known valid-but-loose JSON payloads to ability schemas.
     */
    private function normalizeStructuredJsonText(string $content): string
    {
        $json = $this->extractJsonPayload($content);
        if ($json === '') {
            return $content;
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return $content;
        }

        if ($this->isListArray($decoded) && (empty($decoded) || $this->looksLikeTaxonomySuggestions($decoded))) {
            $encoded = wp_json_encode(array('suggestions' => $decoded), JSON_UNESCAPED_UNICODE);
            return is_string($encoded) ? $encoded : $content;
        }

        return $json;
    }

    /**
     * Extracts JSON from plain text or fenced blocks.
     */
    private function extractJsonPayload(string $content): string
    {
        $content = trim($content);
        if ($content === '') {
            return '';
        }

        if (preg_match('/```(?:json)?\s*([\s\S]*?)\s*```/i', $content, $matches)) {
            $content = trim($matches[1]);
        }

        if ($content !== '' && ($content[0] === '{' || $content[0] === '[')) {
            return $content;
        }

        $objectPos = strpos($content, '{');
        $arrayPos = strpos($content, '[');
        $start = false;
        if ($objectPos !== false && $arrayPos !== false) {
            $start = min($objectPos, $arrayPos);
        } elseif ($objectPos !== false) {
            $start = $objectPos;
        } elseif ($arrayPos !== false) {
            $start = $arrayPos;
        }

        if ($start === false) {
            return '';
        }

        $candidate = trim(substr($content, $start));
        $lastObject = strrpos($candidate, '}');
        $lastArray = strrpos($candidate, ']');
        $end = false;
        if ($lastObject !== false && $lastArray !== false) {
            $end = max($lastObject, $lastArray);
        } elseif ($lastObject !== false) {
            $end = $lastObject;
        } elseif ($lastArray !== false) {
            $end = $lastArray;
        }

        return $end === false ? '' : trim(substr($candidate, 0, $end + 1));
    }

    /**
     * Checks whether an array uses consecutive integer keys.
     *
     * @param array<mixed> $items Array to inspect.
     */
    private function isListArray(array $items): bool
    {
        if (empty($items)) {
            return true;
        }

        return array_keys($items) === range(0, count($items) - 1);
    }

    /**
     * Checks whether a list is a taxonomy suggestion payload.
     *
     * @param array<mixed> $items Decoded JSON list.
     */
    private function looksLikeTaxonomySuggestions(array $items): bool
    {
        if (empty($items)) {
            return false;
        }

        foreach ($items as $item) {
            if (!is_array($item) || empty($item['term'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Returns whether this model belongs to an Anthropic-mode slot.
     */
    private function isAnthropicMode(): bool
    {
        return Settings::getMode(Settings::getSlotIdForProviderId($this->providerMetadata()->getId())) === Settings::MODE_ANTHROPIC;
    }

    /**
     * Converts OpenAI chat params prepared by the base class into Anthropic /messages params.
     *
     * @param array<string,mixed> $params OpenAI-compatible params.
     * @return array<string,mixed>
     */
    private function prepareAnthropicMessagesParams(array $params): array
    {
        $anthropic = array(
            'model'      => isset($params['model']) && is_string($params['model']) ? $params['model'] : $this->metadata()->getId(),
            'messages'   => array(),
            'max_tokens' => isset($params['max_tokens']) ? (int) $params['max_tokens'] : 4096,
        );

        if (isset($params['messages']) && is_array($params['messages'])) {
            foreach ($params['messages'] as $message) {
                if (!is_array($message)) {
                    continue;
                }

                $role = isset($message['role']) && is_string($message['role']) ? $message['role'] : 'user';
                $content = $message['content'] ?? '';
                if ($role === 'system') {
                    $system = $this->extractTextContent($content);
                    if ($system !== '') {
                        $anthropic['system'] = isset($anthropic['system']) ? $anthropic['system'] . "\n" . $system : $system;
                    }
                    continue;
                }

                if ($role === 'tool') {
                    $toolUseId = isset($message['tool_call_id']) && is_string($message['tool_call_id']) ? $message['tool_call_id'] : '';
                    if ($toolUseId === '') {
                        continue;
                    }

                    $anthropic['messages'][] = array(
                        'role'    => 'user',
                        'content' => array(array(
                            'type'        => 'tool_result',
                            'tool_use_id' => $toolUseId,
                            'content'     => $this->extractTextContent($content),
                        )),
                    );
                    continue;
                }

                $messageContent = $this->prepareAnthropicContent($content);
                if ($role === 'assistant' && isset($message['tool_calls']) && is_array($message['tool_calls'])) {
                    $messageContent = array_merge($messageContent, $this->prepareAnthropicToolUseParts($message['tool_calls']));
                }

                $anthropic['messages'][] = array(
                    'role'    => $role === 'assistant' ? 'assistant' : 'user',
                    'content' => $messageContent,
                );
            }
        }

        if (empty($anthropic['messages'])) {
            $anthropic['messages'][] = array(
                'role'    => 'user',
                'content' => array(array('type' => 'text', 'text' => '')),
            );
        }

        if (isset($params['temperature'])) {
            $anthropic['temperature'] = $params['temperature'];
        }
        if (isset($params['top_p'])) {
            $anthropic['top_p'] = $params['top_p'];
        }
        if (isset($params['stop']) && is_array($params['stop'])) {
            $anthropic['stop_sequences'] = array_values($params['stop']);
        }
        if (isset($params['response_format']) && is_array($params['response_format'])) {
            $schema = isset($params['response_format']['json_schema']['schema']) ? $params['response_format']['json_schema']['schema'] : null;
            if (is_array($schema)) {
                $anthropic['output_format'] = array(
                    'type'   => 'json_schema',
                    'schema' => $schema,
                );
            }
        }
        if (isset($params['tools']) && is_array($params['tools'])) {
            $tools = $this->prepareAnthropicTools($params['tools']);
            if (!empty($tools)) {
                $anthropic['tools'] = $tools;
            }
        }
        if (isset($params['top_k'])) {
            $anthropic['top_k'] = $params['top_k'];
        }
        if (isset($params['metadata']) && is_array($params['metadata'])) {
            $anthropic['metadata'] = $params['metadata'];
        }
        if (isset($params['reasoning_effort']) && is_string($params['reasoning_effort'])) {
            $thinkingBudget = $this->getThinkingBudget($params['reasoning_effort']);
            $anthropic['thinking'] = array(
                'type'          => 'enabled',
                'budget_tokens' => min($thinkingBudget, max(1024, $anthropic['max_tokens'] - 1)),
            );
            $anthropic['max_tokens'] = max($anthropic['max_tokens'], $anthropic['thinking']['budget_tokens'] + 1);
        }

        $customOptions = $this->getConfig()->getCustomOptions();
        foreach ($customOptions as $key => $value) {
            if (!isset($anthropic[$key])) {
                $anthropic[$key] = $value;
            }
        }

        return $anthropic;
    }

    /**
     * Converts OpenAI content parts to Anthropic content parts.
     *
     * @param mixed $content OpenAI content value.
     * @return list<array<string,mixed>>
     */
    private function prepareAnthropicContent($content): array
    {
        if (is_string($content)) {
            return array(array('type' => 'text', 'text' => $content));
        }

        if (!is_array($content)) {
            return array(array('type' => 'text', 'text' => ''));
        }

        $parts = array();
        foreach ($content as $part) {
            if (is_string($part)) {
                $parts[] = array('type' => 'text', 'text' => $part);
                continue;
            }
            if (!is_array($part)) {
                continue;
            }

            $type = isset($part['type']) && is_string($part['type']) ? $part['type'] : '';
            if ($type === 'text') {
                $parts[] = array('type' => 'text', 'text' => isset($part['text']) && is_string($part['text']) ? $part['text'] : '');
                continue;
            }
            if ($type === 'image_url' && isset($part['image_url']) && is_array($part['image_url'])) {
                $imageUrl = isset($part['image_url']['url']) && is_string($part['image_url']['url']) ? $part['image_url']['url'] : '';
                $imagePart = $this->prepareAnthropicImagePart($imageUrl);
                if ($imagePart !== null) {
                    $parts[] = $imagePart;
                }
            }
        }

        return !empty($parts) ? $parts : array(array('type' => 'text', 'text' => ''));
    }

    /**
     * Converts OpenAI assistant tool call history to Anthropic tool_use parts.
     *
     * @param array<mixed> $toolCalls OpenAI tool call records.
     * @return list<array<string,mixed>>
     */
    private function prepareAnthropicToolUseParts(array $toolCalls): array
    {
        $parts = array();
        foreach ($toolCalls as $toolCall) {
            if (!is_array($toolCall) || empty($toolCall['function']) || !is_array($toolCall['function'])) {
                continue;
            }

            $id = isset($toolCall['id']) && is_string($toolCall['id']) ? $toolCall['id'] : '';
            $function = $toolCall['function'];
            $name = isset($function['name']) && is_string($function['name']) ? $function['name'] : '';
            if ($id === '' || $name === '') {
                continue;
            }

            $arguments = $function['arguments'] ?? new \stdClass();
            if (is_string($arguments)) {
                $decoded = json_decode($arguments, true);
                $arguments = is_array($decoded) ? $decoded : new \stdClass();
            } elseif (!is_array($arguments) && !$arguments instanceof \stdClass) {
                $arguments = new \stdClass();
            }
            if (is_array($arguments) && empty($arguments)) {
                $arguments = new \stdClass();
            }

            $parts[] = array(
                'type'  => 'tool_use',
                'id'    => $id,
                'name'  => $name,
                'input' => $arguments,
            );
        }

        return $parts;
    }

    /**
     * Converts image URL or data URI to an Anthropic image part.
     *
     * @return array<string,mixed>|null
     */
    private function prepareAnthropicImagePart(string $url): ?array
    {
        if ($url === '') {
            return null;
        }

        if (preg_match('#^data:(image/[a-z0-9.+-]+);base64,(.+)$#i', $url, $matches)) {
            return array(
                'type'   => 'image',
                'source' => array(
                    'type'       => 'base64',
                    'media_type' => $matches[1],
                    'data'       => preg_replace('/\s+/', '', $matches[2]),
                ),
            );
        }

        return array(
            'type'   => 'image',
            'source' => array(
                'type' => 'url',
                'url'  => $url,
            ),
        );
    }

    /**
     * Extracts plain text from OpenAI content.
     *
     * @param mixed $content OpenAI content value.
     */
    private function extractTextContent($content): string
    {
        if (is_string($content)) {
            return trim($content);
        }

        if (!is_array($content)) {
            return '';
        }

        $fragments = array();
        foreach ($content as $part) {
            if (is_string($part)) {
                $fragments[] = $part;
                continue;
            }
            if (is_array($part) && isset($part['text']) && is_string($part['text'])) {
                $fragments[] = $part['text'];
            }
        }

        return trim(implode("\n", $fragments));
    }

    /**
     * Converts OpenAI tool declarations to Anthropic tool declarations.
     *
     * @param array<mixed> $tools OpenAI tool declarations.
     * @return list<array<string,mixed>>
     */
    private function prepareAnthropicTools(array $tools): array
    {
        $converted = array();
        foreach ($tools as $tool) {
            if (!is_array($tool) || empty($tool['function']) || !is_array($tool['function'])) {
                continue;
            }

            $function = $tool['function'];
            if (empty($function['name']) || !is_string($function['name'])) {
                continue;
            }

            $converted[] = array_filter(array(
                'name'         => $function['name'],
                'description'  => isset($function['description']) && is_string($function['description']) ? $function['description'] : null,
                'input_schema' => isset($function['parameters']) && is_array($function['parameters'])
                    ? $function['parameters']
                    : array('type' => 'object', 'properties' => new \stdClass()),
            ));
        }

        return $converted;
    }

    /**
     * Converts an Anthropic /messages response into an OpenAI chat-completions response.
     */
    private function convertAnthropicResponse(Response $response): Response
    {
        $data = $response->getData();
        if (!is_array($data)) {
            return $response;
        }

        $content = '';
        $toolCalls = array();
        if (isset($data['content']) && is_array($data['content'])) {
            foreach ($data['content'] as $part) {
                if (!is_array($part)) {
                    continue;
                }
                if (isset($part['type']) && $part['type'] === 'text' && isset($part['text']) && is_string($part['text'])) {
                    $content .= ($content === '' ? '' : "\n") . $part['text'];
                    continue;
                }
                if (isset($part['type']) && $part['type'] === 'thinking' && isset($part['thinking']) && is_string($part['thinking'])) {
                    $content .= ($content === '' ? '' : "\n") . $part['thinking'];
                    continue;
                }
                if (isset($part['type']) && $part['type'] === 'tool_use') {
                    $toolCalls[] = array(
                        'type'     => 'function',
                        'id'       => isset($part['id']) && is_string($part['id']) ? $part['id'] : null,
                        'function' => array(
                            'name'      => isset($part['name']) && is_string($part['name']) ? $part['name'] : null,
                            'arguments' => wp_json_encode($part['input'] ?? new \stdClass()) ?: '{}',
                        ),
                    );
                }
            }
        }

        $finishReason = 'stop';
        if (isset($data['stop_reason']) && is_string($data['stop_reason'])) {
            if (in_array($data['stop_reason'], array('max_tokens', 'model_context_window_exceeded'), true)) {
                $finishReason = 'length';
            } elseif ($data['stop_reason'] === 'tool_use') {
                $finishReason = 'tool_calls';
            } elseif ($data['stop_reason'] === 'refusal') {
                $finishReason = 'content_filter';
            }
        }

        $message = array(
            'role'    => isset($data['role']) && $data['role'] === 'user' ? 'user' : 'assistant',
            'content' => $content,
        );
        if (!empty($toolCalls)) {
            $message['tool_calls'] = $toolCalls;
        }

        $inputTokens = 0;
        $outputTokens = 0;
        if (isset($data['usage']) && is_array($data['usage'])) {
            $inputTokens = (int) ($data['usage']['input_tokens'] ?? 0)
                + (int) ($data['usage']['cache_creation_input_tokens'] ?? 0)
                + (int) ($data['usage']['cache_read_input_tokens'] ?? 0);
            $outputTokens = (int) ($data['usage']['output_tokens'] ?? 0);
        }

        $body = wp_json_encode(array(
            'id'      => isset($data['id']) && is_string($data['id']) ? $data['id'] : '',
            'choices' => array(array(
                'message'       => $message,
                'finish_reason' => $finishReason,
            )),
            'usage'   => array(
                'prompt_tokens'     => $inputTokens,
                'completion_tokens' => $outputTokens,
                'total_tokens'      => $inputTokens + $outputTokens,
            ),
        ));

        return new Response($response->getStatusCode(), $response->getHeaders(), is_string($body) ? $body : '{}');
    }
}