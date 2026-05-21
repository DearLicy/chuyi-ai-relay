<?php
/**
 * 初一中转 model metadata directory.
 *
 * @package WordPress\ChuyiAiRelay\Metadata
 */

declare(strict_types=1);

namespace WordPress\ChuyiAiRelay\Metadata;

use WordPress\AiClient\AiClient;
use WordPress\AiClient\Files\Enums\FileTypeEnum;
use WordPress\AiClient\Files\Enums\MediaOrientationEnum;
use WordPress\AiClient\Messages\Enums\ModalityEnum;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\DTO\SupportedOption;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Providers\Models\Enums\OptionEnum;
use WordPress\AiClient\Providers\OpenAiCompatibleImplementation\AbstractOpenAiCompatibleModelMetadataDirectory;
use WordPress\ChuyiAiRelay\Provider\ChuyiRelayProvider;
use WordPress\ChuyiAiRelay\Settings;

/**
 * Converts OpenAI-compatible /models responses into WordPress AI model metadata.
 */
final class ChuyiRelayModelMetadataDirectory extends AbstractOpenAiCompatibleModelMetadataDirectory
{
    /**
     * Uses locally saved model IDs first; falls back to /models only when no local list exists.
     *
     * @return array<string, ModelMetadata>
     */
    protected function sendListModelsRequest(): array
    {
        $savedModels = Settings::getModels();
        if (!empty($savedModels)) {
            $models = array();
            foreach ($savedModels as $model) {
                $models[$model['id']] = new ModelMetadata(
                    $model['id'],
                    $model['name'],
                    $this->getCapabilitiesForModel($model['id']),
                    $this->getOptionsForModel($model['id'])
                );
            }
            uasort($models, array($this, 'sortModels'));
            return $models;
        }

        return parent::sendListModelsRequest();
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
            $data
        );
    }

    /**
     * {@inheritDoc}
     */
    protected function parseResponseToModelMetadataList(Response $response): array
    {
        $responseData = $response->getData();
        if (!is_array($responseData) || empty($responseData['data']) || !is_array($responseData['data'])) {
            throw ResponseException::fromMissingData('初一中转', 'data');
        }

        $models = array();
        foreach ($responseData['data'] as $modelData) {
            if (!is_array($modelData) || empty($modelData['id']) || !is_string($modelData['id'])) {
                continue;
            }

            $modelId = sanitize_text_field($modelData['id']);
            if ($modelId === '') {
                continue;
            }

            $modelName = isset($modelData['name']) && is_string($modelData['name']) && $modelData['name'] !== ''
                ? sanitize_text_field($modelData['name'])
                : $modelId;

            $models[] = new ModelMetadata(
                $modelId,
                $modelName,
                $this->getCapabilitiesForModel($modelId),
                $this->getOptionsForModel($modelId)
            );
        }

        if (empty($models)) {
            throw ResponseException::fromMissingData('初一中转', 'data[].id');
        }

        usort($models, array($this, 'sortModels'));
        return $models;
    }

    /**
     * Keeps cache entries separate when the relay endpoint changes.
     */
    protected function getBaseCacheKey(): string
    {
        return 'ai_client_' . AiClient::VERSION . '_' . md5(static::class . '|' . Settings::getBaseUrl());
    }

    /**
     * Returns model capabilities from saved manual config or inferred defaults.
     *
     * @return list<CapabilityEnum>
     */
    private function getCapabilitiesForModel(string $modelId): array
    {
        $capabilityMap = Settings::getModelCapabilities();
        $capabilities = $capabilityMap[$modelId] ?? Settings::inferCapabilities($modelId);

        if (in_array('image_generation', $capabilities, true)) {
            return array(CapabilityEnum::imageGeneration());
        }

        if (in_array('text_generation', $capabilities, true) || in_array('vision', $capabilities, true)) {
            return array(
                CapabilityEnum::textGeneration(),
                CapabilityEnum::chatHistory(),
            );
        }

        return array();
    }

    /**
     * Returns supported configuration options for the requested model.
     *
     * @return list<SupportedOption>
     */
    private function getOptionsForModel(string $modelId): array
    {
        $capabilityMap = Settings::getModelCapabilities();
        $capabilities = $capabilityMap[$modelId] ?? Settings::inferCapabilities($modelId);

        if (in_array('image_generation', $capabilities, true)) {
            return $this->getImageOptions($modelId);
        }

        if (in_array('text_generation', $capabilities, true) || in_array('vision', $capabilities, true)) {
            return $this->getTextOptions(in_array('vision', $capabilities, true));
        }

        return array();
    }

    /**
     * Returns text generation options.
     *
     * @return list<SupportedOption>
     */
    private function getTextOptions(bool $supportsVision): array
    {
        $inputModalities = $supportsVision
            ? array(
                array(ModalityEnum::text()),
                array(ModalityEnum::text(), ModalityEnum::image()),
            )
            : array(
                array(ModalityEnum::text()),
            );

        return array(
            new SupportedOption(OptionEnum::systemInstruction()),
            new SupportedOption(OptionEnum::candidateCount()),
            new SupportedOption(OptionEnum::maxTokens()),
            new SupportedOption(OptionEnum::temperature()),
            new SupportedOption(OptionEnum::topP()),
            new SupportedOption(OptionEnum::stopSequences()),
            new SupportedOption(OptionEnum::presencePenalty()),
            new SupportedOption(OptionEnum::frequencyPenalty()),
            new SupportedOption(OptionEnum::logprobs()),
            new SupportedOption(OptionEnum::topLogprobs()),
            new SupportedOption(OptionEnum::outputMimeType(), array('text/plain', 'application/json')),
            new SupportedOption(OptionEnum::outputSchema()),
            new SupportedOption(OptionEnum::functionDeclarations()),
            new SupportedOption(OptionEnum::inputModalities(), $inputModalities),
            new SupportedOption(OptionEnum::outputModalities(), array(array(ModalityEnum::text()))),
            new SupportedOption(OptionEnum::customOptions()),
        );
    }

    /**
     * Returns image generation options.
     *
     * @return list<SupportedOption>
     */
    private function getImageOptions(string $modelId): array
    {
        $isGptImage = strpos($modelId, 'gpt-image-') === 0;

        return array(
            new SupportedOption(OptionEnum::inputModalities(), array(array(ModalityEnum::text()))),
            new SupportedOption(OptionEnum::outputModalities(), array(array(ModalityEnum::image()))),
            new SupportedOption(OptionEnum::candidateCount()),
            new SupportedOption(
                OptionEnum::outputMimeType(),
                $isGptImage ? array('image/png', 'image/jpeg', 'image/webp') : array('image/png')
            ),
            new SupportedOption(
                OptionEnum::outputFileType(),
                $isGptImage ? array(FileTypeEnum::inline()) : array(FileTypeEnum::inline(), FileTypeEnum::remote())
            ),
            new SupportedOption(OptionEnum::outputMediaOrientation(), array(
                MediaOrientationEnum::square(),
                MediaOrientationEnum::landscape(),
                MediaOrientationEnum::portrait(),
            )),
            new SupportedOption(
                OptionEnum::outputMediaAspectRatio(),
                $isGptImage ? array('1:1', '3:2', '2:3') : array('1:1', '7:4', '4:7')
            ),
            new SupportedOption(OptionEnum::customOptions()),
        );
    }

    /**
     * Sorts models in a stable, human-readable order.
     */
    private function sortModels(ModelMetadata $a, ModelMetadata $b): int
    {
        return strnatcasecmp($a->getId(), $b->getId());
    }
}