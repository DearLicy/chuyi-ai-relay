<?php
/**
 * 初一中转 AI provider.
 *
 * @package WordPress\ChuyiAiRelay\Provider
 */

declare(strict_types=1);

namespace WordPress\ChuyiAiRelay\Provider;

use WordPress\AiClient\AiClient;
use WordPress\AiClient\Common\Exception\RuntimeException;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiProvider;
use WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface;
use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;
use WordPress\AiClient\Providers\Http\Enums\RequestAuthenticationMethod;
use WordPress\AiClient\Providers\Models\Contracts\ModelInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\ChuyiAiRelay\Availability\ChuyiRelayProviderAvailability;
use WordPress\ChuyiAiRelay\Metadata\ChuyiRelayModelMetadataDirectory;
use WordPress\ChuyiAiRelay\Models\ChuyiRelayImageGenerationModel;
use WordPress\ChuyiAiRelay\Models\ChuyiRelayTextGenerationModel;
use WordPress\ChuyiAiRelay\Settings;

/**
 * Registers 初一中转 as an OpenAI-compatible provider.
 */
final class ChuyiRelayProvider extends AbstractApiProvider
{
    public const ID = 'chuyi-relay';

    /**
     * {@inheritDoc}
     */
    protected static function baseUrl(): string
    {
        $baseUrl = Settings::getBaseUrl();
        return $baseUrl !== '' ? $baseUrl : 'https://example.invalid/v1';
    }

    /**
     * Returns the URL shown on the Connectors credentials screen.
     */
    private static function credentialsUrl(): ?string
    {
        $baseUrl = Settings::getBaseUrl();
        if ($baseUrl === '') {
            return null;
        }

        return preg_replace('#/v\d+(?:\.\d+)?$#i', '', $baseUrl) ?: $baseUrl;
    }

    /**
     * {@inheritDoc}
     */
    protected static function createModel(ModelMetadata $modelMetadata, ProviderMetadata $providerMetadata): ModelInterface
    {
        foreach ($modelMetadata->getSupportedCapabilities() as $capability) {
            if ($capability->isImageGeneration()) {
                return new ChuyiRelayImageGenerationModel($modelMetadata, $providerMetadata);
            }
        }

        foreach ($modelMetadata->getSupportedCapabilities() as $capability) {
            if ($capability->isTextGeneration()) {
                return new ChuyiRelayTextGenerationModel($modelMetadata, $providerMetadata);
            }
        }

        throw new RuntimeException('初一中转暂不支持该模型能力。');
    }

    /**
     * {@inheritDoc}
     */
    protected static function createProviderMetadata(): ProviderMetadata
    {
        $credentialsUrl = self::credentialsUrl();

        $args = array(
            self::ID,
            '初一中转',
            ProviderTypeEnum::server(),
            $credentialsUrl,
            RequestAuthenticationMethod::apiKey(),
        );

        if (version_compare(AiClient::VERSION, '1.2.0', '>=')) {
            $args[] = function_exists('__')
                ? __('通过自定义 OpenAI 协议接口提供文本、视觉和生图能力。', 'chuyi-ai-relay')
                : '通过自定义 OpenAI 协议接口提供文本、视觉和生图能力。';
        }

        if (version_compare(AiClient::VERSION, '1.3.0', '>=')) {
            $args[] = \CHUYI_AI_RELAY_DIR . 'assets/images/chuyi-relay.svg';
        }

        return new ProviderMetadata(...$args);
    }

    /**
     * {@inheritDoc}
     */
    protected static function createProviderAvailability(): ProviderAvailabilityInterface
    {
        return new ChuyiRelayProviderAvailability();
    }

    /**
     * {@inheritDoc}
     */
    protected static function createModelMetadataDirectory(): ModelMetadataDirectoryInterface
    {
        return new ChuyiRelayModelMetadataDirectory();
    }
}