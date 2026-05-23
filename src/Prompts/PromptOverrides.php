<?php
/**
 * Prompt override storage and runtime integration.
 *
 * @package WordPress\ChuyiAiRelay\Prompts
 */

declare(strict_types=1);

namespace WordPress\ChuyiAiRelay\Prompts;

if (!defined('ABSPATH')) {
    return;
}

final class PromptOverrides
{
    public const OPTION = 'chuyi_ai_relay_prompt_overrides';
    public const MODE_REPLACE = 'replace';
    public const MODE_APPEND = 'append';

    /**
     * Registers runtime hooks.
     */
    public static function init(): void
    {
        add_filter('wpai_system_instruction', array(__CLASS__, 'filterSystemInstruction'), 20, 3);
    }

    /**
     * Applies a saved override to an AI plugin ability system instruction.
     *
     * @param array<string,mixed> $data
     */
    public static function filterSystemInstruction(string $instruction, string $abilityName, array $data): string
    {
        $override = self::get($abilityName);
        if ($override === null || !$override['enabled']) {
            return $instruction;
        }

        if ($override['mode'] === self::MODE_APPEND) {
            return trim($instruction . "\n\n" . $override['instruction']);
        }

        return $override['instruction'];
    }

    /**
     * Returns all normalized prompt overrides.
     *
     * @return array<string,array{ability:string,instruction:string,mode:string,enabled:bool,updated_at:string}>
     */
    public static function all(): array
    {
        $stored = get_option(self::OPTION, array());
        if (!is_array($stored)) {
            return array();
        }

        $items = array();
        foreach ($stored as $ability => $override) {
            if (!is_string($ability) || !is_array($override)) {
                continue;
            }

            $normalized = self::normalize($ability, $override);
            if ($normalized !== null) {
                $items[$normalized['ability']] = $normalized;
            }
        }

        ksort($items);
        return $items;
    }

    /**
     * Returns one normalized prompt override.
     *
     * @return array{ability:string,instruction:string,mode:string,enabled:bool,updated_at:string}|null
     */
    public static function get(string $ability): ?array
    {
        $ability = self::normalizeAbility($ability);
        if ($ability === '') {
            return null;
        }

        $items = self::all();
        return $items[$ability] ?? null;
    }

    /**
     * Saves one prompt override.
     *
     * @param array<string,mixed> $data
     * @return array{ability:string,instruction:string,mode:string,enabled:bool,updated_at:string}|\WP_Error
     */
    public static function save(string $ability, array $data)
    {
        $ability = self::normalizeAbility($ability);
        if ($ability === '') {
            return new \WP_Error('invalid_ability', __('必须提供能力别名。', 'chuyi-ai-relay'));
        }

        $instruction = isset($data['instruction']) && is_string($data['instruction'])
            ? trim($data['instruction'])
            : '';
        if ($instruction === '') {
            return new \WP_Error('empty_instruction', __('必须提供提示词指令。', 'chuyi-ai-relay'));
        }

        $mode = isset($data['mode']) && is_string($data['mode']) ? sanitize_key($data['mode']) : self::MODE_REPLACE;
        if (!in_array($mode, array(self::MODE_REPLACE, self::MODE_APPEND), true)) {
            $mode = self::MODE_REPLACE;
        }

        $enabled = array_key_exists('enabled', $data) ? (bool) $data['enabled'] : true;

        $items = self::all();
        $items[$ability] = array(
            'ability'     => $ability,
            'instruction' => $instruction,
            'mode'        => $mode,
            'enabled'     => $enabled,
            'updated_at'  => gmdate('c'),
        );

        update_option(self::OPTION, $items, false);
        return $items[$ability];
    }

    /**
     * Deletes one prompt override.
     */
    public static function delete(string $ability): bool
    {
        $ability = self::normalizeAbility($ability);
        if ($ability === '') {
            return false;
        }

        $items = self::all();
        if (!isset($items[$ability])) {
            return false;
        }

        unset($items[$ability]);
        update_option(self::OPTION, $items, false);
        return true;
    }

    /**
     * Toggles one prompt override.
     *
     * @return array{ability:string,instruction:string,mode:string,enabled:bool,updated_at:string}|\WP_Error
     */
    public static function setEnabled(string $ability, bool $enabled)
    {
        $override = self::get($ability);
        if ($override === null) {
            return new \WP_Error('prompt_override_not_found', __('未找到提示词覆盖。', 'chuyi-ai-relay'));
        }

        $override['enabled'] = $enabled;
        $override['updated_at'] = gmdate('c');

        $items = self::all();
        $items[$override['ability']] = $override;
        update_option(self::OPTION, $items, false);

        return $override;
    }

    /**
     * @param array<string,mixed> $override
     * @return array{ability:string,instruction:string,mode:string,enabled:bool,updated_at:string}|null
     */
    private static function normalize(string $ability, array $override): ?array
    {
        $ability = self::normalizeAbility($ability);
        if ($ability === '') {
            return null;
        }

        $instruction = isset($override['instruction']) && is_string($override['instruction'])
            ? trim($override['instruction'])
            : '';
        if ($instruction === '') {
            return null;
        }

        $mode = isset($override['mode']) && is_string($override['mode']) ? sanitize_key($override['mode']) : self::MODE_REPLACE;
        if (!in_array($mode, array(self::MODE_REPLACE, self::MODE_APPEND), true)) {
            $mode = self::MODE_REPLACE;
        }

        $updatedAt = isset($override['updated_at']) && is_string($override['updated_at']) ? $override['updated_at'] : '';

        return array(
            'ability'     => $ability,
            'instruction' => $instruction,
            'mode'        => $mode,
            'enabled'     => !empty($override['enabled']),
            'updated_at'  => $updatedAt,
        );
    }

    private static function normalizeAbility(string $ability): string
    {
        $ability = trim($ability);
        if ($ability === '') {
            return '';
        }

        return sanitize_text_field($ability);
    }
}