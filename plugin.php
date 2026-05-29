<?php
/**
 * Plugin Name: 初一 AI 中转
 * Plugin URI: https://github.com/DearLicy/chuyi-ai-relay
 * Description: 为 WordPress AI Client 增加多中转、多协议模型池，支持 OpenAI-compatible 与 Anthropic Messages 接入。
 * Requires at least: 7.0
 * Requires PHP: 7.4
 * Version: 1.0.3
 * Author: 李初一
 * Author URI: https://github.com/DearLicy
 */

declare(strict_types=1);

namespace WordPress\ChuyiAiRelay;

use WordPress\AiClient\AiClient;
use WordPress\ChuyiAiRelay\Abilities\Registry as AbilitiesRegistry;
use WordPress\ChuyiAiRelay\Admin\ConnectorAds;
use WordPress\ChuyiAiRelay\Prompts\PromptOverrides;
use WordPress\ChuyiAiRelay\Provider\ChuyiRelayProvider;
use WordPress\ChuyiAiRelay\Update\GitHubReleaseUpdater;

if (!defined('ABSPATH')) {
    return;
}

define('CHUYI_AI_RELAY_FILE', __FILE__);
define('CHUYI_AI_RELAY_DIR', plugin_dir_path(__FILE__));
define('CHUYI_AI_RELAY_URL', plugin_dir_url(__FILE__));
define('CHUYI_AI_RELAY_VERSION', '1.0.3');

require_once __DIR__ . '/src/autoload.php';
require_once __DIR__ . '/src/Admin/SettingsPage.php';
require_once __DIR__ . '/src/Language/I18n.php';

GitHubReleaseUpdater::init();
PromptOverrides::init();
AbilitiesRegistry::init();

function register_provider(): void
{
    if (!class_exists(AiClient::class)) {
        return;
    }

    $registry = AiClient::defaultRegistry();
    foreach (Settings::getRegisterableSlots() as $slotId => $slot) {
        $providerClass = ChuyiRelayProvider::providerClassForSlot($slotId);
        $providerId = $providerClass::providerId();

        if ($registry->hasProvider($providerId) || $registry->hasProvider($providerClass)) {
            continue;
        }

        $registry->registerProvider($providerClass);
    }
}
add_action('init', __NAMESPACE__ . '\\register_provider', 5);

function apply_connector_favicons(\WP_Connector_Registry $connectorRegistry): void
{
    foreach (Settings::getRegisterableSlots() as $slotId => $slot) {
        $providerId = Settings::getProviderIdForSlot($slotId);
        if (!$connectorRegistry->is_registered($providerId)) {
            continue;
        }

        $connector = $connectorRegistry->unregister($providerId);
        if (!is_array($connector)) {
            continue;
        }

        $logoUrl = Settings::getLogoUrl($slotId);
        if ($logoUrl !== '') {
            $connector['logo_url'] = $logoUrl;
        } else {
            unset($connector['logo_url']);
        }

        $connectorRegistry->register($providerId, $connector);
    }
}
add_action('wp_connectors_init', __NAMESPACE__ . '\\apply_connector_favicons', 20);

function approve_own_connectors(): void
{
    $pluginBasename = plugin_basename(\CHUYI_AI_RELAY_FILE);
    $approvals = get_option('wpai_connector_approvals', array());
    if (!is_array($approvals)) {
        $approvals = array();
    }

    if (!isset($approvals[$pluginBasename]) || !is_array($approvals[$pluginBasename])) {
        $approvals[$pluginBasename] = array();
    }

    $changed = false;
    foreach (Settings::getRegisterableSlots() as $slotId => $slot) {
        $providerId = Settings::getProviderIdForSlot($slotId);
        if (empty($approvals[$pluginBasename][$providerId])) {
            $approvals[$pluginBasename][$providerId] = true;
            $changed = true;
        }
    }

    if ($changed) {
        update_option('wpai_connector_approvals', $approvals, false);
    }
}
add_action('init', __NAMESPACE__ . '\\approve_own_connectors', 20);

function log_relay_http_debug($response, string $context, string $class, array $parsedArgs, string $url): void
{
    if ($context !== 'response') {
        return;
    }

    $matched = false;
    foreach (Settings::getRegisterableSlots() as $slotId => $slot) {
        $baseUrl = Settings::getBaseUrl($slotId);
        if ($baseUrl !== '' && strpos($url, rtrim($baseUrl, '/') . '/') === 0) {
            $matched = true;
            break;
        }
    }

    if (!$matched) {
        return;
    }

    if (is_wp_error($response)) {
        error_log(sprintf(
            '[chuyi-ai-relay] http response target=%s status=wp_error error=%s',
            $url,
            $response->get_error_message()
        ));
        return;
    }

    $status = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);
    $preview = is_string($body) ? substr(preg_replace('/\s+/', ' ', $body) ?: $body, 0, 500) : '';
    error_log(sprintf(
        '[chuyi-ai-relay] http response target=%s status=%s body_preview=%s',
        $url,
        (string) $status,
        $preview
    ));
}
add_action('http_api_debug', __NAMESPACE__ . '\\log_relay_http_debug', 10, 5);

function register_admin(): void
{
    if (function_exists(__NAMESPACE__ . '\\Admin\\chuyi_ai_relay_register_admin')) {
        Admin\chuyi_ai_relay_register_admin();
    }

    ConnectorAds::init();
}
add_action('plugins_loaded', __NAMESPACE__ . '\\register_admin');
