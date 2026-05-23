<?php
/**
 * 初一 AI 中转 image generation model.
 *
 * @package WordPress\ChuyiAiRelay\Models
 */

declare(strict_types=1);

namespace WordPress\ChuyiAiRelay\Models;

use WordPress\AiClient\Files\Enums\MediaOrientationEnum;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\OpenAiCompatibleImplementation\AbstractOpenAiCompatibleImageGenerationModel;
use WordPress\AiClient\Results\DTO\Candidate;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use WordPress\ChuyiAiRelay\Settings;

/**
 * Image generation through the relay's standard image endpoint.
 */
final class ChuyiRelayImageGenerationModel extends AbstractOpenAiCompatibleImageGenerationModel
{
    /**
     * {@inheritDoc}
     */
    public function generateImageResult(array $prompt): GenerativeAiResult
    {
        $httpTransporter = $this->getHttpTransporter();
        $params = $this->prepareGenerateImageParams($prompt);
        $params = $this->normalizeImageRequestParams($params);

        $expectedMimeType = isset($params['output_format']) && is_string($params['output_format'])
            ? 'image/' . $params['output_format']
            : 'image/png';

        $request = $this->createRequest(
            HttpMethodEnum::POST(),
            $this->getImageEndpointPath(),
            array('Content-Type' => 'application/json'),
            $this->prepareEndpointParams($params)
        );
        $request = $this->getRequestAuthentication()->authenticateRequest($request);
        $response = $httpTransporter->send($request);
        $this->throwIfNotSuccessful($response);

        return $this->parseInlineImageResponse($response, $expectedMimeType);
    }

    /**
     * Returns the selected endpoint path for this provider slot.
     */
    private function getImageEndpointPath(): string
    {
        $slotId = Settings::getSlotIdForProviderId($this->providerMetadata()->getId());
        return Settings::getImageEndpoint($slotId) === Settings::IMAGE_ENDPOINT_CHAT ? 'chat/completions' : 'images/generations';
    }

    /**
     * Adapts image-generation params to the selected relay endpoint.
     *
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    private function prepareEndpointParams(array $params): array
    {
        if ($this->getImageEndpointPath() !== 'chat/completions') {
            return $params;
        }

        $prompt = isset($params['prompt']) && is_string($params['prompt']) ? $params['prompt'] : '';
        return array(
            'model'      => isset($params['model']) && is_string($params['model']) ? $params['model'] : $this->metadata()->getId(),
            'messages'   => array(array('role' => 'user', 'content' => $prompt)),
            'modalities' => array('image'),
        );
    }

    /**
     * Aligns image request parameters with the official OpenAI provider.
     *
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    protected function normalizeImageRequestParams(array $params): array
    {
        $model = isset($params['model']) && is_string($params['model']) ? $params['model'] : $this->metadata()->getId();

        if ($this->isGptImageModel($model)) {
            unset($params['response_format']);
        } else {
            unset($params['output_format']);
        }

        return $params;
    }

    /**
     * {@inheritDoc}
     */
    protected function createRequest(HttpMethodEnum $method, string $path, array $headers = array(), $data = null): Request
    {
        return new Request(
            $method,
            Settings::urlForProviderId($this->providerMetadata()->getId(), $path),
            $headers,
            $data,
            $this->getRequestOptions()
        );
    }

    /**
     * Parses image responses after normalizing inline base64 data.
     */
    private function parseInlineImageResponse(Response $response, string $expectedMimeType): GenerativeAiResult
    {
        $responseData = $response->getData();
        if (!is_array($responseData)) {
            return $this->parseResponseToGenerativeAiResult($response, $expectedMimeType);
        }

        $responseData = $this->normalizeImageUrlResponseData($responseData);
        if ($this->getImageEndpointPath() === 'chat/completions') {
            $responseData = $this->normalizeChatImageResponseData($responseData);
        }
        if (empty($responseData['data']) || !is_array($responseData['data'])) {
            return $this->parseResponseToGenerativeAiResult($response, $expectedMimeType);
        }

        foreach ($responseData['data'] as $index => $item) {
            if (!is_array($item)) {
                continue;
            }

            if (isset($item['url']) && is_string($item['url']) && $item['url'] !== '' && empty($item['b64_json'])) {
                unset($responseData['data'][$index]['url']);
                continue;
            }

            if (isset($responseData['data'][$index]['b64_json']) && is_string($responseData['data'][$index]['b64_json'])) {
                $responseData['data'][$index]['b64_json'] = $this->normalizeBase64ImageData($responseData['data'][$index]['b64_json']);
            }
        }

        $body = wp_json_encode($responseData);
        if (!is_string($body)) {
            throw ResponseException::fromInvalidData($this->providerMetadata()->getName(), 'body', 'The response could not be encoded.');
        }

        return $this->parseResponseToGenerativeAiResult(
            new Response($response->getStatusCode(), $response->getHeaders(), $body),
            $expectedMimeType
        );
    }

    /**
     * Extracts base64 data URIs from chat-completion text responses.
     *
     * @param array<string,mixed> $responseData
     * @return array<string,mixed>
     */
    private function normalizeChatImageResponseData(array $responseData): array
    {
        if (!empty($responseData['data']) && is_array($responseData['data'])) {
            return $responseData;
        }

        $content = isset($responseData['choices'][0]['message']['content']) && is_string($responseData['choices'][0]['message']['content'])
            ? $responseData['choices'][0]['message']['content']
            : '';
        if ($content === '') {
            return $responseData;
        }

        $items = array();
        if (preg_match_all('#data:(image/[a-z0-9.+-]+);base64,([a-z0-9+/=\r\n]+)#i', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $items[] = array(
                    'b64_json'  => preg_replace('/\s+/', '', $match[2]),
                    'mime_type' => strtolower($match[1]),
                );
            }
        }

        if (!empty($items)) {
            $responseData['data'] = $items;
        }

        return $responseData;
    }

    /**
     * Converts non-standard image URL lists into OpenAI-compatible data items.
     *
     * @param array<string,mixed> $responseData
     * @return array<string,mixed>
     */
    private function normalizeImageUrlResponseData(array $responseData): array
    {
        if (!empty($responseData['data']) && is_array($responseData['data'])) {
            return $responseData;
        }

        if (empty($responseData['image_urls']) || !is_array($responseData['image_urls'])) {
            return $responseData;
        }

        $items = array();
        foreach ($responseData['image_urls'] as $url) {
            if (is_string($url) && $url !== '') {
                $items[] = array('url' => $url);
            }
        }

        if (!empty($items)) {
            $responseData['data'] = $items;
        }

        return $responseData;
    }

    /**
     * Ensures URL-only image choices are ignored because WordPress needs inline base64 data.
     *
     * @param array<string,mixed> $choiceData
     */
    protected function parseResponseChoiceToCandidate(array $choiceData, int $index, string $expectedMimeType = 'image/png'): Candidate
    {
        if (isset($choiceData['url']) && is_string($choiceData['url']) && $choiceData['url'] !== '' && empty($choiceData['b64_json'])) {
            unset($choiceData['url']);
        }

        return parent::parseResponseChoiceToCandidate($choiceData, $index, $expectedMimeType);
    }

    /**
     * Converts data URIs to the plain base64 shape expected by ai-client File.
     */
    private function normalizeBase64ImageData(string $value): string
    {
        $value = trim($value);
        if (preg_match('#^data:image/[a-z0-9.+-]+;base64,(.+)$#is', $value, $matches)) {
            return preg_replace('/\s+/', '', $matches[1]) ?: '';
        }

        return preg_replace('/\s+/', '', $value) ?: '';
    }

    /**
     * {@inheritDoc}
     */
    protected function prepareSizeParam(?MediaOrientationEnum $orientation, ?string $aspectRatio): string
    {
        $modelId = $this->metadata()->getId();

        if ($this->isGptImageModel($modelId)) {
            return $this->prepareGptImageSizeParam($orientation, $aspectRatio);
        }

        return $this->prepareDalleSizeParam($modelId, $orientation, $aspectRatio);
    }

    /**
     * {@inheritDoc}
     */
    protected function getResultId(array $responseData): string
    {
        if (isset($responseData['id']) && is_string($responseData['id'])) {
            return $responseData['id'];
        }

        return isset($responseData['created']) && is_int($responseData['created'])
            ? 'img-' . $responseData['created']
            : '';
    }

    /**
     * Checks whether the model uses the newer GPT image parameter set.
     */
    private function isGptImageModel(string $modelId): bool
    {
        return strpos($modelId, 'gpt-image-') === 0;
    }

    /**
     * Maps WordPress image sizing options to GPT image sizes.
     */
    private function prepareGptImageSizeParam(?MediaOrientationEnum $orientation, ?string $aspectRatio): string
    {
        if ($aspectRatio !== null) {
            $aspectRatioMap = array(
                '1:1' => '1024x1024',
                '3:2' => '1536x1024',
                '2:3' => '1024x1536',
            );
            if (isset($aspectRatioMap[$aspectRatio])) {
                return $aspectRatioMap[$aspectRatio];
            }
        }

        if ($orientation !== null) {
            if ($orientation->isLandscape()) {
                return '1536x1024';
            }
            if ($orientation->isPortrait()) {
                return '1024x1536';
            }
        }

        return '1024x1024';
    }

    /**
     * Maps WordPress image sizing options to DALL-E style sizes.
     */
    private function prepareDalleSizeParam(string $modelId, ?MediaOrientationEnum $orientation, ?string $aspectRatio): string
    {
        $isDalle3 = $modelId === 'dall-e-3';

        if ($aspectRatio !== null) {
            $aspectRatioMap = $isDalle3
                ? array(
                    '1:1' => '1024x1024',
                    '7:4' => '1792x1024',
                    '4:7' => '1024x1792',
                )
                : array(
                    '1:1' => '1024x1024',
                );

            if (isset($aspectRatioMap[$aspectRatio])) {
                return $aspectRatioMap[$aspectRatio];
            }
        }

        if ($orientation !== null && $isDalle3) {
            if ($orientation->isLandscape()) {
                return '1792x1024';
            }
            if ($orientation->isPortrait()) {
                return '1024x1792';
            }
        }

        return '1024x1024';
    }
}