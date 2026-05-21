<?php
/**
 * Shared settings helpers for 初一中转.
 *
 * @package WordPress\ChuyiAiRelay
 */

declare(strict_types=1);

namespace WordPress\ChuyiAiRelay;

/**
 * Stores and normalizes plugin settings.
 */
final class Settings
{
    public const BASE_URL_OPTION = 'chuyi_ai_relay_base_url';
    public const MODELS_OPTION = 'chuyi_ai_relay_models';
    public const MODEL_CAPABILITIES_OPTION = 'chuyi_ai_relay_model_capabilities';
    public const CONNECTOR_API_KEY_OPTION = 'connectors_ai_chuyi_relay_api_key';
    public const API_KEY_CONSTANT = 'CHUYI_RELAY_API_KEY';

    /**
     * Returns the saved OpenAI-compatible base URL.
     */
    public static function getBaseUrl(): string
    {
        return self::normalizeBaseUrl((string) get_option(self::BASE_URL_OPTION, ''));
    }

    /**
     * Normalizes a relay root and guarantees an OpenAI-compatible /v1 base URL.
     */
    public static function normalizeBaseUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        $url = preg_replace('/\s+/', '', $url);
        if (!is_string($url) || $url === '') {
            return '';
        }

        if (!preg_match('#^https?://#i', $url)) {
            $url = 'https://' . $url;
        }

        $parts = wp_parse_url($url);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return '';
        }

        $path = isset($parts['path']) ? rtrim($parts['path'], '/') : '';
        $path = preg_replace('#/(models|chat/completions|responses|images/generations)$#', '', $path);
        if (!is_string($path)) {
            $path = '';
        }
        if ($path === '' || !preg_match('#/v\d+(?:\.[\d]+)?$#i', $path)) {
            $path .= '/v1';
        }

        $normalized = strtolower((string) $parts['scheme']) . '://' . $parts['host'];
        if (!empty($parts['port'])) {
            $normalized .= ':' . (int) $parts['port'];
        }
        $normalized .= $path;

        return esc_url_raw(rtrim($normalized, '/'));
    }

    /**
     * Returns the API key from environment, constant, or Connectors option.
     */
    public static function getApiKey(): string
    {
        $env = getenv(self::API_KEY_CONSTANT);
        if (is_string($env) && $env !== '') {
            return $env;
        }

        if (defined(self::API_KEY_CONSTANT)) {
            $constant = constant(self::API_KEY_CONSTANT);
            if (is_scalar($constant) && (string) $constant !== '') {
                return (string) $constant;
            }
        }

        $option = get_option(self::CONNECTOR_API_KEY_OPTION, '');
        return is_string($option) ? $option : '';
    }

    /**
     * Returns models saved by the one-click fetch action.
     *
     * @return list<array{id:string,name:string}>
     */
    public static function getModels(): array
    {
        $models = get_option(self::MODELS_OPTION, array());
        if (!is_array($models)) {
            return array();
        }

        $normalized = array();
        foreach ($models as $model) {
            if (!is_array($model) || empty($model['id']) || !is_string($model['id'])) {
                continue;
            }

            $id = sanitize_text_field($model['id']);
            if ($id === '') {
                continue;
            }

            $name = isset($model['name']) && is_string($model['name']) && $model['name'] !== ''
                ? sanitize_text_field($model['name'])
                : $id;

            $normalized[] = array(
                'id'   => $id,
                'name' => $name,
            );
        }

        return $normalized;
    }

    /**
     * Returns the saved capability map keyed by model ID.
     *
     * @return array<string,list<string>>
     */
    public static function getModelCapabilities(): array
    {
        $capabilities = get_option(self::MODEL_CAPABILITIES_OPTION, array());
        if (!is_array($capabilities)) {
            return array();
        }

        $normalized = array();
        foreach ($capabilities as $modelId => $modelCapabilities) {
            if (!is_string($modelId) || !is_array($modelCapabilities)) {
                continue;
            }

            $normalizedCapabilities = self::sanitizeCapabilities($modelCapabilities);
            $normalized[$modelId] = $normalizedCapabilities;
        }

        return $normalized;
    }

    /**
     * Saves fetched models and preserves existing manual capability choices.
     *
     * @param list<array{id:string,name:string}> $models Models to save.
     */
    public static function saveFetchedModels(array $models): void
    {
        $existingCapabilities = self::getModelCapabilities();
        $nextCapabilities = array();
        $nextModels = array();

        foreach ($models as $model) {
            if (empty($model['id']) || !is_string($model['id'])) {
                continue;
            }

            $id = sanitize_text_field($model['id']);
            if ($id === '') {
                continue;
            }

            $name = !empty($model['name']) && is_string($model['name']) ? sanitize_text_field($model['name']) : $id;
            $nextModels[] = array(
                'id'   => $id,
                'name' => $name,
            );
            $nextCapabilities[$id] = $existingCapabilities[$id] ?? self::inferCapabilities($id);
        }

        update_option(self::MODELS_OPTION, $nextModels, false);
        update_option(self::MODEL_CAPABILITIES_OPTION, $nextCapabilities, false);
    }

    /**
     * Saves manually selected model capabilities.
     *
     * @param array<string,list<string>> $capabilities Capability map.
     */
    public static function saveModelCapabilities(array $capabilities): void
    {
        $models = self::getModels();

        $normalized = array();
        foreach ($models as $model) {
            $modelId = $model['id'];
            $modelCapabilities = isset($capabilities[$modelId]) && is_array($capabilities[$modelId])
                ? $capabilities[$modelId]
                : array();

            $normalized[$modelId] = self::sanitizeCapabilities($modelCapabilities);
        }

        update_option(self::MODEL_CAPABILITIES_OPTION, $normalized, false);
    }

    /**
     * Infers model capabilities from common OpenAI-compatible model IDs.
     *
     * @return list<string>
     */
    public static function inferCapabilities(string $modelId): array
    {
        $id = strtolower($modelId);

        if (preg_match('/(dall-e|gpt-image|imagen|flux|stable-diffusion|sdxl|midjourney)/', $id)) {
            return array('image_generation');
        }

        if (preg_match('/(embedding|embed|rerank|moderation|tts|whisper|transcribe|realtime)/', $id)) {
            return array();
        }

        if (preg_match('/(gpt-4o|gpt-4\.1|gpt-5|^o1|^o3|^o4|vision|\bvl\b|qwen-vl|glm-4v|llava)/', $id)) {
            return array('text_generation', 'vision');
        }

        return array('text_generation');
    }

    /**
     * Sanitizes capability strings. 生图模型必须作为独立模型使用。
     *
     * @param array<mixed> $capabilities Raw capability values.
     * @return list<string>
     */
    public static function sanitizeCapabilities(array $capabilities): array
    {
        $allowed = array('text_generation', 'vision', 'image_generation');
        $capabilities = array_values(array_unique(array_filter(array_map('strval', $capabilities))));
        $capabilities = array_values(array_intersect($allowed, $capabilities));

        if (in_array('image_generation', $capabilities, true)) {
            return array('image_generation');
        }

        if (in_array('vision', $capabilities, true) && !in_array('text_generation', $capabilities, true)) {
            array_unshift($capabilities, 'text_generation');
        }

        return array_values($capabilities);
    }
}