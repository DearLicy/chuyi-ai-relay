<?php
/**
 * 初一 AI 中转 AI providers.
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
 * Shared implementation for one relay slot.
 */
abstract class AbstractChuyiRelayProvider extends AbstractApiProvider
{
    public const ID = 'chuyi-relay';
    public const SLOT_ID = 'default';

    /**
     * Returns the provider class for a relay slot and creates dynamic classes on demand.
     *
     * @return class-string<AbstractChuyiRelayProvider>
     */
    public static function providerClassForSlot(string $slotId): string
    {
        $slot = Settings::getSlot($slotId);
        $slotId = $slot['id'];
        if ($slotId === Settings::DEFAULT_SLOT_ID) {
            return ChuyiRelayProvider::class;
        }

        $classSuffix = preg_replace('/[^A-Za-z0-9_]/', '_', ucwords($slotId, '_'));
        $classSuffix = str_replace('_', '', (string) $classSuffix);
        if ($classSuffix === '' || ctype_digit($classSuffix[0])) {
            $classSuffix = 'K' . $classSuffix;
        }

        $className = __NAMESPACE__ . '\\ChuyiRelayProvider' . $classSuffix;
        if (!class_exists($className, false)) {
            eval('namespace ' . __NAMESPACE__ . '; final class ChuyiRelayProvider' . $classSuffix . ' extends AbstractChuyiRelayProvider { public const SLOT_ID = ' . var_export($slotId, true) . '; }');
        }

        /** @var class-string<AbstractChuyiRelayProvider> $className */
        return $className;
    }

    /**
     * Returns the slot ID for this provider class.
     */
    public static function slotId(): string
    {
        return static::SLOT_ID;
    }

    /**
     * Returns the provider ID for this slot.
     */
    public static function providerId(): string
    {
        return Settings::getProviderIdForSlot(static::slotId());
    }

    /**
     * {@inheritDoc}
     */
    protected static function baseUrl(): string
    {
        return Settings::urlForSlot(static::slotId());
    }

    /**
     * Returns the URL shown on the Connectors credentials screen.
     */
    private static function credentialsUrl(): ?string
    {
        $baseUrl = Settings::getBaseUrl(static::slotId());
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
                if (Settings::getMode(static::slotId()) === Settings::MODE_ANTHROPIC) {
                    throw new RuntimeException('Anthropic 接口模式不支持生图模型。');
                }

                return new ChuyiRelayImageGenerationModel($modelMetadata, $providerMetadata);
            }
        }

        foreach ($modelMetadata->getSupportedCapabilities() as $capability) {
            if ($capability->isTextGeneration()) {
                return new ChuyiRelayTextGenerationModel($modelMetadata, $providerMetadata);
            }
        }

        throw new RuntimeException('初一 AI 中转暂不支持该模型能力。');
    }

    /**
     * {@inheritDoc}
     */
    protected static function createProviderMetadata(): ProviderMetadata
    {
        $mode = Settings::getMode(static::slotId());
        $modeLabel = $mode === Settings::MODE_ANTHROPIC ? 'Anthropic' : 'OpenAI';

        $args = array(
            static::providerId(),
            Settings::getSlotName(static::slotId()),
            ProviderTypeEnum::server(),
            self::credentialsUrl(),
            RequestAuthenticationMethod::apiKey(),
        );

        if (version_compare(AiClient::VERSION, '1.2.0', '>=')) {
            $args[] = function_exists('__')
                ? sprintf(__('通过自定义 %s 协议中转接口提供 AI 能力。', 'chuyi-ai-relay'), $modeLabel)
                : sprintf('通过自定义 %s 协议中转接口提供 AI 能力。', $modeLabel);
        }

        return new ProviderMetadata(...$args);
    }

    /**
     * {@inheritDoc}
     */
    protected static function createProviderAvailability(): ProviderAvailabilityInterface
    {
        return new ChuyiRelayProviderAvailability(static::slotId());
    }

    /**
     * {@inheritDoc}
     */
    protected static function createModelMetadataDirectory(): ModelMetadataDirectoryInterface
    {
        return new ChuyiRelayModelMetadataDirectory(static::slotId());
    }
}

/**
 * Primary relay slot.
 */
final class ChuyiRelayProvider extends AbstractChuyiRelayProvider
{
    public const ID = 'chuyi-relay';
    public const SLOT_ID = 'default';
}







