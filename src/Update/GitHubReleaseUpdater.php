<?php
/**
 * GitHub Releases update checker for 初一 AI 中转.
 *
 * @package WordPress\ChuyiAiRelay\Update
 */

declare(strict_types=1);

namespace WordPress\ChuyiAiRelay\Update;

if (!defined('ABSPATH')) {
    return;
}

/**
 * Integrates GitHub Releases with the WordPress plugin update flow.
 */
final class GitHubReleaseUpdater
{
    private const API_URL = 'https://api.github.com/repos/DearLicy/chuyi-ai-relay/releases/latest';
    private const RELEASES_URL = 'https://github.com/DearLicy/chuyi-ai-relay/releases';
    private const CACHE_KEY = 'chuyi_ai_relay_latest_release';
    private const CACHE_TTL = 1800;

    /**
     * Registers update hooks.
     */
    public static function init(): void
    {
        add_filter('pre_set_site_transient_update_plugins', array(__CLASS__, 'filterUpdateTransient'));
        add_filter('plugins_api', array(__CLASS__, 'filterPluginInfo'), 10, 3);
    }

    /**
     * Adds update metadata to WordPress plugin update checks.
     *
     * @param mixed $transient WordPress update transient.
     * @return mixed
     */
    public static function filterUpdateTransient($transient)
    {
        if (!is_object($transient)) {
            return $transient;
        }

        $release = self::getRelease();
        $pluginFile = plugin_basename(\CHUYI_AI_RELAY_FILE);

        if (!$release || version_compare($release['version'], \CHUYI_AI_RELAY_VERSION, '<=')) {
            if (!isset($transient->no_update) || !is_array($transient->no_update)) {
                $transient->no_update = array();
            }
            $transient->no_update[$pluginFile] = self::buildUpdateObject($release ?: self::currentRelease());
            return $transient;
        }

        if (!isset($transient->response) || !is_array($transient->response)) {
            $transient->response = array();
        }
        $transient->response[$pluginFile] = self::buildUpdateObject($release);

        return $transient;
    }

    /**
     * Provides the plugin details modal content.
     *
     * @param mixed  $result Existing result.
     * @param string $action Requested action.
     * @param object $args Plugin API args.
     * @return mixed
     */
    public static function filterPluginInfo($result, string $action, $args)
    {
        if ($action !== 'plugin_information' || !is_object($args) || ($args->slug ?? '') !== self::slug()) {
            return $result;
        }

        $release = self::getRelease() ?: self::currentRelease();
        $info = self::buildUpdateObject($release);
        $info->sections = array(
            'description' => '为 WordPress AI Client 增加多中转、多协议模型池，支持 OpenAI-compatible 与 Anthropic Messages 接入。',
            'changelog'   => self::formatChangelog($release['body'] ?? ''),
        );
        $info->banners = array();
        $info->icons = array(
            'svg' => \CHUYI_AI_RELAY_URL . 'assets/images/chuyi-relay.svg',
            'default' => \CHUYI_AI_RELAY_URL . 'assets/images/chuyi-relay.svg',
        );

        return $info;
    }

    /**
     * Returns latest release metadata when a newer version is available.
     *
     * @return array<string,mixed>|null
     */
    public static function getAvailableUpdate(): ?array
    {
        $release = self::getRelease();
        if (!$release || version_compare($release['version'], \CHUYI_AI_RELAY_VERSION, '<=')) {
            return null;
        }

        return $release;
    }

    /**
     * Forces a fresh release check and returns newer release metadata when available.
     *
     * @return array<string,mixed>|null
     */
    public static function refreshAvailableUpdate(): ?array
    {
        delete_site_transient(self::CACHE_KEY);
        return self::getAvailableUpdate();
    }

    /**
     * Fetches and caches the latest release.
     *
     * @return array<string,mixed>|null
     */
    private static function getRelease(): ?array
    {
        $cached = get_site_transient(self::CACHE_KEY);
        if (is_array($cached)) {
            return $cached;
        }

        $response = wp_remote_get(self::API_URL, array(
            'timeout' => 10,
            'headers' => array(
                'Accept' => 'application/vnd.github+json',
                'User-Agent' => 'chuyi-ai-relay/' . \CHUYI_AI_RELAY_VERSION,
            ),
        ));

        if (is_wp_error($response)) {
            return null;
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        if ($status < 200 || $status >= 300 || $body === '') {
            return null;
        }

        $data = json_decode($body, true);
        if (!is_array($data) || !empty($data['draft']) || !empty($data['prerelease'])) {
            return null;
        }

        $version = self::normalizeVersion(isset($data['tag_name']) && is_string($data['tag_name']) ? $data['tag_name'] : '');
        if ($version === '') {
            return null;
        }

        $release = array(
            'version' => $version,
            'tag' => isset($data['tag_name']) && is_string($data['tag_name']) ? $data['tag_name'] : $version,
            'name' => isset($data['name']) && is_string($data['name']) && $data['name'] !== '' ? $data['name'] : 'v' . $version,
            'body' => isset($data['body']) && is_string($data['body']) ? $data['body'] : '',
            'published_at' => isset($data['published_at']) && is_string($data['published_at']) ? $data['published_at'] : '',
            'package' => self::resolvePackageUrl($data),
            'homepage' => isset($data['html_url']) && is_string($data['html_url']) ? esc_url_raw($data['html_url']) : self::RELEASES_URL,
        );

        set_site_transient(self::CACHE_KEY, $release, self::CACHE_TTL);
        return $release;
    }

    /**
     * Builds a WordPress update object.
     *
     * @param array<string,mixed> $release Release data.
     */
    private static function buildUpdateObject(array $release): \stdClass
    {
        $object = new \stdClass();
        $object->id = self::RELEASES_URL;
        $object->slug = self::slug();
        $object->plugin = plugin_basename(\CHUYI_AI_RELAY_FILE);
        $object->new_version = (string) ($release['version'] ?? \CHUYI_AI_RELAY_VERSION);
        $object->url = (string) ($release['homepage'] ?? self::RELEASES_URL);
        $object->package = (string) ($release['package'] ?? '');
        $object->tested = '7.0';
        $object->requires = '6.9';
        $object->requires_php = '7.4';
        $object->last_updated = (string) ($release['published_at'] ?? '');
        $object->name = '初一 AI 中转';
        $object->author = '李初一';
        $object->homepage = self::RELEASES_URL;
        $object->icons = array(
            'svg' => \CHUYI_AI_RELAY_URL . 'assets/images/chuyi-relay.svg',
            'default' => \CHUYI_AI_RELAY_URL . 'assets/images/chuyi-relay.svg',
        );

        return $object;
    }

    /**
     * Returns local version metadata.
     *
     * @return array<string,mixed>
     */
    private static function currentRelease(): array
    {
        return array(
            'version' => \CHUYI_AI_RELAY_VERSION,
            'homepage' => self::RELEASES_URL,
            'package' => '',
            'body' => '',
            'published_at' => '',
        );
    }

    /**
     * Resolves a downloadable package from release assets or zipball.
     *
     * @param array<string,mixed> $data GitHub release payload.
     */
    private static function resolvePackageUrl(array $data): string
    {
        $assets = isset($data['assets']) && is_array($data['assets']) ? $data['assets'] : array();
        foreach ($assets as $asset) {
            if (!is_array($asset)) {
                continue;
            }
            $name = isset($asset['name']) && is_string($asset['name']) ? strtolower($asset['name']) : '';
            $url = isset($asset['browser_download_url']) && is_string($asset['browser_download_url']) ? $asset['browser_download_url'] : '';
            if ($url !== '' && substr($name, -4) === '.zip') {
                return esc_url_raw($url);
            }
        }

        return isset($data['zipball_url']) && is_string($data['zipball_url']) ? esc_url_raw($data['zipball_url']) : '';
    }

    /**
     * Normalizes v-prefixed tags to semver-like versions.
     */
    private static function normalizeVersion(string $version): string
    {
        $version = trim($version);
        $version = preg_replace('/^v/i', '', $version);
        return is_string($version) && preg_match('/^\d+(?:\.\d+){1,3}(?:[-+][0-9A-Za-z.-]+)?$/', $version) ? $version : '';
    }

    /**
     * Formats release notes for the WordPress modal.
     */
    private static function formatChangelog(string $body): string
    {
        $body = trim($body);
        if ($body === '') {
            return '暂无更新说明。';
        }

        return nl2br(esc_html($body));
    }

    /**
     * Returns WordPress plugin slug.
     */
    private static function slug(): string
    {
        return dirname(plugin_basename(\CHUYI_AI_RELAY_FILE));
    }
}