<?php
/**
 * WordPress Abilities exposed by 初一 AI 中转.
 *
 * @package WordPress\ChuyiAiRelay\Abilities
 */

declare(strict_types=1);

namespace WordPress\ChuyiAiRelay\Abilities;

use WordPress\ChuyiAiRelay\Abilities\Image\GenerateImage;
use WordPress\ChuyiAiRelay\Abilities\Image\GenerateImagePrompt;
use WordPress\ChuyiAiRelay\Abilities\Image\ImportBase64Image;
use WordPress\ChuyiAiRelay\Prompts\DefaultPrompts;
use WordPress\ChuyiAiRelay\Prompts\PromptOverrides;
use WordPress\ChuyiAiRelay\Settings;

if (!defined('ABSPATH')) {
    return;
}

final class Registry
{
    public const CATEGORY = 'chuyi-ai-relay';

    /**
     * Registers ability hooks when the WordPress Abilities API is available.
     */
    public static function init(): void
    {
        add_action('wp_abilities_api_categories_init', array(__CLASS__, 'registerCategory'));
        add_action('wp_abilities_api_init', array(__CLASS__, 'registerAbilities'));
        add_action('wp_abilities_api_init', array(__CLASS__, 'replaceImageAbilities'), 100);
        add_action('plugins_loaded', array(__CLASS__, 'registerImageModelFilters'), 20);
    }

    public static function registerCategory(): void
    {
        if (!function_exists('wp_register_ability_category')) {
            return;
        }

        wp_register_ability_category(
            self::CATEGORY,
            array(
                'label'       => __('初一 AI 中转', 'chuyi-ai-relay'),
                'description' => __('中转站、模型和 AI 提示词增强能力。', 'chuyi-ai-relay'),
            )
        );
    }

    public static function registerAbilities(): void
    {
        if (!function_exists('wp_register_ability')) {
            return;
        }

        self::registerListPromptOverridesAbility();
        self::registerSavePromptOverrideAbility();
        self::registerDeletePromptOverrideAbility();
        self::registerSetPromptOverrideEnabledAbility();
    }

    public static function replaceImageAbilities(): void
    {
        if (!function_exists('wp_register_ability')) {
            return;
        }

        foreach (array('ai/image-generation', 'ai/image-import', 'ai/image-prompt-generation') as $abilityName) {
            if (function_exists('wp_has_ability') && wp_has_ability($abilityName) && function_exists('wp_unregister_ability')) {
                wp_unregister_ability($abilityName);
            }
        }

        wp_register_ability(
            'ai/image-generation',
            array(
                'label'         => __('Image Generation and Editing', 'ai'),
                'description'   => __('Generate and edit images using AI. Requires an AI connector that includes support for image generation models.', 'ai'),
                'ability_class' => GenerateImage::class,
            )
        );

        wp_register_ability(
            'ai/image-import',
            array(
                'label'         => __('Base64 Image Import', 'ai'),
                'description'   => __('Imports a base64 encoded image into the media library', 'ai'),
                'ability_class' => ImportBase64Image::class,
            )
        );

        wp_register_ability(
            'ai/image-prompt-generation',
            array(
                'label'         => __('Image Prompt Generation', 'ai'),
                'description'   => __('Generates a prompt from post content that can be used to generate an image', 'ai'),
                'ability_class' => GenerateImagePrompt::class,
            )
        );
    }

    public static function registerImageModelFilters(): void
    {
        add_filter('wpai_preferred_image_models', array(__CLASS__, 'filterPreferredImageModels'), 5);
        add_filter('wpai_is_image_generation_connector_configured', array(__CLASS__, 'forceImageGenerationConnectorConfigured'), 5, 2);
    }

    /**
     * @param bool $configured
     * @param array<string, mixed> $connectorData
     */
    public static function forceImageGenerationConnectorConfigured($configured, array $connectorData): bool
    {
        if ($configured) {
            return true;
        }

        $connectorSlug = isset($connectorData['slug']) && is_string($connectorData['slug']) ? sanitize_key($connectorData['slug']) : '';
        if ($connectorSlug === '' || strpos($connectorSlug, 'chuyi-relay') !== 0) {
            return (bool) $configured;
        }

        $slotId = Settings::getSlotIdForProviderId($connectorSlug);
        return Settings::getMode($slotId) === Settings::MODE_OPENAI && !empty(Settings::getModels($slotId));
    }

    /**
     * @param array<int, array{string, string}> $preferredModels
     * @return array<int, array{string, string}>
     */
    public static function filterPreferredImageModels(array $preferredModels): array
    {
        $configured = get_option('wpai_feature_image-generation_field_developer', array());
        if (is_array($configured) && !empty($configured['provider']) && !empty($configured['model'])) {
            return $preferredModels;
        }

        return self::appendPreferredImageModels($preferredModels);
    }

    /**
     * @param array<int, array{string, string}> $preferredModels
     * @return array<int, array{string, string}>
     */
    private static function appendPreferredImageModels(array $preferredModels): array
    {
        $preferred = array();
        $seen = array();

        foreach ($preferredModels as $item) {
            if (!is_array($item) || count($item) < 2) {
                continue;
            }

            $providerId = is_string($item[0]) ? $item[0] : '';
            $modelId = is_string($item[1]) ? $item[1] : '';
            if ($providerId === '' || $modelId === '') {
                continue;
            }

            $key = $providerId . '|' . $modelId;
            if (isset($seen[$key])) {
                continue;
            }

            $preferred[] = array($providerId, $modelId);
            $seen[$key] = true;
        }

        foreach (Settings::getRegisterableSlots() as $slotId => $slot) {
            if (Settings::getMode($slotId) !== Settings::MODE_OPENAI) {
                continue;
            }

            $providerId = Settings::getProviderIdForSlot($slotId);
            $models = isset($slot['models']) && is_array($slot['models']) ? $slot['models'] : array();
            foreach ($models as $model) {
                if (!is_array($model) || empty($model['id']) || !is_string($model['id'])) {
                    continue;
                }

                $modelCapabilities = isset($model['capabilities']) && is_array($model['capabilities'])
                    ? Settings::sanitizeCapabilities($model['capabilities'])
                    : Settings::inferCapabilities($model['id']);
                if (!in_array('image_generation', $modelCapabilities, true)) {
                    continue;
                }

                $key = $providerId . '|' . $model['id'];
                if (isset($seen[$key])) {
                    continue;
                }

                $preferred[] = array($providerId, $model['id']);
                $seen[$key] = true;
            }
        }

        return $preferred;
    }

    private static function registerListPromptOverridesAbility(): void
    {
        wp_register_ability(
            'chuyi-ai-relay/list-prompt-overrides',
            array(
                'label'               => __('列出 AI 提示词覆盖', 'chuyi-ai-relay'),
                'description'         => __('列出 WordPress AI 能力的默认系统提示词和已保存覆盖。', 'chuyi-ai-relay'),
                'category'            => self::CATEGORY,
                'input_schema'        => array(
                    'type'       => 'object',
                    'properties' => array(),
                ),
                'output_schema'       => self::promptOverrideListSchema(),
                'execute_callback'    => static function (): array {
                    return array(
                        'overrides' => DefaultPrompts::managed(),
                    );
                },
                'permission_callback' => array(__CLASS__, 'manageOptionsPermission'),
                'meta'                => self::abilityMeta(),
            )
        );
    }

    private static function registerSavePromptOverrideAbility(): void
    {
        wp_register_ability(
            'chuyi-ai-relay/save-prompt-override',
            array(
                'label'               => __('保存 AI 提示词覆盖', 'chuyi-ai-relay'),
                'description'         => __('为 WordPress AI 能力创建或更新系统提示词覆盖。', 'chuyi-ai-relay'),
                'category'            => self::CATEGORY,
                'input_schema'        => array(
                    'type'       => 'object',
                    'properties' => array(
                        'ability'     => array(
                            'type'        => 'string',
                            'description' => __('目标能力别名，例如 ai/title-generation。', 'chuyi-ai-relay'),
                        ),
                        'instruction' => array(
                            'type'        => 'string',
                            'description' => __('替换或追加的系统提示词。', 'chuyi-ai-relay'),
                        ),
                        'mode'        => array(
                            'type'        => 'string',
                            'enum'        => array(PromptOverrides::MODE_REPLACE, PromptOverrides::MODE_APPEND),
                            'default'     => PromptOverrides::MODE_REPLACE,
                            'description' => __('使用 replace 覆盖原始提示词，或使用 append 追加额外要求。', 'chuyi-ai-relay'),
                        ),
                        'enabled'     => array(
                            'type'        => 'boolean',
                            'default'     => true,
                            'description' => __('此提示词覆盖是否启用。', 'chuyi-ai-relay'),
                        ),
                    ),
                    'required'   => array('ability', 'instruction'),
                ),
                'output_schema'       => self::promptOverrideSchema(),
                'execute_callback'    => static function (array $input) {
                    return PromptOverrides::save((string) ($input['ability'] ?? ''), $input);
                },
                'permission_callback' => array(__CLASS__, 'manageOptionsPermission'),
                'meta'                => self::abilityMeta(),
            )
        );
    }

    private static function registerDeletePromptOverrideAbility(): void
    {
        wp_register_ability(
            'chuyi-ai-relay/delete-prompt-override',
            array(
                'label'               => __('删除 AI 提示词覆盖', 'chuyi-ai-relay'),
                'description'         => __('删除已保存的系统提示词覆盖。', 'chuyi-ai-relay'),
                'category'            => self::CATEGORY,
                'input_schema'        => array(
                    'type'       => 'object',
                    'properties' => array(
                        'ability' => array(
                            'type'        => 'string',
                            'description' => __('目标能力别名。', 'chuyi-ai-relay'),
                        ),
                    ),
                    'required'   => array('ability'),
                ),
                'output_schema'       => array(
                    'type'       => 'object',
                    'properties' => array(
                        'deleted' => array(
                            'type'        => 'boolean',
                            'description' => __('是否删除了覆盖配置。', 'chuyi-ai-relay'),
                        ),
                    ),
                ),
                'execute_callback'    => static function (array $input): array {
                    return array(
                        'deleted' => PromptOverrides::delete((string) ($input['ability'] ?? '')),
                    );
                },
                'permission_callback' => array(__CLASS__, 'manageOptionsPermission'),
                'meta'                => self::abilityMeta(),
            )
        );
    }

    private static function registerSetPromptOverrideEnabledAbility(): void
    {
        wp_register_ability(
            'chuyi-ai-relay/set-prompt-override-enabled',
            array(
                'label'               => __('启用或停用 AI 提示词覆盖', 'chuyi-ai-relay'),
                'description'         => __('不删除配置，仅启用或停用已保存的系统提示词覆盖。', 'chuyi-ai-relay'),
                'category'            => self::CATEGORY,
                'input_schema'        => array(
                    'type'       => 'object',
                    'properties' => array(
                        'ability' => array(
                            'type'        => 'string',
                            'description' => __('目标能力别名。', 'chuyi-ai-relay'),
                        ),
                        'enabled' => array(
                            'type'        => 'boolean',
                            'description' => __('此提示词覆盖是否应该启用。', 'chuyi-ai-relay'),
                        ),
                    ),
                    'required'   => array('ability', 'enabled'),
                ),
                'output_schema'       => self::promptOverrideSchema(),
                'execute_callback'    => static function (array $input) {
                    return PromptOverrides::setEnabled((string) ($input['ability'] ?? ''), (bool) ($input['enabled'] ?? false));
                },
                'permission_callback' => array(__CLASS__, 'manageOptionsPermission'),
                'meta'                => self::abilityMeta(),
            )
        );
    }

    /**
     * @param mixed $input
     * @return bool|\WP_Error
     */
    public static function manageOptionsPermission($input)
    {
        if (current_user_can('manage_options')) {
            return true;
        }

        return new \WP_Error('insufficient_capabilities', __('你没有管理 AI 提示词覆盖的权限。', 'chuyi-ai-relay'));
    }

    /**
     * @return array<string,mixed>
     */
    private static function promptOverrideSchema(): array
    {
        return array(
            'type'       => 'object',
            'properties' => array(
                'ability'     => array(
                    'type'        => 'string',
                    'description' => __('目标能力别名。', 'chuyi-ai-relay'),
                ),
                'instruction' => array(
                    'type'        => 'string',
                    'description' => __('替换或追加的系统提示词。', 'chuyi-ai-relay'),
                ),
                'mode'        => array(
                    'type'        => 'string',
                    'enum'        => array(PromptOverrides::MODE_REPLACE, PromptOverrides::MODE_APPEND),
                    'description' => __('提示词覆盖模式。', 'chuyi-ai-relay'),
                ),
                'enabled'     => array(
                    'type'        => 'boolean',
                    'description' => __('此提示词覆盖是否启用。', 'chuyi-ai-relay'),
                ),
                'updated_at'  => array(
                    'type'        => 'string',
                    'description' => __('ISO 8601 格式的最后更新时间。', 'chuyi-ai-relay'),
                ),
            ),
        );
    }

    /**
     * @return array<string,mixed>
     */
    private static function promptOverrideListSchema(): array
    {
        return array(
            'type'       => 'object',
            'properties' => array(
                'overrides' => array(
                    'type'        => 'array',
                    'description' => __('已保存的提示词覆盖。', 'chuyi-ai-relay'),
                    'items'       => self::promptOverrideSchema(),
                ),
            ),
        );
    }

    /**
     * @return array<string,mixed>
     */
    private static function abilityMeta(): array
    {
        return array(
            'show_in_rest' => true,
            'mcp'          => array(
                'public' => false,
                'type'   => 'tool',
            ),
        );
    }
}