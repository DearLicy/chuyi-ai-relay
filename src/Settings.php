<?php
/**
 * Shared settings helpers for 初一 AI 中转.
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
    public const SLOTS_OPTION = 'chuyi_ai_relay_slots';
    public const OPTIONS_OPTION = 'chuyi_ai_relay_options';
    public const RELAYS_KEY = 'relays';
    public const DEFAULT_SLOT_ID = 'default';
    public const MODE_OPENAI = 'openai';
    public const MODE_ANTHROPIC = 'anthropic';
    public const IMAGE_ENDPOINT_AUTO = 'auto';
    public const IMAGE_ENDPOINT_IMAGE = 'image';
    public const IMAGE_ENDPOINT_CHAT = 'chat';
    public const THINKING_DEPTH_OFF = 'off';
    public const THINKING_DEPTH_LOW = 'low';
    public const THINKING_DEPTH_MEDIUM = 'medium';
    public const THINKING_DEPTH_HIGH = 'high';

    /**
     * Returns normalized relay rows from the dynamic group.
     *
     * @return list<array<string,mixed>>
     */
    public static function getRelays(): array
    {
        $stored = get_option(self::SLOTS_OPTION, null);
        if (!is_array($stored)) {
            return array(self::getDefaultRelay(0));
        }

        $rawRelays = isset($stored[self::RELAYS_KEY]) && is_array($stored[self::RELAYS_KEY])
            ? $stored[self::RELAYS_KEY]
            : array();

        $relays = self::normalizeRelays($rawRelays);
        if ($relays !== $rawRelays) {
            update_option(self::SLOTS_OPTION, array(self::RELAYS_KEY => $relays), false);
        }

        return $relays;
    }

    /**
     * Returns normalized plugin-wide generation settings.
     *
     * @return array{context_max_tokens:int,max_output_tokens:int,thinking_depth:string,image_generation_timeout:int}
     */
    public static function getOptions(): array
    {
        $stored = get_option(self::OPTIONS_OPTION, array());
        return self::normalizeOptions($stored);
    }

    /**
     * Saves plugin-wide generation settings.
     *
     * @param mixed $options Raw settings payload.
     */
    public static function saveOptions($options): void
    {
        update_option(self::OPTIONS_OPTION, self::normalizeOptions($options), false);
    }

    /**
     * Normalizes plugin-wide generation settings.
     *
     * @param mixed $options Raw settings payload.
     * @return array{context_max_tokens:int,max_output_tokens:int,thinking_depth:string,image_generation_timeout:int}
     */
    public static function normalizeOptions($options): array
    {
        $options = is_array($options) ? $options : array();
        $defaults = self::getDefaultOptions();

        $thinkingDepth = isset($options['thinking_depth']) && is_string($options['thinking_depth'])
            ? sanitize_key($options['thinking_depth'])
            : $defaults['thinking_depth'];
        if (!in_array($thinkingDepth, array(self::THINKING_DEPTH_OFF, self::THINKING_DEPTH_LOW, self::THINKING_DEPTH_MEDIUM, self::THINKING_DEPTH_HIGH), true)) {
            $thinkingDepth = $defaults['thinking_depth'];
        }

        return array(
            'context_max_tokens'      => self::normalizePositiveInt($options['context_max_tokens'] ?? $defaults['context_max_tokens'], 0, 1000000),
            'max_output_tokens'       => self::normalizePositiveInt($options['max_output_tokens'] ?? $defaults['max_output_tokens'], 0, 200000),
            'thinking_depth'          => $thinkingDepth,
            'image_generation_timeout'=> self::normalizePositiveInt($options['image_generation_timeout'] ?? $defaults['image_generation_timeout'], 10, 3600),
        );
    }

    /**
     * Returns the configured image generation timeout in seconds.
     */
    public static function getImageGenerationTimeout(): int
    {
        $options = self::getOptions();
        return $options['image_generation_timeout'];
    }

    /**
     * Returns the configured max output token limit. 0 means do not override downstream settings.
     */
    public static function getMaxOutputTokens(): int
    {
        $options = self::getOptions();
        return $options['max_output_tokens'];
    }

    /**
     * Returns the configured context token budget. 0 means no plugin-level trimming.
     */
    public static function getContextMaxTokens(): int
    {
        $options = self::getOptions();
        return $options['context_max_tokens'];
    }

    /**
     * Returns the configured thinking depth.
     */
    public static function getThinkingDepth(): string
    {
        $options = self::getOptions();
        return $options['thinking_depth'];
    }

    /**
     * Returns normalized slot settings keyed by internal slot ID.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function getSlots(): array
    {
        $slots = array();
        foreach (self::getRelays() as $index => $relay) {
            $slotId = $relay['key'];
            $slots[$slotId] = self::relayToSlot($relay, $slotId, $index);
        }

        return $slots;
    }

    /**
     * Returns configured provider slots that should be registered with the AI Client.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function getRegisterableSlots(): array
    {
        $registerable = array();
        foreach (self::getSlots() as $slotId => $slot) {
            if (!empty($slot['enabled']) && !empty($slot['site_url'])) {
                $registerable[$slotId] = $slot;
            }
        }

        return $registerable;
    }

    /**
     * Returns one normalized slot.
     *
     * @return array<string,mixed>
     */
    public static function getSlot(string $slotId = self::DEFAULT_SLOT_ID): array
    {
        $slotId = self::normalizeSlotId($slotId);
        $slots = self::getSlots();

        if (isset($slots[$slotId])) {
            return $slots[$slotId];
        }

        $default = self::getDefaultRelay(0);
        $default['key'] = $slotId;
        return self::relayToSlot($default, $slotId, 0);
    }

    /**
     * Updates one slot by writing back to the dynamic relay group.
     *
     * @param array<string,mixed> $updates Slot fields to merge.
     */
    public static function updateSlot(string $slotId, array $updates): void
    {
        $slotId = self::normalizeSlotId($slotId);
        $relays = self::getRelays();
        $matched = false;

        foreach ($relays as $index => $relay) {
            if (($relay['key'] ?? '') !== $slotId) {
                continue;
            }

            $relays[$index] = array_merge($relay, $updates, array('key' => $slotId));
            $matched = true;
            break;
        }

        if (!$matched) {
            $relay = array_merge(self::getDefaultRelay(count($relays)), $updates, array('key' => $slotId));
            $relays[] = $relay;
        }

        self::saveRelays($relays);
    }

    /**
     * Normalizes the complete settings payload.
     *
     * @param mixed $data Raw save payload.
     * @return array<string,mixed>
     */
    public static function normalizeOption($data): array
    {
        $relays = is_array($data) && isset($data[self::RELAYS_KEY]) && is_array($data[self::RELAYS_KEY])
            ? $data[self::RELAYS_KEY]
            : array();

        return array(
            self::RELAYS_KEY => self::normalizeRelays($relays),
        );
    }

    /**
     * Normalizes relay group rows for storage.
     *
     * @param mixed $relays Raw relay rows.
     * @return list<array<string,mixed>>
     */
    public static function normalizeRelays($relays): array
    {
        if (!is_array($relays)) {
            return array();
        }

        $normalized = array();
        $seenKeys = array();
        foreach (array_values($relays) as $index => $relay) {
            if (!is_array($relay)) {
                continue;
            }

            $normalizedRelay = self::normalizeRelay($relay, $index);
            if (isset($seenKeys[$normalizedRelay['key']])) {
                $normalizedRelay['key'] = self::generateRelayKey($seenKeys);
            }

            $seenKeys[$normalizedRelay['key']] = true;
            $normalized[] = $normalizedRelay;
        }

        return $normalized;
    }

    /**
     * Saves relay group rows.
     *
     * @param list<array<string,mixed>> $relays Relay rows.
     */
    public static function saveRelays(array $relays): void
    {
        update_option(self::SLOTS_OPTION, array(self::RELAYS_KEY => self::normalizeRelays($relays)), false);
        if (function_exists(__NAMESPACE__ . '\\approve_own_connectors')) {
            approve_own_connectors();
        }
    }

    /**
     * Returns the provider ID for a slot.
     */
    public static function getProviderIdForSlot(string $slotId): string
    {
        $slotId = self::normalizeSlotId($slotId);
        if ($slotId === self::DEFAULT_SLOT_ID) {
            return 'chuyi-relay';
        }

        return 'chuyi-relay-' . str_replace('_', '-', $slotId);
    }

    /**
     * Resolves a slot ID from a provider ID.
     */
    public static function getSlotIdForProviderId(string $providerId): string
    {
        if ($providerId === 'chuyi-relay') {
            return self::DEFAULT_SLOT_ID;
        }

        if (preg_match('/^chuyi-relay-([a-z0-9][a-z0-9_-]*)$/', $providerId, $matches)) {
            return self::normalizeSlotId(str_replace('-', '_', $matches[1]));
        }

        return self::DEFAULT_SLOT_ID;
    }

    /**
     * Returns the connector option name for a slot API key.
     */
    public static function getConnectorApiKeyOption(string $slotId = self::DEFAULT_SLOT_ID): string
    {
        $providerId = self::getProviderIdForSlot($slotId);
        $sanitizedId = str_replace('-', '_', $providerId);

        return 'connectors_ai_' . $sanitizedId . '_api_key';
    }

    /**
     * Returns the constant/env name for a slot API key.
     */
    public static function getApiKeyConstant(string $slotId = self::DEFAULT_SLOT_ID): string
    {
        $providerId = self::getProviderIdForSlot($slotId);
        $sanitizedId = str_replace('-', '_', $providerId);

        return strtoupper((string) preg_replace('/([a-z])([A-Z])/', '$1_$2', $sanitizedId)) . '_API_KEY';
    }

    /**
     * Returns the saved relay root URL for a slot.
     */
    public static function getSiteUrl(string $slotId = self::DEFAULT_SLOT_ID): string
    {
        $slot = self::getSlot($slotId);
        return isset($slot['site_url']) && is_string($slot['site_url']) ? $slot['site_url'] : '';
    }

    /**
     * Returns the generated API base URL for a slot.
     */
    public static function getBaseUrl(string $slotId = self::DEFAULT_SLOT_ID): string
    {
        $siteUrl = self::getSiteUrl($slotId);
        if ($siteUrl === '') {
            return '';
        }

        $siteUrl = rtrim($siteUrl, '/');
        $siteUrl = preg_replace('#/(chat/completions|images/generations|models)$#i', '', $siteUrl) ?: $siteUrl;

        return preg_match('#/v\d+$#i', $siteUrl)
            ? $siteUrl
            : $siteUrl . '/v1';
    }

    /**
     * Returns the API mode for a slot.
     */
    public static function getMode(string $slotId = self::DEFAULT_SLOT_ID): string
    {
        $slot = self::getSlot($slotId);
        return isset($slot['mode']) && in_array($slot['mode'], array(self::MODE_OPENAI, self::MODE_ANTHROPIC), true)
            ? $slot['mode']
            : self::MODE_OPENAI;
    }

    /**
     * Returns the selected image generation endpoint for a slot.
     */
    public static function getImageEndpoint(string $slotId = self::DEFAULT_SLOT_ID): string
    {
        $slot = self::getSlot($slotId);
        return isset($slot['image_endpoint']) && in_array($slot['image_endpoint'], array(self::IMAGE_ENDPOINT_IMAGE, self::IMAGE_ENDPOINT_CHAT, self::IMAGE_ENDPOINT_AUTO), true)
            ? $slot['image_endpoint']
            : self::IMAGE_ENDPOINT_IMAGE;
    }

    /**
     * Returns the display name for a slot.
     */
    public static function getSlotName(string $slotId = self::DEFAULT_SLOT_ID): string
    {
        $slot = self::getSlot($slotId);
        return isset($slot['name']) && is_string($slot['name']) && $slot['name'] !== ''
            ? $slot['name']
            : self::getDefaultRelayName(self::getSlotIndex($slotId));
    }

    /**
     * Returns the favicon URL for a slot.
     */
    public static function getLogoUrl(string $slotId = self::DEFAULT_SLOT_ID): string
    {
        $siteUrl = self::getSiteUrl($slotId);
        return $siteUrl === '' ? '' : esc_url_raw(rtrim($siteUrl, '/') . '/favicon.ico');
    }

    /**
     * Builds an endpoint URL for the provider that owns the given provider ID.
     */
    public static function urlForProviderId(string $providerId, string $path = ''): string
    {
        return self::urlForSlot(self::getSlotIdForProviderId($providerId), $path);
    }

    /**
     * Builds an endpoint URL for a slot.
     */
    public static function urlForSlot(string $slotId, string $path = ''): string
    {
        $baseUrl = self::getBaseUrl($slotId);
        if ($baseUrl === '') {
            $baseUrl = 'https://example.invalid/v1';
        }

        if ($path === '') {
            return $baseUrl;
        }

        return rtrim($baseUrl, '/') . '/' . ltrim($path, '/');
    }

    /**
     * Normalizes the relay root URL. Input must include http:// or https://.
     */
    public static function normalizeSiteUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        $url = preg_replace('/\s+/', '', $url);
        if (!is_string($url) || $url === '' || !preg_match('#^https?://#i', $url)) {
            return '';
        }

        $parts = wp_parse_url($url);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return '';
        }

        $scheme = strtolower((string) $parts['scheme']);
        $host = strtolower((string) $parts['host']);
        $path = isset($parts['path']) && is_string($parts['path']) ? '/' . trim($parts['path'], '/') : '';
        if ($path === '/') {
            $path = '';
        }

        $normalized = $scheme . '://' . $host;
        if (!empty($parts['port'])) {
            $normalized .= ':' . (int) $parts['port'];
        }
        $normalized .= $path;

        return esc_url_raw($normalized);
    }

    /**
     * Returns the API key from environment, constant, or Connectors option.
     */
    public static function getApiKey(string $slotId = self::DEFAULT_SLOT_ID): string
    {
        $constantName = self::getApiKeyConstant($slotId);

        $env = getenv($constantName);
        if (is_string($env) && $env !== '') {
            return $env;
        }

        if (defined($constantName)) {
            $constant = constant($constantName);
            if (is_scalar($constant) && (string) $constant !== '') {
                return (string) $constant;
            }
        }

        $option = get_option(self::getConnectorApiKeyOption($slotId), '');
        return is_string($option) ? $option : '';
    }

    /**
     * Returns models saved for a slot.
     *
     * @return list<array{id:string,name:string}>
     */
    public static function getModels(string $slotId = self::DEFAULT_SLOT_ID): array
    {
        $slot = self::getSlot($slotId);
        return isset($slot['models']) && is_array($slot['models']) ? self::normalizeModels($slot['models']) : array();
    }

    /**
     * Returns the saved capability map keyed by model ID.
     *
     * @return array<string,list<string>>
     */
    public static function getModelCapabilities(string $slotId = self::DEFAULT_SLOT_ID): array
    {
        $slot = self::getSlot($slotId);
        return isset($slot['capabilities']) && is_array($slot['capabilities'])
            ? self::normalizeCapabilityMap($slot['capabilities'])
            : array();
    }

    /**
     * Saves fetched models and preserves existing manual capability choices.
     *
     * @param list<array{id:string,name:string}> $models Models to save.
     */
    public static function saveFetchedModels(array $models, string $slotId = self::DEFAULT_SLOT_ID): void
    {
        $models = self::normalizeModels($models);
        $existingCapabilities = self::getModelCapabilities($slotId);
        $rows = array();
        $nextCapabilities = array();

        foreach ($models as $model) {
            $id = $model['id'];
            $capabilities = $existingCapabilities[$id] ?? self::inferCapabilities($id);
            $rows[] = array(
                'id'           => $id,
                'name'         => $model['name'],
                'capabilities' => $capabilities,
            );
            $nextCapabilities[$id] = $capabilities;
        }

        self::updateSlot(
            $slotId,
            array(
                'models'       => $rows,
                'capabilities' => $nextCapabilities,
            )
        );
    }

    /**
     * Infers model capabilities from common model IDs.
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

        if (preg_match('/(gpt-4o|gpt-4\.1|gpt-5|^o1|^o3|^o4|vision|\bvl\b|qwen-vl|glm-4v|llava|claude-3|claude-sonnet|claude-opus|claude-haiku)/', $id)) {
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

    /**
     * Normalizes one slot ID into the supported internal range.
     */
    private static function normalizeSlotId(string $slotId): string
    {
        $slotId = sanitize_key(str_replace('-', '_', strtolower(trim($slotId))));
        if ($slotId === '') {
            return self::DEFAULT_SLOT_ID;
        }

        if (preg_match('/^r(\d+)$/', $slotId, $matches)) {
            $number = max(1, (int) $matches[1]);
            return $number === 1 ? self::DEFAULT_SLOT_ID : (string) $number;
        }

        if (preg_match('/^\d+$/', $slotId)) {
            $number = max(1, (int) $slotId);
            return $number === 1 ? self::DEFAULT_SLOT_ID : (string) $number;
        }

        return $slotId;
    }

    /**
     * Returns the zero-based relay index for display fallback only.
     */
    private static function getSlotIndex(string $slotId): int
    {
        $slots = array_keys(self::getSlots());
        $index = array_search(self::normalizeSlotId($slotId), $slots, true);
        return is_int($index) ? $index : 0;
    }

    /**
     * Returns default plugin-wide generation settings.
     *
     * @return array{context_max_tokens:int,max_output_tokens:int,thinking_depth:string,image_generation_timeout:int}
     */
    private static function getDefaultOptions(): array
    {
        return array(
            'context_max_tokens'       => 0,
            'max_output_tokens'        => 0,
            'thinking_depth'           => self::THINKING_DEPTH_OFF,
            'image_generation_timeout' => 90,
        );
    }

    /**
     * Normalizes an integer setting into an allowed inclusive range. 0 is allowed only when the minimum is 0.
     *
     * @param mixed $value Raw integer-like value.
     */
    private static function normalizePositiveInt($value, int $min, int $max): int
    {
        $number = is_numeric($value) ? (int) $value : $min;
        if ($number <= 0 && $min === 0) {
            return 0;
        }

        return max($min, min($max, $number));
    }

    /**
     * Returns default relay row.
     *
     * @return array<string,mixed>
     */
    private static function getDefaultRelay(int $index): array
    {
        return array(
            'key'     => $index === 0 ? self::DEFAULT_SLOT_ID : self::generateRelayKey(),
            'enabled' => $index === 0,
            'name'    => self::getDefaultRelayName($index),
            'site_url'=> '',
            'mode'    => self::MODE_OPENAI,
            'image_endpoint' => self::IMAGE_ENDPOINT_IMAGE,
            'models'  => array(),
            'status'  => array(
                'latency' => 0,
                'ok'      => null,
                'message' => '',
                'checked' => '',
            ),
        );
    }

    /**
     * Returns default relay display name.
     */
    private static function getDefaultRelayName(int $index): string
    {
        return $index === 0 ? '初一 AI 中转' : '初一 AI 中转 ' . ($index + 1);
    }

    /**
     * Converts a normalized relay row to an internal slot.
     *
     * @param array<string,mixed> $relay Relay row.
     * @return array<string,mixed>
     */
    private static function relayToSlot(array $relay, string $slotId, int $index): array
    {
        $relay = self::normalizeRelay($relay, $index);

        return array(
            'id'           => $slotId,
            'key'          => $relay['key'],
            'index'        => $index,
            'provider_id'  => self::getProviderIdForSlot($slotId),
            'enabled'      => (bool) $relay['enabled'],
            'name'         => $relay['name'],
            'mode'         => $relay['mode'],
            'image_endpoint' => $relay['image_endpoint'],
            'site_url'     => $relay['site_url'],
            'models'       => $relay['models'],
            'status'       => $relay['status'],
            'capabilities' => self::capabilitiesFromModelRows($relay['models']),
        );
    }

    /**
     * Normalizes one relay row.
     *
     * @param array<string,mixed> $relay Raw relay row.
     * @return array<string,mixed>
     */
    private static function normalizeRelay(array $relay, int $index): array
    {
        $default = self::getDefaultRelay($index);

        $rawKey = isset($relay['key']) && is_string($relay['key']) ? $relay['key'] : '';
        $key = self::normalizeRelayKey($rawKey);
        if ($key === '') {
            $key = $index === 0 ? self::DEFAULT_SLOT_ID : self::generateRelayKey();
        }

        $mode = isset($relay['mode']) && is_string($relay['mode']) ? sanitize_key($relay['mode']) : $default['mode'];
        if (!in_array($mode, array(self::MODE_OPENAI, self::MODE_ANTHROPIC), true)) {
            $mode = self::MODE_OPENAI;
        }

        $imageEndpoint = isset($relay['image_endpoint']) && is_string($relay['image_endpoint']) ? sanitize_key($relay['image_endpoint']) : $default['image_endpoint'];
        if (!in_array($imageEndpoint, array(self::IMAGE_ENDPOINT_IMAGE, self::IMAGE_ENDPOINT_CHAT, self::IMAGE_ENDPOINT_AUTO), true)) {
            $imageEndpoint = self::IMAGE_ENDPOINT_IMAGE;
        }

        $name = isset($relay['name']) && is_string($relay['name']) ? sanitize_text_field($relay['name']) : $default['name'];
        if ($name === '') {
            $name = $default['name'];
        }

        $rawUrl = isset($relay['site_url']) && is_string($relay['site_url']) ? $relay['site_url'] : '';
        $siteUrl = self::normalizeSiteUrl($rawUrl);

        $models = isset($relay['models']) && is_array($relay['models']) ? self::normalizeModels($relay['models']) : array();
        $status = isset($relay['status']) && is_array($relay['status']) ? self::normalizeStatus($relay['status']) : $default['status'];

        return array(
            'key'     => $key,
            'enabled' => isset($relay['enabled']) ? (bool) $relay['enabled'] : (bool) $default['enabled'],
            'name'    => $name,
            'site_url'=> $siteUrl,
            'mode'    => $mode,
            'image_endpoint' => $imageEndpoint,
            'models'  => $models,
            'status'  => $status,
        );
    }

    /**
     * Normalizes a persisted relay key.
     */
    private static function normalizeRelayKey(string $key): string
    {
        $key = sanitize_key(str_replace('-', '_', strtolower(trim($key))));
        if ($key === '') {
            return '';
        }

        if (preg_match('/^r(\d+)$/', $key, $matches)) {
            $number = max(1, (int) $matches[1]);
            return $number === 1 ? self::DEFAULT_SLOT_ID : (string) $number;
        }

        if (preg_match('/^\d+$/', $key)) {
            $number = max(1, (int) $key);
            return $number === 1 ? self::DEFAULT_SLOT_ID : (string) $number;
        }

        return $key;
    }

    /**
     * Generates a stable relay key for new group rows.
     *
     * @param array<string,bool> $reserved Existing keys.
     */
    public static function generateRelayKey(array $reserved = array()): string
    {
        do {
            $key = substr(md5(uniqid('', true)), 0, 12);
        } while (isset($reserved[$key]) || $key === self::DEFAULT_SLOT_ID || preg_match('/^\d+$/', $key));

        return $key;
    }

    /**
     * Normalizes runtime status data for one relay.
     *
     * @param mixed $status Raw status row.
     * @return array{latency:int,ok:bool|null,message:string,checked:string}
     */
    private static function normalizeStatus($status): array
    {
        if (!is_array($status)) {
            return array(
                'latency' => 0,
                'ok'      => null,
                'message' => '',
                'checked' => '',
            );
        }

        $ok = null;
        if (array_key_exists('ok', $status)) {
            $ok = $status['ok'] === null ? null : (bool) $status['ok'];
        }

        $checked = isset($status['checked']) && is_string($status['checked']) ? sanitize_text_field($status['checked']) : '';

        return array(
            'latency' => isset($status['latency']) ? max(0, (int) $status['latency']) : 0,
            'ok'      => $ok,
            'message' => isset($status['message']) && is_string($status['message']) ? sanitize_text_field($status['message']) : '',
            'checked' => preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $checked) ? $checked : '',
        );
    }

    /**
     * Normalizes model rows.
     *
     * @param mixed $models Raw model list.
     * @return list<array{id:string,name:string,capabilities:list<string>}>
     */
    private static function normalizeModels($models): array
    {
        if (!is_array($models)) {
            return array();
        }

        $normalized = array();
        $seen = array();
        foreach ($models as $model) {
            if (!is_array($model) || empty($model['id']) || !is_string($model['id'])) {
                continue;
            }

            $id = sanitize_text_field($model['id']);
            if ($id === '' || isset($seen[$id])) {
                continue;
            }

            $name = isset($model['name']) && is_string($model['name']) && $model['name'] !== ''
                ? sanitize_text_field($model['name'])
                : $id;

            $normalized[] = array(
                'id'           => $id,
                'name'         => $name,
                'capabilities' => isset($model['capabilities']) && is_array($model['capabilities'])
                    ? self::sanitizeCapabilities($model['capabilities'])
                    : self::inferCapabilities($id),
            );
            $seen[$id] = true;
        }

        return $normalized;
    }

    /**
     * Builds a capability map from model group rows.
     *
     * @param mixed $modelRows Raw model rows.
     * @return array<string,list<string>>
     */
    private static function capabilitiesFromModelRows($modelRows): array
    {
        if (!is_array($modelRows)) {
            return array();
        }

        $capabilities = array();
        foreach ($modelRows as $row) {
            if (!is_array($row) || empty($row['id']) || !is_string($row['id'])) {
                continue;
            }

            $modelId = sanitize_text_field($row['id']);
            if ($modelId === '') {
                continue;
            }

            $capabilities[$modelId] = isset($row['capabilities']) && is_array($row['capabilities'])
                ? self::sanitizeCapabilities($row['capabilities'])
                : self::inferCapabilities($modelId);
        }

        return $capabilities;
    }

    /**
     * Normalizes capability map.
     *
     * @param mixed $capabilities Raw capability map.
     * @return array<string,list<string>>
     */
    private static function normalizeCapabilityMap($capabilities): array
    {
        if (!is_array($capabilities)) {
            return array();
        }

        $normalized = array();
        foreach ($capabilities as $modelId => $modelCapabilities) {
            if (!is_string($modelId) || !is_array($modelCapabilities)) {
                continue;
            }

            $modelId = sanitize_text_field($modelId);
            if ($modelId === '') {
                continue;
            }

            $normalized[$modelId] = self::sanitizeCapabilities($modelCapabilities);
        }

        return $normalized;
    }
}