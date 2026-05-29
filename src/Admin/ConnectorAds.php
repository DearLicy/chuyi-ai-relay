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
        $localCards = self::getLocalCards();

        return !empty($localCards) ? $localCards : $remoteCards;
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

            $description = isset($item['description']) && is_string($item['description']) ? sanitize_text_field($item['description']) : '';
            $buttonText = isset($item['buttonText']) && is_string($item['buttonText']) ? sanitize_text_field($item['buttonText']) : __('前往', 'chuyi-ai-relay');
            $icon = isset($item['icon']) && is_string($item['icon']) ? sanitize_key($item['icon']) : 'dreamax';
            $iconUrl = isset($item['iconUrl']) && is_string($item['iconUrl']) ? self::normalizeAssetUrl($item['iconUrl']) : '';
            $connectorClass = isset($item['connectorClass']) && is_string($item['connectorClass']) ? sanitize_html_class($item['connectorClass']) : '';
            $itemClass = isset($item['itemClass']) && is_string($item['itemClass']) ? sanitize_text_field($item['itemClass']) : 'css-1bcj5ek';
            $componentClass = isset($item['componentClass']) && is_string($item['componentClass']) ? sanitize_text_field($item['componentClass']) : 'css-1v73mal e19lxcc00';
            $groupClass = isset($item['groupClass']) && is_string($item['groupClass']) ? sanitize_text_field($item['groupClass']) : 'components-flex components-h-stack components-v-stack css-8mn8b1 e19lxcc00';
            $rowClass = isset($item['rowClass']) && is_string($item['rowClass']) ? sanitize_text_field($item['rowClass']) : 'components-flex components-h-stack css-1mfjabq e19lxcc00';
            $contentClass = isset($item['contentClass']) && is_string($item['contentClass']) ? sanitize_text_field($item['contentClass']) : 'components-flex-item components-flex-block css-13y8vek e19lxcc00';
            $textStackClass = isset($item['textStackClass']) && is_string($item['textStackClass']) ? sanitize_text_field($item['textStackClass']) : 'components-flex components-h-stack components-v-stack css-7a7sy7 e19lxcc00';
            $titleClass = isset($item['titleClass']) && is_string($item['titleClass']) ? sanitize_text_field($item['titleClass']) : 'components-truncate components-text css-6jpe9g e19lxcc00';
            $descriptionClass = isset($item['descriptionClass']) && is_string($item['descriptionClass']) ? sanitize_text_field($item['descriptionClass']) : 'components-truncate components-text css-8t07xj e19lxcc00';
            $actionClass = isset($item['actionClass']) && is_string($item['actionClass']) ? sanitize_text_field($item['actionClass']) : 'components-flex components-h-stack css-ubkw7t e19lxcc00';
            $buttonClass = isset($item['buttonClass']) && is_string($item['buttonClass']) ? sanitize_text_field($item['buttonClass']) : 'components-button is-secondary is-compact';

            $id = isset($item['id']) && is_string($item['id']) ? sanitize_key($item['id']) : sanitize_key($title);

            $cards[] = array(
                'id' => $id,
                'title' => $title,
                'description' => $description,
                'url' => $url,
                'buttonText' => $buttonText !== '' ? $buttonText : __('前往', 'chuyi-ai-relay'),
                'icon' => $icon !== '' ? $icon : 'dreamax',
                'iconUrl' => $iconUrl,
                'itemClass' => $itemClass !== '' ? $itemClass : 'css-1bcj5ek',
                'componentClass' => $componentClass !== '' ? $componentClass : 'css-1v73mal e19lxcc00',
                'connectorClass' => $connectorClass !== '' ? $connectorClass : 'connector-item--ai-provider-for-' . $id,
                'groupClass' => $groupClass,
                'rowClass' => $rowClass,
                'contentClass' => $contentClass,
                'textStackClass' => $textStackClass,
                'titleClass' => $titleClass,
                'descriptionClass' => $descriptionClass,
                'actionClass' => $actionClass,
                'buttonClass' => $buttonClass,
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
            .chuyi-ai-relay-connector-ad{list-style:none}
            .chuyi-ai-relay-connector-ad svg,.chuyi-ai-relay-connector-ad img{flex:0 0 auto;line-height:1}
        ';
    }
}