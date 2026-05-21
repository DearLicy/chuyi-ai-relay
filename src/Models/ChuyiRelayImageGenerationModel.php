<?php
/**
 * 初一中转 image generation model.
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
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use WordPress\ChuyiAiRelay\Provider\ChuyiRelayProvider;

/**
 * Image generation through the relay's chat completions endpoint.
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
        $request = $this->createRequest(
            HttpMethodEnum::POST(),
            'chat/completions',
            array('Content-Type' => 'application/json'),
            $this->prepareChatCompletionsImageParams($params)
        );
        $request = $this->getRequestAuthentication()->authenticateRequest($request);
        $response = $httpTransporter->send($request);
        $this->throwIfNotSuccessful($response);

        $expectedMimeType = isset($params['output_format']) && is_string($params['output_format'])
            ? 'image/' . $params['output_format']
            : 'image/png';

        return $this->parseChatCompletionsImageResponse($response, $expectedMimeType);
    }

    /**
     * {@inheritDoc}
     */
    protected function createRequest(HttpMethodEnum $method, string $path, array $headers = array(), $data = null): Request
    {
        return new Request(
            $method,
            ChuyiRelayProvider::url($path),
            $headers,
            $data,
            $this->getRequestOptions()
        );
    }

    /**
     * Converts image generation params to a chat completions request.
     *
     * @param array<string, mixed> $params Image generation params.
     * @return array<string, mixed>
     */
    private function prepareChatCompletionsImageParams(array $params): array
    {
        $prompt = isset($params['prompt']) && is_string($params['prompt']) ? $params['prompt'] : '';
        $chatParams = array(
            'model'    => $this->metadata()->getId(),
            'messages' => array(
                array(
                    'role'    => 'user',
                    'content' => $prompt,
                ),
            ),
        );

        if (isset($params['n'])) {
            $chatParams['n'] = $params['n'];
        }
        if (isset($params['size'])) {
            $chatParams['size'] = $params['size'];
        }

        $chatParams['modalities'] = array('image');

        return $chatParams;
    }

    /**
     * Parses chat completions image responses into the image result shape expected by the base parser.
     */
    private function parseChatCompletionsImageResponse(Response $response, string $expectedMimeType): GenerativeAiResult
    {
        $responseData = $response->getData();
        if (!is_array($responseData) || empty($responseData['choices']) || !is_array($responseData['choices'])) {
            throw ResponseException::fromMissingData($this->providerMetadata()->getName(), 'choices');
        }

        $imageData = array();
        foreach ($responseData['choices'] as $index => $choiceData) {
            $extracted = $this->extractImageData($choiceData);
            if ($extracted === null) {
                throw ResponseException::fromInvalidData($this->providerMetadata()->getName(), "choices[{$index}]", 'The value must contain an image URL or base64 image data.');
            }
            $imageData[] = $extracted;
        }

        $usage = array();
        if (isset($responseData['usage']) && is_array($responseData['usage'])) {
            $usage = array(
                'input_tokens'  => isset($responseData['usage']['prompt_tokens']) ? (int) $responseData['usage']['prompt_tokens'] : 0,
                'output_tokens' => isset($responseData['usage']['completion_tokens']) ? (int) $responseData['usage']['completion_tokens'] : 0,
                'total_tokens'  => isset($responseData['usage']['total_tokens']) ? (int) $responseData['usage']['total_tokens'] : 0,
            );
        }

        $body = wp_json_encode(
            array(
                'id'    => isset($responseData['id']) && is_string($responseData['id']) ? $responseData['id'] : $this->getResultId($responseData),
                'data'  => $imageData,
                'usage' => $usage,
            )
        );

        if (!is_string($body)) {
            throw ResponseException::fromInvalidData($this->providerMetadata()->getName(), 'body', 'The response could not be encoded.');
        }

        return $this->parseResponseToGenerativeAiResult(
            new Response($response->getStatusCode(), $response->getHeaders(), $body),
            $expectedMimeType
        );
    }

    /**
     * Recursively extracts the first image URL or base64 payload from relay-specific response shapes.
     *
     * @param mixed $value Response fragment.
     * @return array{url?:string,b64_json?:string}|null
     */
    private function extractImageData($value): ?array
    {
        if (is_string($value)) {
            $value = trim($value);
            if ($value === '') {
                return null;
            }
            if (preg_match('#^https?://#i', $value)) {
                return array('url' => $value);
            }
            if (preg_match('#data:image/[a-z0-9.+-]+;base64,[a-z0-9+/=]+#i', $value, $matches)) {
                return array('b64_json' => $matches[0]);
            }
            if (preg_match('#https?://\S+#i', $value, $matches)) {
                return array('url' => rtrim($matches[0], '.,;:!?)"]\''));
            }
            if (preg_match('#^[a-z0-9+/]{120,}={0,2}$#i', $value)) {
                return array('b64_json' => $value);
            }
            return null;
        }

        if (!is_array($value)) {
            return null;
        }

        foreach (array('url', 'image_url') as $key) {
            if (isset($value[$key]) && is_string($value[$key])) {
                $imageData = $this->extractImageData($value[$key]);
                if ($imageData !== null) {
                    return $imageData;
                }
            }
        }

        foreach (array('b64_json', 'base64', 'b64') as $key) {
            if (isset($value[$key]) && is_string($value[$key])) {
                return array('b64_json' => $value[$key]);
            }
        }

        foreach ($value as $child) {
            $imageData = $this->extractImageData($child);
            if ($imageData !== null) {
                return $imageData;
            }
        }

        return null;
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