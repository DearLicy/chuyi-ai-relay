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
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;
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

        $path = $this->getImageEndpointPath();
        $request = $this->createRequest(
            HttpMethodEnum::POST(),
            $path,
            array('Content-Type' => 'application/json'),
            $this->prepareEndpointParams($params, $path)
        );
        $request = $this->getRequestAuthentication()->authenticateRequest($request);
        $response = $httpTransporter->send($request);
        $this->throwIfNotSuccessful($response);

        return $this->parseInlineImageResponse($response, $expectedMimeType);
    }

    /**
     * Returns endpoint paths for the current slot. Auto mode uses the standard image endpoint only.
     *
     * @return list<string>
     */
    private function getImageEndpointPaths(): array
    {
        $slotId = Settings::getSlotIdForProviderId($this->providerMetadata()->getId());
        $endpoint = Settings::getImageEndpoint($slotId);

        if ($endpoint === Settings::IMAGE_ENDPOINT_CHAT) {
            return array('chat/completions');
        }

        return array('images/generations');
    }

    /**
     * Returns the selected endpoint path for compatibility with inherited helpers.
     */
    private function getImageEndpointPath(): string
    {
        $paths = $this->getImageEndpointPaths();
        return $paths[0];
    }

    /**
     * Adapts image-generation params to the selected relay endpoint.
     *
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    private function prepareEndpointParams(array $params, string $path): array
    {
        if ($path !== 'chat/completions') {
            return $params;
        }

        $prompt = isset($params['prompt']) && is_string($params['prompt']) ? $params['prompt'] : '';
        return array(
            'model'    => isset($params['model']) && is_string($params['model']) ? $params['model'] : $this->metadata()->getId(),
            'messages' => array(array('role' => 'user', 'content' => $prompt)),
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

        $normalized = array(
            'model'  => $model,
            'prompt' => isset($params['prompt']) && is_string($params['prompt']) ? $params['prompt'] : '',
        );

        if (isset($params['n']) && is_numeric($params['n'])) {
            $normalized['n'] = max(1, (int) $params['n']);
        }

        if (isset($params['size']) && is_string($params['size']) && preg_match('/^\d+x\d+$/', $params['size'])) {
            $normalized['size'] = $params['size'];
        }

        return $normalized;
    }

    /**
     * {@inheritDoc}
     */
    protected function createRequest(HttpMethodEnum $method, string $path, array $headers = array(), $data = null): Request
    {
        $url = Settings::urlForProviderId($this->providerMetadata()->getId(), $path);
        $this->logRelayImageRequest($url, $path, $data);

        return new Request(
            $method,
            $url,
            $headers,
            $data,
            $this->getImageRequestOptions()
        );
    }

    /**
     * Records the final relay image request target without exposing secrets or prompt content.
     *
     * @param mixed $data
     */
    private function logRelayImageRequest(string $url, string $path, $data): void
    {
        $providerId = $this->providerMetadata()->getId();
        $slotId = Settings::getSlotIdForProviderId($providerId);
        $model = $this->metadata()->getId();
        $promptLength = 0;
        $size = '';

        if (is_array($data)) {
            if (isset($data['model']) && is_string($data['model']) && $data['model'] !== '') {
                $model = $data['model'];
            }

            if (isset($data['prompt']) && is_string($data['prompt'])) {
                $promptLength = strlen($data['prompt']);
            }

            if (isset($data['messages']) && is_array($data['messages'])) {
                foreach ($data['messages'] as $message) {
                    if (!is_array($message) || !isset($message['content']) || !is_string($message['content'])) {
                        continue;
                    }
                    $promptLength += strlen($message['content']);
                }
            }

            if (isset($data['size']) && is_string($data['size'])) {
                $size = $data['size'];
            }
        }

        error_log(sprintf(
            '[chuyi-ai-relay] image request target provider=%s slot=%s model=%s base_url=%s endpoint=%s path=%s timeout=%s prompt_length=%s size=%s',
            $providerId,
            $slotId,
            $model,
            Settings::getBaseUrl($slotId),
            $url,
            $path,
            (string) Settings::getImageGenerationTimeout(),
            (string) $promptLength,
            $size
        ));
    }

    /**
     * The official AI plugin currently hard-codes 90 seconds. This layer takes over only our relay image requests.
     */
    private function getImageRequestOptions(): RequestOptions
    {
        $options = $this->getRequestOptions();
        $options = $options instanceof RequestOptions ? clone $options : new RequestOptions();
        $options->setTimeout((float) Settings::getImageGenerationTimeout());

        return $options;
    }

    /**
     * Parses image responses after normalizing every supported provider shape to inline base64.
     */
    private function parseInlineImageResponse(Response $response, string $expectedMimeType): GenerativeAiResult
    {
        $responseData = $this->normalizeImageResponseData($response);
        if (empty($responseData['data']) || !is_array($responseData['data'])) {
            return $this->parseResponseToGenerativeAiResult($response, $expectedMimeType);
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
     * 官方 AI 插件只认 inline base64；这里临时接管 URL、Markdown 和 data URI 等非标准返回。
     *
     * @return array<string,mixed>
     */
    private function normalizeImageResponseData(Response $response): array
    {
        $responseData = $response->getData();
        $body = trim((string) $response->getBody());
        $items = array();

        if (is_array($responseData)) {
            $items = array_merge($items, $this->extractImageItemsFromArray($responseData));
        }

        if ($body !== '') {
            if ($items === array()) {
                $items = array_merge($items, $this->extractImageItemsFromText($body));
            } elseif ($this->responseLooksLikeMarkdown($body)) {
                $items = array_merge($items, $this->extractImageItemsFromText($body));
            }
        }

        $items = $this->dedupeImageItems($items);
        if (empty($items)) {
            return is_array($responseData) ? $responseData : array();
        }

        $normalized = is_array($responseData) ? $responseData : array();
        $normalized['data'] = $items;

        return $normalized;
    }

    /**
     * Checks whether a raw response looks like markdown/plain text instead of JSON.
     */
    private function responseLooksLikeMarkdown(string $body): bool
    {
        return strpos($body, '![') !== false
            || strpos($body, '<img') !== false
            || strpos($body, 'data:image/') !== false
            || preg_match('#^https?://#i', trim($body)) === 1
            || $this->looksLikeBase64ImageData($body);
    }

    /**
     * Extracts images from one OpenAI-compatible data item.
     *
     * @param array<mixed> $item
     * @return list<array<string,string>>
     */
    private function extractImageItemsFromArrayItem(array $item): array
    {
        $items = array();

        if (isset($item['b64_json']) && is_string($item['b64_json']) && trim($item['b64_json']) !== '') {
            $items[] = $this->base64ToItem(
                $item['b64_json'],
                isset($item['mime_type']) && is_string($item['mime_type']) ? $item['mime_type'] : 'image/png'
            );
        }

        if (isset($item['url']) && is_string($item['url']) && $item['url'] !== '') {
            $this->appendImageSourceItem($items, $item['url']);
        }

        if (isset($item['content']) && is_string($item['content']) && $item['content'] !== '') {
            $items = array_merge($items, $this->extractImageItemsFromText($item['content']));
        }

        if (isset($item['text']) && is_string($item['text']) && $item['text'] !== '') {
            $items = array_merge($items, $this->extractImageItemsFromText($item['text']));
        }

        return $items;
    }



    /**
     * Extracts Markdown image URLs, Markdown data URIs, HTML img src, bare URLs and bare base64 payloads.
     *
     * @return list<array<string,string>>
     */
    private function extractImageItemsFromText(string $text): array
    {
        $items = array();
        $text = html_entity_decode(trim($text), ENT_QUOTES);
        if ($text === '') {
            return $items;
        }

        $this->appendLooseImageSource($items, $text);
        $this->appendLooseBase64Payload($items, $text);

        if (preg_match_all('/!\[[^\]]*\]\(\s*(?:<([^>]+)>|([^\s)]+))(?:\s+["\'][^"\']*["\'])?\s*\)/i', $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $source = trim($match[1] !== '' ? $match[1] : $match[2]);
                $this->appendImageSourceItem($items, $source);
            }
        }

        if (preg_match_all('/<img[^>]+src=["\']([^"\']+)["\']/i', $text, $matches)) {
            foreach ($matches[1] as $source) {
                $this->appendImageSourceItem($items, trim($source));
            }
        }

        if (preg_match_all('#(?:^|[^A-Za-z0-9+/=])(data:image/[a-z0-9.+-]+;base64,[A-Za-z0-9+/=\r\n]+)(?:$|[^A-Za-z0-9+/=])#i', $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $items[] = $this->base64ToItem($match[1], 'image/png');
            }
        }

        if (preg_match_all('#https?://[^\s<>"]+#i', $text, $matches)) {
            foreach ($matches[0] as $source) {
                $this->appendImageSourceItem($items, trim($source));
            }
        }

        if (preg_match_all('/(?:^|[^A-Za-z0-9+/=])((?:[A-Za-z0-9+/=\r\n]{120,}))(?:$|[^A-Za-z0-9+/=])/i', $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $candidate = preg_replace('/\s+/', '', $match[1]) ?: '';
                if ($candidate === '' || !$this->looksLikeBase64ImageData($candidate)) {
                    continue;
                }

                $items[] = $this->base64ToItem($candidate, 'image/png');
            }
        }

        return array_values(array_filter($items));
    }

    /**
     * Appends a direct image source if it can be converted safely.
     *
     * @param list<array<string,string>> $items
     */
    private function appendImageSourceItem(array &$items, string $source): void
    {
        $source = trim($source);
        if ($source === '') {
            return;
        }

        try {
            $items[] = $this->imageSourceToItem($source);
        } catch (ResponseException $exception) {
            return;
        }
    }

    /**
     * Appends a direct or wrapped base64 payload when the whole text is payload-like.
     *
     * @param list<array<string,string>> $items
     */
    private function appendLooseBase64Payload(array &$items, string $text): void
    {
        $candidate = $this->extractLooseBase64Payload($text);
        if ($candidate === '') {
            return;
        }

        $items[] = $this->base64ToItem($candidate, 'image/png');
    }

    /**
     * Appends a direct URL when the whole text is a source URL.
     *
     * @param list<array<string,string>> $items
     */
    private function appendLooseImageSource(array &$items, string $text): void
    {
        $source = $this->extractLooseImageSource($text);
        if ($source === '') {
            return;
        }

        $items[] = $this->imageSourceToItem($source);
    }

    /**
     * Detects a plain source URL or data URI if the payload is raw text.
     */
    private function extractLooseImageSource(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        if (preg_match('#^data:image/[a-z0-9.+-]+;base64,[A-Za-z0-9+/=\r\n]+$#i', $text)) {
            return $text;
        }

        if (preg_match('#^https?://#i', $text)) {
            return $text;
        }

        return '';
    }

    /**
     * Detects a plain base64 payload if the payload is raw text.
     */
    private function extractLooseBase64Payload(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        if (preg_match('#^data:image/[a-z0-9.+-]+;base64,([A-Za-z0-9+/=\r\n]+)$#i', $text, $matches)) {
            return preg_replace('/\s+/', '', $matches[1]) ?: '';
        }

        if ($this->looksLikeBase64ImageData($text)) {
            return preg_replace('/\s+/', '', $text) ?: '';
        }

        return '';
    }

    /**
     * Checks whether a string looks like image base64.
     */
    private function looksLikeBase64ImageData(string $value): bool
    {
        $value = preg_replace('/\s+/', '', trim($value)) ?: '';
        if ($value === '' || strlen($value) < 120 || strlen($value) % 4 !== 0) {
            return false;
        }

        if (!preg_match('#^[A-Za-z0-9+/=]+$#', $value)) {
            return false;
        }

        return base64_decode($value, true) !== false;
    }

    /**
     * Extracts image items from common JSON response envelopes.
     *
     * @param array<string,mixed> $responseData
     * @return list<array<string,string>>
     */
    private function extractImageItemsFromArray(array $responseData): array
    {
        $items = array();

        if (isset($responseData['data']) && is_array($responseData['data'])) {
            foreach ($responseData['data'] as $item) {
                if (is_array($item)) {
                    $items = array_merge($items, $this->extractImageItemsFromArrayItem($item));
                } elseif (is_string($item)) {
                    $items = array_merge($items, $this->extractImageItemsFromText($item));
                }
            }
        }

        foreach (array('image', 'url', 'output', 'result', 'content', 'text') as $key) {
            if (!isset($responseData[$key])) {
                continue;
            }

            if (is_string($responseData[$key])) {
                $items = array_merge($items, $this->extractImageItemsFromText($responseData[$key]));
            } elseif (is_array($responseData[$key])) {
                $items = array_merge($items, $this->extractImageItemsFromArray($responseData[$key]));
            }
        }

        if (isset($responseData['choices']) && is_array($responseData['choices'])) {
            foreach ($responseData['choices'] as $choice) {
                if (is_array($choice)) {
                    $items = array_merge($items, $this->extractImageItemsFromArray($choice));
                }
            }
        }

        return $items;
    }

    /**
     * Converts one supported image source to the inline base64 shape expected by AI Client.
     *
     * @return array<string,string>
     */
    private function imageSourceToItem(string $source): array
    {
        $source = trim($source, " \t\n\r\0\x0B\"'`.,;)");
        if ($source === '') {
            throw ResponseException::fromInvalidData($this->providerMetadata()->getName(), 'source', 'Image source is empty.');
        }

        if (preg_match('#^data:(image/[a-z0-9.+-]+);base64,(.+)$#is', $source, $matches)) {
            return $this->base64ToItem($matches[2], $matches[1]);
        }

        if ($this->looksLikeBase64ImageData($source)) {
            return $this->base64ToItem($source, 'image/png');
        }

        if ($this->isAllowedImageUrl($source)) {
            return $this->imageUrlToItem($source);
        }

        throw ResponseException::fromInvalidData($this->providerMetadata()->getName(), 'source', 'Unsupported image source.');
    }

    /**
     * Downloads an image URL and converts it to inline base64.
     *
     * @return array<string,string>
     */
    private function imageUrlToItem(string $url): array
    {
        if (!$this->isAllowedImageUrl($url)) {
            throw ResponseException::fromInvalidData($this->providerMetadata()->getName(), 'url', 'Image URL is not allowed.');
        }

        $response = $this->downloadImageUrl($url);
        $body = (string) wp_remote_retrieve_body($response);
        if ($body === '') {
            throw ResponseException::fromInvalidData($this->providerMetadata()->getName(), 'url', 'Downloaded image body is empty.');
        }

        $mimeType = $this->detectImageMimeType($body, (string) wp_remote_retrieve_header($response, 'content-type'));

        return array(
            'b64_json'  => base64_encode($body),
            'mime_type' => $mimeType,
        );
    }

    /**
     * Normalizes base64 image data to an OpenAI-compatible image item.
     *
     * @return array<string,string>
     */
    private function base64ToItem(string $value, string $mimeType): array
    {
        $dataUriMimeType = $this->getMimeTypeFromDataUri($value);
        $base64 = $this->normalizeBase64ImageData($value);
        if ($base64 === '' || base64_decode($base64, true) === false) {
            throw ResponseException::fromInvalidData($this->providerMetadata()->getName(), 'b64_json', 'Image base64 data is invalid.');
        }

        return array(
            'b64_json'  => $base64,
            'mime_type' => $this->normalizeImageMimeType($dataUriMimeType !== '' ? $dataUriMimeType : $mimeType),
        );
    }



    /**
     * Downloads an image URL, falling back to i0.wp.com only when the server cannot fetch the origin directly.
     *
     * @return array|\WP_Error
     */
    private function downloadImageUrl(string $url)
    {
        $response = wp_remote_get($url, array(
            'timeout'     => Settings::getImageGenerationTimeout(),
            'redirection' => 3,
        ));

        if (!$this->isSuccessfulImageDownload($response)) {
            $proxyUrl = $this->buildI0ProxyUrl($url);
            if ($proxyUrl !== '') {
                $response = wp_remote_get($proxyUrl, array(
                    'timeout'     => Settings::getImageGenerationTimeout(),
                    'redirection' => 3,
                ));
            }
        }

        if (is_wp_error($response)) {
            throw ResponseException::fromInvalidData($this->providerMetadata()->getName(), 'url', $response->get_error_message());
        }

        $statusCode = (int) wp_remote_retrieve_response_code($response);
        if ($statusCode < 200 || $statusCode >= 300) {
            throw ResponseException::fromInvalidData($this->providerMetadata()->getName(), 'url', 'Image download failed with HTTP ' . $statusCode . '.');
        }

        return $response;
    }

    /**
     * Checks a WordPress HTTP response before deciding whether proxy download is needed.
     *
     * @param mixed $response
     */
    private function isSuccessfulImageDownload($response): bool
    {
        if (is_wp_error($response)) {
            return false;
        }

        $statusCode = (int) wp_remote_retrieve_response_code($response);
        if ($statusCode < 200 || $statusCode >= 300 || (string) wp_remote_retrieve_body($response) === '') {
            return false;
        }

        $contentType = strtolower(trim(strtok((string) wp_remote_retrieve_header($response, 'content-type'), ';') ?: ''));
        return $contentType === '' || strpos($contentType, 'image/') === 0;
    }

    /**
     * Builds a Jetpack image proxy URL for servers that cannot access the generated image host directly.
     */
    private function buildI0ProxyUrl(string $url): string
    {
        $parts = wp_parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            return '';
        }

        $host = $parts['host'];
        $path = isset($parts['path']) && is_string($parts['path']) ? $parts['path'] : '/';
        $queryParts = array();
        if (isset($parts['query']) && is_string($parts['query']) && $parts['query'] !== '') {
            parse_str($parts['query'], $queryParts);
        }
        $queryParts['ssl'] = '1';
        $query = '?' . http_build_query($queryParts, '', '&');

        return 'https://i0.wp.com/' . $host . $path . $query;
    }

    /**
     * Checks whether a URL is safe enough for provider image download.
     */
    private function isAllowedImageUrl(string $url): bool
    {
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            return false;
        }

        $parts = wp_parse_url($url);
        return is_array($parts) && !empty($parts['scheme']) && !empty($parts['host']);
    }

    /**
     * Detects the downloaded image MIME type from response bytes first, then response header.
     */
    private function detectImageMimeType(string $body, string $headerMimeType): string
    {
        $mimeType = '';
        if (function_exists('getimagesizefromstring')) {
            $imageInfo = @getimagesizefromstring($body); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            if (is_array($imageInfo) && !empty($imageInfo['mime']) && is_string($imageInfo['mime'])) {
                $mimeType = $imageInfo['mime'];
            }
        }
        if ($mimeType === '' && function_exists('finfo_buffer')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $detected = finfo_buffer($finfo, $body);
                finfo_close($finfo);
                $mimeType = is_string($detected) ? $detected : '';
            }
        }
        if ($mimeType === '') {
            $mimeType = trim(strtolower(strtok($headerMimeType, ';') ?: ''));
        }

        return $this->normalizeImageMimeType($mimeType);
    }

    /**
     * Ensures response choice fallback still turns URL-only choices into inline base64.
     *
     * @param array<string,mixed> $choiceData
     */
    protected function parseResponseChoiceToCandidate(array $choiceData, int $index, string $expectedMimeType = 'image/png'): Candidate
    {
        if (isset($choiceData['url']) && is_string($choiceData['url']) && $choiceData['url'] !== '' && empty($choiceData['b64_json'])) {
            $choiceData = array_merge($choiceData, $this->imageUrlToItem($choiceData['url']));
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
     * Extracts MIME type from a data URI.
     */
    private function getMimeTypeFromDataUri(string $value): string
    {
        return preg_match('#^data:(image/[a-z0-9.+-]+);base64,#i', trim($value), $matches)
            ? strtolower($matches[1])
            : '';
    }

    /**
     * Keeps image MIME types predictable for downstream File parsing.
     */
    private function normalizeImageMimeType(string $mimeType): string
    {
        $mimeType = strtolower(trim(strtok($mimeType, ';') ?: $mimeType));
        return preg_match('#^image/[a-z0-9.+-]+$#', $mimeType) ? $mimeType : 'image/png';
    }

    /**
     * Removes duplicate base64 items after multiple extraction passes.
     *
     * @param list<array<string,string>> $items
     * @return list<array<string,string>>
     */
    private function dedupeImageItems(array $items): array
    {
        $deduped = array();
        $seen = array();
        foreach ($items as $item) {
            if (empty($item['b64_json']) || !is_string($item['b64_json'])) {
                continue;
            }
            $key = md5($item['b64_json']);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $deduped[] = array(
                'b64_json'  => $item['b64_json'],
                'mime_type' => isset($item['mime_type']) ? $this->normalizeImageMimeType($item['mime_type']) : 'image/png',
            );
        }

        return $deduped;
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