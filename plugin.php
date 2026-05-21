<?php
/**
 * Plugin Name: 初一中转
 * Plugin URI: https://github.com/DearLicy/chuyi-ai-relay
 * Description: 为 WordPress AI Client 增加自定义 OpenAI 协议中转接口，支持模型同步和生图能力声明。
 * Requires at least: 6.9
 * Requires PHP: 7.4
 * Version: 1.0.0
 * Author: 李初一
 * Author URI: https://github.com/DearLicy
 */

declare(strict_types=1);

namespace WordPress\ChuyiAiRelay;

use WordPress\AiClient\AiClient;
use WordPress\ChuyiAiRelay\Admin\SettingsPage;
use WordPress\ChuyiAiRelay\Provider\ChuyiRelayProvider;

if (!defined('ABSPATH')) {
    return;
}

define('CHUYI_AI_RELAY_FILE', __FILE__);
define('CHUYI_AI_RELAY_DIR', plugin_dir_path(__FILE__));
define('CHUYI_AI_RELAY_URL', plugin_dir_url(__FILE__));
define('CHUYI_AI_RELAY_VERSION', '1.0.0');

require_once __DIR__ . '/src/autoload.php';

function register_provider(): void
{
    if (!class_exists(AiClient::class)) {
        return;
    }

    $registry = AiClient::defaultRegistry();

    if ($registry->hasProvider(ChuyiRelayProvider::ID) || $registry->hasProvider(ChuyiRelayProvider::class)) {
        return;
    }

    $registry->registerProvider(ChuyiRelayProvider::class);
}
add_action('init', __NAMESPACE__ . '\\register_provider', 5);

function register_admin(): void
{
    SettingsPage::init();
}
add_action('plugins_loaded', __NAMESPACE__ . '\\register_admin');