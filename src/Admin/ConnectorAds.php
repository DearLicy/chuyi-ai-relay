<?php
/**
 * Connectors page recommendation cards for 初一 AI 中转.
 *
 * @package WordPress\ChuyiAiRelay\Admin
 */

declare(strict_types=1);

namespace WordPress\ChuyiAiRelay\Admin;

/**
 * Injects plugin-owned recommendation cards into the Connectors screen.
 */
final class ConnectorAds
{
    private const CONFIG_FILE = 'ads/connectors.json';
    private const REMOTE_CONFIG_URL = 'https://raw.githubusercontent.com/DearLicy/chuyi-ai-relay/main/ads/connectors.json';
    private const REMOTE_CACHE_KEY = 'chuyi_ai_relay_remote_connector_ads';
    private const REMOTE_CACHE_TTL = 1800;
    private const REMOTE_FAIL_CACHE_TTL = 300;

    /**
     * Registers admin hooks.
     */
    public static function init(): void
    {
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueueAssets'));
    }

    /**
     * Enqueues the Connectors ad assets only on the Connectors page.
     */
    public static function enqueueAssets(string $hookSuffix): void
    {
        if (!self::isConnectorsScreen($hookSuffix)) {
            return;
        }

        $cards = self::getCards();
        if (empty($cards)) {
            return;
        }

        wp_register_style('chuyi-ai-relay-connectors-ads', false, array(), \CHUYI_AI_RELAY_VERSION);
        wp_enqueue_style('chuyi-ai-relay-connectors-ads');
        wp_add_inline_style('chuyi-ai-relay-connectors-ads', self::getInlineStyles());

        wp_enqueue_script(
            'chuyi-ai-relay-connectors-ads',
            \CHUYI_AI_RELAY_URL . 'assets/js/connectors-ads.js',
            array(),
            \CHUYI_AI_RELAY_VERSION,
            true
        );

        wp_localize_script(
            'chuyi-ai-relay-connectors-ads',
            'chuyiAiRelayConnectorAds',
            array(
                'cards' => $cards,
            )
        );
    }

    /**
     * Detects the WordPress Connectors admin screen.
     */
    private static function isConnectorsScreen(string $hookSuffix): bool
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ($screen && isset($screen->id) && $screen->id === 'options-connectors') {
            return true;
        }

        if ($hookSuffix === 'options-connectors.php') {
            return true;
        }

        $script = isset($_SERVER['PHP_SELF']) && is_string($_SERVER['PHP_SELF']) ? basename($_SERVER['PHP_SELF']) : '';
        return $script === 'options-connectors.php';
    }

    /**
     * Returns normalized ad cards. Remote config wins; local config is the fallback.
     *
     * @return list<array<string,string>>
     */
    private static function getCards(): array
    {
        $remoteCards = self::getRemoteCards();
        return !empty($remoteCards) ? $remoteCards : self::getLocalCards();
    }

    /**
     * Returns normalized ad cards from GitHub raw config.
     *
     * @return list<array<string,string>>
     */
    private static function getRemoteCards(): array
    {
        $cached = get_transient(self::REMOTE_CACHE_KEY);
        if (is_array($cached)) {
            return self::normalizeCards($cached);
        }

        $response = wp_remote_get(self::REMOTE_CONFIG_URL, array(
            'timeout' => 8,
            'headers' => array(
                'Accept' => 'application/json',
                'User-Agent' => 'chuyi-ai-relay/' . \CHUYI_AI_RELAY_VERSION,
            ),
        ));

        if (is_wp_error($response)) {
            set_transient(self::REMOTE_CACHE_KEY, array(), self::REMOTE_FAIL_CACHE_TTL);
            return array();
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        if ($status < 200 || $status >= 300 || trim($body) === '') {
            set_transient(self::REMOTE_CACHE_KEY, array(), self::REMOTE_FAIL_CACHE_TTL);
            return array();
        }

        $decoded = json_decode($body, true);
        $cards = self::cardsFromDecodedConfig($decoded);
        set_transient(self::REMOTE_CACHE_KEY, $cards, empty($cards) ? self::REMOTE_FAIL_CACHE_TTL : self::REMOTE_CACHE_TTL);

        return $cards;
    }

    /**
     * Returns normalized ad cards from the ads folder.
     *
     * @return list<array<string,string>>
     */
    private static function getLocalCards(): array
    {
        $path = \CHUYI_AI_RELAY_DIR . self::CONFIG_FILE;
        if (!is_readable($path)) {
            return array();
        }

        $raw = file_get_contents($path);
        if (!is_string($raw) || trim($raw) === '') {
            return array();
        }

        $decoded = json_decode($raw, true);
        return self::cardsFromDecodedConfig($decoded);
    }

    /**
     * Extracts cards from supported JSON shapes.
     *
     * @param mixed $decoded Decoded JSON payload.
     * @return list<array<string,string>>
     */
    private static function cardsFromDecodedConfig($decoded): array
    {
        if (!is_array($decoded)) {
            return array();
        }

        $items = isset($decoded['cards']) && is_array($decoded['cards']) ? $decoded['cards'] : $decoded;
        return self::normalizeCards($items);
    }

    /**
     * Normalizes and sanitizes ad cards.
     *
     * @param mixed $items Raw card items.
     * @return list<array<string,string>>
     */
    private static function normalizeCards($items): array
    {
        if (!is_array($items)) {
            return array();
        }

        $cards = array();
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $title = isset($item['title']) && is_string($item['title']) ? sanitize_text_field($item['title']) : '';
            $url = isset($item['url']) && is_string($item['url']) ? esc_url_raw($item['url']) : '';

            if ($title === '' || $url === '') {
                continue;
            }

            $cards[] = array(
                'title' => $title,
                'url' => $url,
                'icon' => self::normalizeAssetUrl(isset($item['icon']) && is_string($item['icon']) ? $item['icon'] : 'assets/images/chuyi-relay.svg'),
            );
        }

        return $cards;
    }

    /**
     * Normalizes configured asset URLs.
     */
    private static function normalizeAssetUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        if (preg_match('#^https?://#i', $url)) {
            return esc_url_raw($url);
        }

        return esc_url_raw(\CHUYI_AI_RELAY_URL . ltrim($url, '/'));
    }

    /**
     * Returns scoped card styles.
     */
    private static function getInlineStyles(): string
    {
        return '
            .chuyi-ai-relay-connectors-ads{display:grid;gap:12px;margin:0 0 12px;color:#1d2327}
            .chuyi-ai-relay-connectors-ads__grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px}
            .chuyi-ai-relay-connectors-ad__card{display:grid;grid-template-columns:44px 1fr;gap:12px;padding:16px;border:1px solid #dcdcde;border-radius:12px;background:#fff;box-shadow:0 1px 2px rgba(0,0,0,.04)}
            .chuyi-ai-relay-connectors-ad__icon{width:44px;height:44px;border-radius:10px;object-fit:contain;background:#f0f6ff;border:1px solid #dbeafe}
            .chuyi-ai-relay-connectors-ad__title{margin:0 0 12px;font-size:15px;line-height:1.4;font-weight:700;color:#111827}
            @media (max-width:782px){.chuyi-ai-relay-connectors-ads__grid{grid-template-columns:1fr}.chuyi-ai-relay-connectors-ad__card{grid-template-columns:40px 1fr}.chuyi-ai-relay-connectors-ad__icon{width:40px;height:40px}}
        ';
    }
}