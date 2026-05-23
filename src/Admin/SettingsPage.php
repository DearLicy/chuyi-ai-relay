<?php
/**
 * Native WordPress admin pages for 初一 AI 中转.
 *
 * @package WordPress\ChuyiAiRelay\Admin
 */

declare(strict_types=1);

namespace WordPress\ChuyiAiRelay\Admin;

use WordPress\ChuyiAiRelay\Prompts\DefaultPrompts;
use WordPress\ChuyiAiRelay\Prompts\PromptOverrides;
use WordPress\ChuyiAiRelay\Settings;
use WordPress\ChuyiAiRelay\Update\GitHubReleaseUpdater;

if (!defined('ABSPATH')) {
    return;
}

const CHUYI_AI_RELAY_MENU_SLUG = 'chuyi-ai-relay';
const CHUYI_AI_RELAY_SETTINGS_SLUG = 'chuyi-ai-relay-settings';
const CHUYI_AI_RELAY_RELAYS_SLUG = 'chuyi-ai-relay-relays';
const CHUYI_AI_RELAY_TEST_SLUG = 'chuyi-ai-relay-test';
const CHUYI_AI_RELAY_PROMPTS_SLUG = 'chuyi-ai-relay-prompts';
const CHUYI_AI_RELAY_HELP_SLUG = 'chuyi-ai-relay-help';
const CHUYI_AI_RELAY_REST_NAMESPACE = 'chuyi-ai-relay/v1';

function chuyi_ai_relay_register_admin(): void
{
    add_action('admin_menu', __NAMESPACE__ . '\\chuyi_ai_relay_register_menu');
    add_action('admin_enqueue_scripts', __NAMESPACE__ . '\\chuyi_ai_relay_enqueue_assets');
    add_action('admin_notices', __NAMESPACE__ . '\\chuyi_ai_relay_render_admin_notices');
    add_action('admin_post_chuyi_ai_relay_check_update', __NAMESPACE__ . '\\chuyi_ai_relay_handle_check_update');
    add_action('admin_post_chuyi_ai_relay_save_prompt_override', __NAMESPACE__ . '\\chuyi_ai_relay_handle_save_prompt_override');
    add_action('rest_api_init', __NAMESPACE__ . '\\chuyi_ai_relay_register_rest_routes');
    add_filter('plugin_action_links_' . plugin_basename(\CHUYI_AI_RELAY_FILE), __NAMESPACE__ . '\\chuyi_ai_relay_add_plugin_action_links');
}

function chuyi_ai_relay_add_plugin_action_links(array $links): array
{
    $checkUpdateUrl = wp_nonce_url(
        admin_url('admin-post.php?action=chuyi_ai_relay_check_update'),
        'chuyi_ai_relay_check_update'
    );

        array_unshift(
            $links,
        '<a href="' . esc_url(admin_url('admin.php?page=' . CHUYI_AI_RELAY_HELP_SLUG)) . '">' . esc_html__('插件设置', 'chuyi-ai-relay') . '</a>',
        '<a href="' . esc_url($checkUpdateUrl) . '">' . esc_html__('检查更新', 'chuyi-ai-relay') . '</a>'
        );

        return $links;
    }

function chuyi_ai_relay_register_menu(): void
{
    add_menu_page(
        '初一 AI 中转 · 使用说明',
        '初一 AI 中转',
        'manage_options',
        CHUYI_AI_RELAY_HELP_SLUG,
        __NAMESPACE__ . '\\chuyi_ai_relay_render_help_page',
        'dashicons-cloud-saved',
        58
    );

    add_submenu_page(
        CHUYI_AI_RELAY_HELP_SLUG,
        '初一 AI 中转 · 使用说明',
        '使用说明',
        'manage_options',
        CHUYI_AI_RELAY_HELP_SLUG,
        __NAMESPACE__ . '\\chuyi_ai_relay_render_help_page'
    );

    add_submenu_page(
        CHUYI_AI_RELAY_HELP_SLUG,
        '初一 AI 中转 · 接入设置',
        '接入设置',
        'manage_options',
        CHUYI_AI_RELAY_SETTINGS_SLUG,
        __NAMESPACE__ . '\\chuyi_ai_relay_render_settings_page'
    );

    add_submenu_page(
        CHUYI_AI_RELAY_HELP_SLUG,
        '初一 AI 中转 · 中转管理',
        '中转管理',
        'manage_options',
        CHUYI_AI_RELAY_RELAYS_SLUG,
        __NAMESPACE__ . '\\chuyi_ai_relay_render_relays_page'
    );

    add_submenu_page(
        CHUYI_AI_RELAY_HELP_SLUG,
        '初一 AI 中转 · 模型测试',
        '模型测试',
        'manage_options',
        CHUYI_AI_RELAY_TEST_SLUG,
        __NAMESPACE__ . '\\chuyi_ai_relay_render_test_page'
    );

    add_submenu_page(
        CHUYI_AI_RELAY_HELP_SLUG,
        '初一 AI 中转 · 提示词管理',
        '提示词管理',
        'manage_options',
        CHUYI_AI_RELAY_PROMPTS_SLUG,
        __NAMESPACE__ . '\\chuyi_ai_relay_render_prompts_page'
    );
}

function chuyi_ai_relay_render_settings_page(): void
{
    chuyi_ai_relay_render_app_mount('settings', '接入设置');
}

function chuyi_ai_relay_render_relays_page(): void
{
    chuyi_ai_relay_render_app_mount('relays', '中转管理');
}

function chuyi_ai_relay_render_test_page(): void
{
    chuyi_ai_relay_render_app_mount('test', '模型测试');
}

function chuyi_ai_relay_render_prompts_page(): void
{
    chuyi_ai_relay_render_app_mount('prompts', '提示词管理');
}

function chuyi_ai_relay_render_help_page(): void
{
    chuyi_ai_relay_render_app_mount('help', '使用说明');
}

function chuyi_ai_relay_render_app_mount(string $page, string $title): void
{
    echo '<script>document.body&&document.body.classList&&document.body.classList.add("chuyi-ai-relay-admin-page");</script>';
    echo '<div class="wrap chuyi-ai-relay-admin-wrap">';
    echo '<div id="chuyi-ai-relay-admin-root" data-page="' . esc_attr($page) . '" data-title="' . esc_attr($title) . '"></div>';
    echo '</div>';
}

function chuyi_ai_relay_enqueue_assets(string $hookSuffix): void
{
    if (!chuyi_ai_relay_is_own_admin_screen($hookSuffix)) {
        return;
    }

    wp_enqueue_style('wp-components');
    wp_register_style('chuyi-ai-relay-admin-settings', false, array('wp-components'), \CHUYI_AI_RELAY_VERSION);
    wp_enqueue_style('chuyi-ai-relay-admin-settings');
    wp_add_inline_style('chuyi-ai-relay-admin-settings', chuyi_ai_relay_get_inline_styles());

    wp_enqueue_script(
        'chuyi-ai-relay-admin-settings',
        \CHUYI_AI_RELAY_URL . 'assets/js/admin-settings.js',
        array('wp-element', 'wp-components', 'wp-api-fetch'),
        \CHUYI_AI_RELAY_VERSION,
        true
    );

    wp_localize_script(
        'chuyi-ai-relay-admin-settings',
        'chuyiAiRelayAdmin',
                array(
            'restUrl' => esc_url_raw(rest_url(CHUYI_AI_RELAY_REST_NAMESPACE)),
            'nonce'   => wp_create_nonce('wp_rest'),
            'pages'   => array(
                'settings'  => admin_url('admin.php?page=' . CHUYI_AI_RELAY_SETTINGS_SLUG),
                'relays'    => admin_url('admin.php?page=' . CHUYI_AI_RELAY_RELAYS_SLUG),
                'test'      => admin_url('admin.php?page=' . CHUYI_AI_RELAY_TEST_SLUG),
                'prompts'   => admin_url('admin.php?page=' . CHUYI_AI_RELAY_PROMPTS_SLUG),
                'help'      => admin_url('admin.php?page=' . CHUYI_AI_RELAY_HELP_SLUG),
                'connectors' => admin_url('options-connectors.php'),
            ),
            'assets'  => array(
                'rewardWechat' => \CHUYI_AI_RELAY_URL . 'assets/images/reward-wechat.jpg',
                'rewardAlipay' => \CHUYI_AI_RELAY_URL . 'assets/images/reward-alipay.jpg',
            ),
        )
    );
}

function chuyi_ai_relay_is_own_admin_screen(string $hookSuffix): bool
{
    return strpos($hookSuffix, CHUYI_AI_RELAY_SETTINGS_SLUG) !== false
        || strpos($hookSuffix, CHUYI_AI_RELAY_RELAYS_SLUG) !== false
        || strpos($hookSuffix, CHUYI_AI_RELAY_TEST_SLUG) !== false
        || strpos($hookSuffix, CHUYI_AI_RELAY_PROMPTS_SLUG) !== false
        || strpos($hookSuffix, CHUYI_AI_RELAY_HELP_SLUG) !== false;
}

function chuyi_ai_relay_handle_check_update(): void
{
    if (!current_user_can('update_plugins')) {
        wp_die(esc_html__('权限不足。', 'chuyi-ai-relay'));
    }

    check_admin_referer('chuyi_ai_relay_check_update');

    $update = GitHubReleaseUpdater::refreshAvailableUpdate();
    $status = $update !== null ? 'available' : 'latest';

    wp_safe_redirect(add_query_arg(
            array(
            'page' => CHUYI_AI_RELAY_HELP_SLUG,
            'chuyi_ai_relay_update_checked' => $status,
        ),
        admin_url('admin.php')
    ));
    exit;
}

function chuyi_ai_relay_handle_save_prompt_override(): void
{
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('权限不足。', 'chuyi-ai-relay'));
    }

    check_admin_referer('chuyi_ai_relay_save_prompt_override');

    $ability = isset($_POST['ability']) && is_string($_POST['ability'])
        ? sanitize_text_field(wp_unslash($_POST['ability']))
        : '';

    $redirectUrl = add_query_arg(
        array('page' => CHUYI_AI_RELAY_PROMPTS_SLUG),
        admin_url('admin.php')
    );

    if (isset($_POST['reset_prompt'])) {
        PromptOverrides::delete($ability);
        wp_safe_redirect(add_query_arg('chuyi_prompt_status', 'reset', $redirectUrl));
        exit;
    }

    $instruction = isset($_POST['instruction']) && is_string($_POST['instruction'])
        ? trim(wp_unslash($_POST['instruction']))
        : '';
    if ($instruction === '') {
        wp_safe_redirect(add_query_arg('chuyi_prompt_status', 'error', $redirectUrl));
        exit;
    }

    $mode = isset($_POST['mode']) && is_string($_POST['mode']) ? sanitize_key(wp_unslash($_POST['mode'])) : PromptOverrides::MODE_REPLACE;
    $enabled = !empty($_POST['enabled']);

    $result = PromptOverrides::save(
        $ability,
        array(
            'instruction' => $instruction,
            'mode'        => $mode,
            'enabled'     => $enabled,
        )
    );

    $status = is_wp_error($result) ? 'error' : 'saved';
    wp_safe_redirect(add_query_arg('chuyi_prompt_status', $status, $redirectUrl));
    exit;
}

function chuyi_ai_relay_render_admin_notices(): void
{
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if (!$screen || empty($screen->id) || strpos((string) $screen->id, 'chuyi-ai-relay') === false) {
        return;
    }

    $checkedStatus = isset($_GET['chuyi_ai_relay_update_checked']) && is_string($_GET['chuyi_ai_relay_update_checked'])
        ? sanitize_key(wp_unslash($_GET['chuyi_ai_relay_update_checked']))
        : '';
    if ($checkedStatus === 'latest') {
        echo '<div class="notice notice-info is-dismissible">';
        echo '<p>初一 AI 中转已完成更新检测，当前已是最新版本。</p>';
        echo '</div>';
    }

    if (chuyi_ai_relay_needs_ai_plugin_approval()) {
        echo '<div class="notice notice-warning is-dismissible">';
        echo '<h2 style="color:#b26200;"><span class="dashicons dashicons-warning" style="vertical-align:middle;"></span> 请完成官方 AI 插件连接器审批</h2>';
        echo '<p>官方 AI 插件 <code>ai/ai.php</code> 需要在 Connector Approvals 中手动放行后，文章编辑页的 AI 功能才能调用初一 AI 中转。</p>';
        echo '<p><a class="button button-primary" style="margin:2px;" href="' . esc_url(admin_url('options-connectors.php')) . '">打开连接器审批</a></p>';
        echo '</div>';
    }

    $update = GitHubReleaseUpdater::getAvailableUpdate();
    if ($update !== null) {
        $version = isset($update['version']) && is_string($update['version']) ? $update['version'] : '';
        $homepage = isset($update['homepage']) && is_string($update['homepage']) ? $update['homepage'] : 'https://github.com/DearLicy/chuyi-ai-relay/releases';

        echo '<div class="notice notice-success is-dismissible">';
        echo '<h2 style="color:#2271b1;"><span class="dashicons dashicons-update" style="vertical-align:middle;"></span> 初一 AI 中转检测到新版本</h2>';
        echo '<p>当前版本为 <code>' . esc_html(\CHUYI_AI_RELAY_VERSION) . '</code>，检测到可更新版本 <code>' . esc_html($version) . '</code>。</p>';
        echo '<p><a class="button button-primary" style="margin:2px;" href="' . esc_url(admin_url('plugins.php')) . '">前往插件更新</a><a target="_blank" rel="noreferrer" class="button" style="margin:2px;" href="' . esc_url($homepage) . '">查看更新说明</a></p>';
        echo '</div>';
    }
}

function chuyi_ai_relay_needs_ai_plugin_approval(): bool
{
    if (!function_exists('is_plugin_active')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    if (!is_plugin_active('ai/ai.php')) {
        return false;
    }

    $slots = Settings::getRegisterableSlots();
    if (empty($slots)) {
        return false;
    }

    $approvals = get_option('wpai_connector_approvals', array());
    $aiApprovals = is_array($approvals) && isset($approvals['ai/ai.php']) && is_array($approvals['ai/ai.php'])
        ? $approvals['ai/ai.php']
        : array();

    foreach ($slots as $slotId => $slot) {
        $providerId = Settings::getProviderIdForSlot($slotId);
        if (empty($aiApprovals[$providerId])) {
            return true;
        }
    }

    return false;
}

function chuyi_ai_relay_register_rest_routes(): void
{
    $permission = __NAMESPACE__ . '\\chuyi_ai_relay_rest_permission';

    register_rest_route(
        CHUYI_AI_RELAY_REST_NAMESPACE,
        '/settings',
            array(
            array(
                'methods'             => 'GET',
                'callback'            => __NAMESPACE__ . '\\chuyi_ai_relay_rest_get_settings',
                'permission_callback' => $permission,
            ),
            array(
                'methods'             => 'POST',
                'callback'            => __NAMESPACE__ . '\\chuyi_ai_relay_rest_save_settings',
                'permission_callback' => $permission,
                ),
            )
        );

    register_rest_route(CHUYI_AI_RELAY_REST_NAMESPACE, '/fetch-models', array(
        'methods'             => 'POST',
        'callback'            => __NAMESPACE__ . '\\chuyi_ai_relay_rest_fetch_models',
        'permission_callback' => $permission,
    ));

    register_rest_route(CHUYI_AI_RELAY_REST_NAMESPACE, '/test-connection', array(
        'methods'             => 'POST',
        'callback'            => __NAMESPACE__ . '\\chuyi_ai_relay_rest_test_connection',
        'permission_callback' => $permission,
    ));

    register_rest_route(CHUYI_AI_RELAY_REST_NAMESPACE, '/test-generation', array(
        'methods'             => 'POST',
        'callback'            => __NAMESPACE__ . '\\chuyi_ai_relay_rest_test_generation',
        'permission_callback' => $permission,
    ));

    register_rest_route(CHUYI_AI_RELAY_REST_NAMESPACE, '/prompts', array(
        'methods'             => 'GET',
        'callback'            => __NAMESPACE__ . '\\chuyi_ai_relay_rest_get_prompts',
        'permission_callback' => $permission,
    ));

    register_rest_route(CHUYI_AI_RELAY_REST_NAMESPACE, '/prompts/(?P<ability>[a-z0-9\/_-]+)', array(
        array(
            'methods'             => 'POST',
            'callback'            => __NAMESPACE__ . '\\chuyi_ai_relay_rest_save_prompt',
            'permission_callback' => $permission,
        ),
        array(
            'methods'             => 'DELETE',
            'callback'            => __NAMESPACE__ . '\\chuyi_ai_relay_rest_reset_prompt',
            'permission_callback' => $permission,
        ),
    ));
}

function chuyi_ai_relay_rest_permission(): bool
{
    return current_user_can('manage_options');
}

function chuyi_ai_relay_rest_get_settings(): \WP_REST_Response
{
    return rest_ensure_response(chuyi_ai_relay_get_admin_payload());
}

function chuyi_ai_relay_rest_save_settings(\WP_REST_Request $request): \WP_REST_Response
{
    $params = $request->get_json_params();
    $relays = isset($params['relays']) && is_array($params['relays']) ? $params['relays'] : array();

    Settings::saveRelays(Settings::normalizeRelays($relays));

    return rest_ensure_response(chuyi_ai_relay_get_admin_payload(array(
        'notice' => array(
            'status'  => 'success',
            'message' => '设置已保存。',
        ),
    )));
}

function chuyi_ai_relay_rest_fetch_models(\WP_REST_Request $request): \WP_REST_Response
{
    $slotId = sanitize_key((string) $request->get_param('slotId'));
    $result = chuyi_ai_relay_fetch_models_from_relay($slotId);

    if (!$result['ok']) {
        return new \WP_REST_Response(array('message' => $result['message']), 400);
    }

    Settings::saveFetchedModels($result['models'], $slotId);

    return rest_ensure_response(chuyi_ai_relay_get_admin_payload(array(
        'notice' => array(
            'status'  => 'success',
            'message' => sprintf('已获取并保存 %d 个模型。', count($result['models'])),
        ),
    )));
}

function chuyi_ai_relay_rest_test_connection(\WP_REST_Request $request): \WP_REST_Response
{
    $slotId = sanitize_key((string) $request->get_param('slotId'));
    $result = chuyi_ai_relay_measure_connection_latency($slotId);

    Settings::updateSlot($slotId, array(
        'status' => array(
            'latency' => $result['latency'],
            'ok'      => $result['ok'],
            'message' => $result['message'],
            'checked' => gmdate('c'),
        ),
    ));

    $payload = chuyi_ai_relay_get_admin_payload();
    $response = array(
        'message' => $result['message'],
        'latency' => $result['latency'],
        'relays'  => $payload['relays'],
    );

    if (!$result['ok']) {
        return new \WP_REST_Response($response, 400);
    }

    return rest_ensure_response($response);
}

function chuyi_ai_relay_rest_test_generation(\WP_REST_Request $request): \WP_REST_Response
{
    $slotId = sanitize_key((string) $request->get_param('slotId'));
    $model = sanitize_text_field((string) $request->get_param('model'));
    $type = sanitize_key((string) $request->get_param('type'));
    $prompt = sanitize_textarea_field((string) $request->get_param('prompt'));

    if ($type !== 'image') {
        $type = 'text';
    }
    if ($prompt === '') {
        $prompt = $type === 'image' ? '生成一张极简风格的蓝色圆形图标' : '请回复：初一 AI 中转文本测试成功';
    }

    $result = chuyi_ai_relay_request_generation($slotId, $model, $prompt, $type === 'image');
    if (!$result['ok']) {
        return new \WP_REST_Response(array('message' => $result['message']), 400);
    }

    return rest_ensure_response(array('message' => $result['message']));
}

function chuyi_ai_relay_rest_get_prompts(): \WP_REST_Response
{
    return rest_ensure_response(array(
        'prompts' => DefaultPrompts::managed(),
    ));
}

function chuyi_ai_relay_rest_save_prompt(\WP_REST_Request $request): \WP_REST_Response
{
    $ability = sanitize_text_field((string) $request->get_param('ability'));
    $params = $request->get_json_params();
    if (!is_array($params)) {
        $params = array();
    }

    $instruction = isset($params['instruction']) && is_string($params['instruction'])
        ? trim($params['instruction'])
        : '';
    if ($instruction === '') {
        return new \WP_REST_Response(array('message' => '提示词不能为空。'), 400);
    }

    $result = PromptOverrides::save($ability, array(
        'instruction' => $instruction,
        'mode'        => isset($params['mode']) && is_string($params['mode']) ? sanitize_key($params['mode']) : PromptOverrides::MODE_REPLACE,
        'enabled'     => !empty($params['enabled']),
    ));

    if (is_wp_error($result)) {
        return new \WP_REST_Response(array('message' => $result->get_error_message()), 400);
    }

    return rest_ensure_response(array(
        'notice'  => array('status' => 'success', 'message' => '提示词覆盖已保存。'),
        'prompts' => DefaultPrompts::managed(),
    ));
}

function chuyi_ai_relay_rest_reset_prompt(\WP_REST_Request $request): \WP_REST_Response
{
    $ability = sanitize_text_field((string) $request->get_param('ability'));
    PromptOverrides::delete($ability);

    return rest_ensure_response(array(
        'notice'  => array('status' => 'success', 'message' => '提示词覆盖已恢复默认。'),
        'prompts' => DefaultPrompts::managed(),
    ));
}

function chuyi_ai_relay_get_admin_payload(array $extra = array()): array
{
    $relays = Settings::getRelays();
    $slots = Settings::getSlots();
    $enabled = 0;
    $models = 0;

    foreach ($slots as $slot) {
        if (!empty($slot['enabled']) && !empty($slot['site_url'])) {
            $enabled++;
        }
        $models += isset($slot['models']) && is_array($slot['models']) ? count($slot['models']) : 0;
    }

    return array_merge(array(
        'relays' => $relays,
        'stats'  => array(
            'totalRelays'   => count($relays),
            'enabledRelays' => $enabled,
            'totalModels'   => $models,
        ),
        'modes'  => array(
            array('label' => 'OpenAI Compatible', 'value' => Settings::MODE_OPENAI),
            array('label' => 'Anthropic Messages', 'value' => Settings::MODE_ANTHROPIC),
        ),
        'imageEndpoints' => array(
            array('label' => '图片接口 /v1/images/generations', 'value' => Settings::IMAGE_ENDPOINT_IMAGE),
            array('label' => '对话接口 /v1/chat/completions', 'value' => Settings::IMAGE_ENDPOINT_CHAT),
            array('label' => '自动尝试：先图片接口，再对话接口', 'value' => Settings::IMAGE_ENDPOINT_AUTO),
        ),
        'capabilities' => array(
            array('label' => '文本', 'value' => 'text_generation'),
            array('label' => '视觉', 'value' => 'vision'),
            array('label' => '生图', 'value' => 'image_generation'),
        ),
    ), $extra);
}

function chuyi_ai_relay_fetch_models_from_relay(string $slotId): array
{
    $baseUrl = Settings::getBaseUrl($slotId);
    $apiKey = Settings::getApiKey($slotId);

        if ($baseUrl === '') {
        return array('ok' => false, 'message' => '请先保存中转站地址。', 'models' => array());
        }
        if ($apiKey === '') {
        return array('ok' => false, 'message' => '请先在 Connectors 中保存此 provider 的 API Key。', 'models' => array());
        }

    $response = wp_remote_get(Settings::urlForSlot($slotId, 'models'), array(
                'timeout' => 30,
        'headers' => chuyi_ai_relay_get_request_headers($slotId, $apiKey),
    ));

        if (is_wp_error($response)) {
            return array('ok' => false, 'message' => $response->get_error_message(), 'models' => array());
        }

        $statusCode = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        if ($statusCode < 200 || $statusCode >= 300) {
        return array('ok' => false, 'message' => 'HTTP ' . $statusCode . '：' . wp_strip_all_tags($body), 'models' => array());
        }

        $data = json_decode($body, true);
    $models = is_array($data) && isset($data['data']) && is_array($data['data'])
        ? chuyi_ai_relay_normalize_models_from_response($data['data'])
        : array();

        if (empty($models)) {
        return array('ok' => false, 'message' => '接口没有返回可用模型。', 'models' => array());
    }

    return array('ok' => true, 'message' => 'ok', 'models' => $models);
}

function chuyi_ai_relay_measure_connection_latency(string $slotId): array
{
    $baseUrl = Settings::getBaseUrl($slotId);
    $apiKey = Settings::getApiKey($slotId);

    if ($baseUrl === '') {
        return array('ok' => false, 'message' => '请先保存中转站地址。', 'latency' => 0);
    }
    if ($apiKey === '') {
        return array('ok' => false, 'message' => '请先在 Connectors 中保存此 provider 的 API Key。', 'latency' => 0);
    }

    $start = microtime(true);
    $response = wp_remote_get(Settings::urlForSlot($slotId, 'models'), array(
        'timeout' => 20,
        'headers' => chuyi_ai_relay_get_request_headers($slotId, $apiKey),
    ));
    $latency = (int) round((microtime(true) - $start) * 1000);

    if (is_wp_error($response)) {
        return array('ok' => false, 'message' => '连通失败 · ' . $latency . 'ms · ' . $response->get_error_message(), 'latency' => $latency);
    }

    $statusCode = (int) wp_remote_retrieve_response_code($response);
    $body = (string) wp_remote_retrieve_body($response);
    if ($statusCode < 200 || $statusCode >= 300) {
        return array('ok' => false, 'message' => '连通失败 · ' . $latency . 'ms · HTTP ' . $statusCode . '：' . wp_strip_all_tags($body), 'latency' => $latency);
    }

    return array('ok' => true, 'message' => '连通成功 · ' . $latency . 'ms', 'latency' => $latency);
}

function chuyi_ai_relay_request_generation(string $slotId, string $model, string $prompt, bool $image): array
{
    $apiKey = Settings::getApiKey($slotId);
    if ($model === '') {
        return array('ok' => false, 'message' => '请选择模型。');
    }
        if ($apiKey === '') {
        return array('ok' => false, 'message' => '请先在 Connectors 中保存此 provider 的 API Key。');
    }

    if ($image) {
        if (Settings::getMode($slotId) === Settings::MODE_ANTHROPIC) {
            return array('ok' => false, 'message' => 'Anthropic Messages 模式不支持生图测试。');
        }

        $imageEndpoint = Settings::getImageEndpoint($slotId);
        if ($imageEndpoint === Settings::IMAGE_ENDPOINT_CHAT) {
            $chatResult = chuyi_ai_relay_request_chat_generation($slotId, $apiKey, $model, $prompt, true);
            $chatResult['message'] = trim($chatResult['message']) ?: '对话接口返回成功，但未提取到 base64 图片内容。';
            return $chatResult;
        }

        $imageResult = chuyi_ai_relay_request_image_generation($slotId, $apiKey, $model, $prompt);
        if ($imageEndpoint === Settings::IMAGE_ENDPOINT_IMAGE || $imageResult['ok']) {
            return $imageResult;
        }

        $chatResult = chuyi_ai_relay_request_chat_generation($slotId, $apiKey, $model, $prompt, true);
        if ($chatResult['ok']) {
            $chatResult['message'] = trim($chatResult['message']) ?: '对话接口返回成功，但未提取到 base64 图片内容。';
            return $chatResult;
        }

        return array(
            'ok' => false,
            'message' => '图片接口生图失败：' . $imageResult['message'] . "\n" . '对话接口生图失败：' . $chatResult['message'],
        );
    }

    if (Settings::getMode($slotId) === Settings::MODE_ANTHROPIC) {
        return chuyi_ai_relay_request_anthropic_generation($slotId, $apiKey, $model, $prompt);
    }

    return chuyi_ai_relay_request_chat_generation($slotId, $apiKey, $model, $prompt, false);
}

function chuyi_ai_relay_request_image_generation(string $slotId, string $apiKey, string $model, string $prompt): array
{
    $payload = array(
        'model' => $model,
        'prompt' => $prompt,
    );

    $result = chuyi_ai_relay_post_generation_request($slotId, 'images/generations', $apiKey, $payload, 90);
    if (!$result['ok']) {
        return $result;
    }

    $message = chuyi_ai_relay_extract_image_generation_message($result['body']);
    if ($message === '') {
        return array('ok' => false, 'message' => '标准生图接口返回成功，但未提取到图片。');
    }

    return array('ok' => true, 'message' => $message);
}

function chuyi_ai_relay_request_chat_generation(string $slotId, string $apiKey, string $model, string $prompt, bool $image): array
{
    $payload = array(
        'model' => $model,
        'messages' => array(array('role' => 'user', 'content' => $prompt)),
    );
    if ($image) {
        $payload['modalities'] = array('image');
    }

    $result = chuyi_ai_relay_post_generation_request($slotId, 'chat/completions', $apiKey, $payload, 60);
    if (!$result['ok']) {
        return $result;
    }

    return array('ok' => true, 'message' => chuyi_ai_relay_extract_generation_message($slotId, $result['body'], $image));
}

function chuyi_ai_relay_request_anthropic_generation(string $slotId, string $apiKey, string $model, string $prompt): array
{
    $payload = array(
        'model' => $model,
        'max_tokens' => 512,
        'messages' => array(array('role' => 'user', 'content' => $prompt)),
    );

    $result = chuyi_ai_relay_post_generation_request($slotId, 'messages', $apiKey, $payload, 60);
    if (!$result['ok']) {
        return $result;
    }

    return array('ok' => true, 'message' => chuyi_ai_relay_extract_generation_message($slotId, $result['body']));
}

function chuyi_ai_relay_post_generation_request(string $slotId, string $path, string $apiKey, array $payload, int $timeout): array
{
    $response = wp_remote_post(Settings::urlForSlot($slotId, $path), array(
        'timeout' => $timeout,
        'headers' => array_merge(array('Content-Type' => 'application/json'), chuyi_ai_relay_get_request_headers($slotId, $apiKey)),
        'body' => wp_json_encode($payload),
    ));

    if (is_wp_error($response)) {
        return array('ok' => false, 'message' => $response->get_error_message(), 'body' => '');
    }

    $statusCode = (int) wp_remote_retrieve_response_code($response);
    $body = (string) wp_remote_retrieve_body($response);
    if ($statusCode < 200 || $statusCode >= 300) {
        return array('ok' => false, 'message' => 'HTTP ' . $statusCode . '：' . wp_strip_all_tags($body), 'body' => $body);
    }

    return array('ok' => true, 'message' => '请求成功。', 'body' => $body);
}

function chuyi_ai_relay_get_request_headers(string $slotId, string $apiKey): array
{
    if (Settings::getMode($slotId) === Settings::MODE_ANTHROPIC) {
        return array(
            'Accept' => 'application/json',
            'x-api-key' => $apiKey,
            'anthropic-version' => '2023-06-01',
        );
    }

    return array(
        'Accept' => 'application/json',
        'Authorization' => 'Bearer ' . $apiKey,
    );
}

function chuyi_ai_relay_normalize_models_from_response(array $items): array
{
    $models = array();
    $seen = array();

    foreach ($items as $item) {
        if (!is_array($item) || empty($item['id']) || !is_string($item['id'])) {
                continue;
            }

        $id = sanitize_text_field($item['id']);
        if ($id === '' || isset($seen[$id])) {
                continue;
            }

        $name = isset($item['name']) && is_string($item['name']) && $item['name'] !== ''
            ? sanitize_text_field($item['name'])
            : (isset($item['display_name']) && is_string($item['display_name']) && $item['display_name'] !== '' ? sanitize_text_field($item['display_name']) : $id);

        $models[] = array(
            'id' => $id,
            'name' => $name,
        );
        $seen[$id] = true;
    }

    usort($models, static function (array $a, array $b): int {
        return strnatcasecmp($a['id'], $b['id']);
    });

    return $models;
}

function chuyi_ai_relay_extract_image_generation_message(string $body): string
{
    $data = json_decode($body, true);
    if (!is_array($data)) {
        return '';
    }

    $items = isset($data['data']) && is_array($data['data']) ? $data['data'] : array();
    if (empty($items) && isset($data['image_urls']) && is_array($data['image_urls'])) {
        foreach ($data['image_urls'] as $url) {
            if (is_string($url) && $url !== '') {
                $items[] = array('url' => $url);
            }
        }
    }

    $lines = array();
    foreach ($items as $index => $item) {
        if (!is_array($item)) {
            continue;
        }

        $alt = isset($item['revised_prompt']) && is_string($item['revised_prompt']) && $item['revised_prompt'] !== ''
            ? $item['revised_prompt']
            : 'generated image ' . ($index + 1);
        $alt = str_replace(array('[', ']'), '', wp_strip_all_tags($alt));

        if (isset($item['b64_json']) && is_string($item['b64_json']) && trim($item['b64_json']) !== '') {
            $mime = isset($item['mime_type']) && is_string($item['mime_type']) && preg_match('#^image/[a-z0-9.+-]+$#i', $item['mime_type'])
                ? strtolower($item['mime_type'])
                : 'image/png';
            $lines[] = '![' . $alt . '](data:' . $mime . ';base64,' . trim($item['b64_json']) . ')';
            continue;
        }

        if (isset($item['url']) && is_string($item['url']) && $item['url'] !== '') {
            $lines[] = '![' . $alt . '](' . esc_url_raw($item['url']) . ')';
        }
    }

    return trim(implode("\n\n", $lines));
}

function chuyi_ai_relay_extract_generation_message(string $slotId, string $body, bool $image = false): string
{
    $data = json_decode($body, true);
    if (!is_array($data)) {
        return $image ? chuyi_ai_relay_extract_markdown_base64_images($body) : wp_strip_all_tags($body);
    }

    if (Settings::getMode($slotId) === Settings::MODE_ANTHROPIC && isset($data['content']) && is_array($data['content'])) {
        $parts = array();
        foreach ($data['content'] as $part) {
            if (is_array($part) && isset($part['text']) && is_string($part['text'])) {
                $parts[] = $part['text'];
            }
        }
        $message = trim(implode("\n", $parts));
        return $image ? chuyi_ai_relay_extract_markdown_base64_images($message) : ($message ?: 'Anthropic 请求成功。');
    }

    if (isset($data['choices'][0]['message']['content']) && is_string($data['choices'][0]['message']['content'])) {
        $message = $data['choices'][0]['message']['content'];
        return $image ? chuyi_ai_relay_extract_markdown_base64_images($message) : $message;
    }

    $message = wp_json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '请求成功。';
    return $image ? chuyi_ai_relay_extract_markdown_base64_images($message) : $message;
}

function chuyi_ai_relay_extract_markdown_base64_images(string $text): string
{
    $text = trim($text);
    if ($text === '') {
        return '';
    }

    $lines = array();
    if (preg_match_all('/!\[([^\]]*)\]\(\s*(data:image\/[a-z0-9.+-]+;base64,[^)\s]+)\s*\)/i', $text, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $alt = str_replace(array('[', ']'), '', wp_strip_all_tags($match[1] !== '' ? $match[1] : 'generated image'));
            $lines[] = '![' . $alt . '](' . preg_replace('/\s+/', '', $match[2]) . ')';
        }
    }

    if (empty($lines) && preg_match_all('#data:image/[a-z0-9.+-]+;base64,[a-z0-9+/=\r\n]+#i', $text, $matches)) {
        foreach ($matches[0] as $index => $dataUri) {
            $lines[] = '![generated image ' . ($index + 1) . '](' . preg_replace('/\s+/', '', $dataUri) . ')';
        }
    }

    return trim(implode("\n\n", array_values(array_unique($lines))));
}

function chuyi_ai_relay_get_inline_styles(): string
{
    return '
        body.chuyi-ai-relay-admin-page,body.chuyi-ai-relay-admin-page #wpwrap,body.chuyi-ai-relay-admin-page #wpcontent,body.chuyi-ai-relay-admin-page #wpbody-content{background:#fff}
        body.chuyi-ai-relay-admin-page #wpcontent{padding-left:0}
        body.chuyi-ai-relay-admin-page #wpfooter{display:none}
        .chuyi-ai-relay-admin-wrap{margin:0;padding:0;max-width:none}
        .chuyi-ai-relay-app{width:100%;min-height:calc(100vh - 32px);background:#fff;color:#1e1e1e}
        .chuyi-ai-relay-app--loading{display:grid;place-items:center;min-height:360px}
        .chuyi-ai-relay-loading{display:inline-flex;align-items:center;gap:10px;color:#646970;font-size:13px}
        .chuyi-ai-relay-page-head{background:#fff;border-bottom:1px solid #dcdcde;margin:0 0 32px;padding:0 32px}
        .chuyi-ai-relay-page-head__inner{display:flex;align-items:flex-start;justify-content:space-between;gap:24px;margin:0 auto;max-width:1240px;padding:32px 0 20px}
        .chuyi-ai-relay-page-head__copy{max-width:720px}
        .chuyi-ai-relay-page-head__eyebrow{display:block;margin:0 0 8px;color:#646970;font-size:11px;font-weight:600;letter-spacing:.08em;line-height:1.2;text-transform:uppercase}
        .chuyi-ai-relay-page-head h1{margin:0 0 8px;color:#1e1e1e;font-size:32px;font-weight:500;line-height:1.2}
        .chuyi-ai-relay-page-head p{margin:0;color:#646970;font-size:14px;line-height:1.6}
        .chuyi-ai-relay-page-head__actions{display:flex;gap:8px;justify-content:flex-end;padding-top:4px;white-space:nowrap}
        .chuyi-ai-relay-tabs{display:flex;gap:0;margin:0 auto;max-width:1240px;overflow-x:auto}
        .chuyi-ai-relay-tabs a{border-bottom:3px solid transparent;color:#50575e;display:block;font-size:14px;font-weight:500;margin:0 24px 0 0;padding:14px 0 13px;text-decoration:none;white-space:nowrap}
        .chuyi-ai-relay-tabs a:hover{color:#1e1e1e}
        .chuyi-ai-relay-tabs a.is-active{border-bottom-color:#3858e9;color:#1e1e1e}
        .chuyi-ai-relay-section,.chuyi-ai-relay-test-layout{box-sizing:border-box;margin:0 auto 32px;max-width:1240px;padding:0 32px}
        .chuyi-ai-relay-section__head{align-items:flex-start;display:flex;gap:20px;justify-content:space-between;margin:0 0 20px}
        .chuyi-ai-relay-section__head--compact{margin-bottom:18px}
        .chuyi-ai-relay-section__head h2{color:#1e1e1e;font-size:20px;font-weight:500;line-height:1.3;margin:0 0 6px}
        .chuyi-ai-relay-section__head p{color:#646970;font-size:13px;line-height:1.6;margin:0;max-width:720px}
        .chuyi-ai-relay-grid{display:grid;gap:16px;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));margin:0 0 28px}
        .chuyi-ai-relay-card__title{color:#1e1e1e;font-size:15px;font-weight:600;line-height:1.35;margin:0 0 12px}
        .chuyi-ai-relay-card__muted{color:#646970;font-size:13px}
        .chuyi-ai-relay-relay-list{display:grid;gap:16px}
        .chuyi-ai-relay-relay-cards{display:grid;gap:16px;grid-template-columns:repeat(auto-fit,minmax(320px,1fr))}
        .chuyi-ai-relay-relay-card,.chuyi-ai-relay-test-card,.chuyi-ai-relay-stat,.chuyi-ai-relay-prompt-card{border:1px solid #dcdcde!important;border-radius:2px!important;box-shadow:none!important}
        .chuyi-ai-relay-relay-card .components-card__body,.chuyi-ai-relay-test-card .components-card__body,.chuyi-ai-relay-stat .components-card__body,.chuyi-ai-relay-prompt-card .components-card__body{padding:20px!important}
        .chuyi-ai-relay-relay-card__head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:16px}
        .chuyi-ai-relay-prompt-list{display:grid;gap:16px}
        .chuyi-ai-relay-prompt-card__head{align-items:flex-start;display:flex;gap:16px;justify-content:space-between;margin-bottom:14px}
        .chuyi-ai-relay-prompt-card__head h3{color:#1e1e1e;font-size:18px;font-weight:500;line-height:1.35;margin:0 0 6px}
        .chuyi-ai-relay-prompt-card__head code{background:#f6f7f7;color:#646970;display:inline-block;font-size:12px;max-width:100%;word-break:break-all}
        .chuyi-ai-relay-prompt-grid{margin-top:16px}
        .chuyi-ai-relay-prompt-grid .components-base-control{margin-bottom:0}
        .chuyi-ai-relay-prompt-grid textarea{font-family:Consolas,Monaco,monospace;line-height:1.55}
        .chuyi-ai-relay-relay-card__head code{background:#f6f7f7;color:#646970;display:inline-block;font-size:12px;margin-top:4px;max-width:100%;word-break:break-all}
        .chuyi-ai-relay-relay-card__meta{display:grid;gap:8px;grid-template-columns:repeat(2,minmax(0,1fr));margin-bottom:14px}
        .chuyi-ai-relay-relay-card__meta div{background:#fff;border:1px solid #e0e0e0;border-radius:2px;padding:10px 12px}
        .chuyi-ai-relay-relay-card__meta span{color:#646970;display:block;font-size:11px;font-weight:600;letter-spacing:.04em;margin-bottom:4px;text-transform:uppercase}
        .chuyi-ai-relay-relay-card__meta strong{color:#1e1e1e;display:block;font-size:13px;line-height:1.45;word-break:break-all}
        .chuyi-ai-relay-latency{font-weight:700!important}
        .chuyi-ai-relay-latency.is-low{color:#008a20!important}
        .chuyi-ai-relay-latency.is-medium{color:#b26200!important}
        .chuyi-ai-relay-latency.is-high{color:#d63638!important}
        .chuyi-ai-relay-latency.is-offline{color:#1e1e1e!important}
        .chuyi-ai-relay-status{align-items:center;background:#f6f7f7;border:1px solid #dcdcde;border-radius:999px;color:#646970;display:inline-flex;font-size:12px;font-weight:600;padding:3px 9px;white-space:nowrap}
        .chuyi-ai-relay-status.is-enabled{background:#edfaef;border-color:#8ed19e;color:#008a20}
        .chuyi-ai-relay-relay{background:#fff;border:1px solid #dcdcde;border-radius:2px;box-shadow:none;overflow:hidden}
        .chuyi-ai-relay-relay__head{align-items:center;background:#fff;border-bottom:1px solid #dcdcde;cursor:pointer;display:flex;gap:16px;justify-content:space-between;padding:16px 18px}
        .chuyi-ai-relay-relay__head:hover{background:#f6f7f7}
        .chuyi-ai-relay-relay__head:focus{box-shadow:inset 0 0 0 2px #3858e9;outline:0}
        .chuyi-ai-relay-relay__title{display:flex;flex-direction:column;gap:5px;min-width:0}
        .chuyi-ai-relay-relay__summary{align-items:center;display:inline-flex;gap:8px;min-width:0}
        .chuyi-ai-relay-relay__chevron{color:#646970;display:inline-block;font-size:20px;line-height:1;transform:rotate(0deg);transition:transform .12s ease}
        .chuyi-ai-relay-relay.is-open .chuyi-ai-relay-relay__chevron{transform:rotate(90deg)}
        .chuyi-ai-relay-relay__title strong{color:#1e1e1e;font-size:15px;font-weight:600;line-height:1.35}
        .chuyi-ai-relay-relay__title code{background:transparent;color:#646970;font-size:12px;padding:0;word-break:break-all}
        .chuyi-ai-relay-relay .components-panel__body{border-top:0!important}
        .chuyi-ai-relay-relay .components-panel__body-title{display:none}
        .chuyi-ai-relay-form-grid{display:grid;gap:18px;grid-template-columns:repeat(2,minmax(0,1fr));padding-top:4px}
        .chuyi-ai-relay-field{display:flex;flex-direction:column;gap:6px}
        .chuyi-ai-relay-field--full{grid-column:1/-1}
        .chuyi-ai-relay-field label{color:#1e1e1e;font-weight:600}
        .chuyi-ai-relay-field input,.chuyi-ai-relay-field select,.chuyi-ai-relay-field textarea{max-width:100%;width:100%}
        .chuyi-ai-relay-model-list{display:grid;gap:10px;margin-top:12px}
        .chuyi-ai-relay-model-row{align-items:start;background:#f6f7f7;border:1px solid #dcdcde;border-radius:2px;display:grid;gap:12px;grid-template-columns:1fr 1fr auto;padding:14px}
        .chuyi-ai-relay-capabilities{display:flex;flex-wrap:wrap;gap:12px;grid-column:1/3;margin-top:4px}
        .chuyi-ai-relay-actions{align-items:center;display:flex;flex-wrap:wrap;gap:8px}
        .chuyi-ai-relay-actions--end{justify-content:flex-end;margin-top:4px}
        .chuyi-ai-relay-stat strong{color:#1e1e1e;display:block;font-size:32px;font-weight:500;line-height:1.15;margin:6px 0}
        .chuyi-ai-relay-stat p{margin:0}
        .chuyi-ai-relay-notice{box-sizing:border-box;margin:0 auto 20px;max-width:1240px;padding:0 32px}
        .chuyi-ai-relay-test-layout{display:grid;gap:20px;grid-template-columns:minmax(0,1fr) minmax(320px,420px)}
        .chuyi-ai-relay-result{background:#f6f7f7;border:1px solid #dcdcde;border-radius:2px;display:grid;gap:12px;min-height:220px;overflow:hidden;padding:14px;word-break:break-word}
        .chuyi-ai-relay-result__images{display:grid;gap:10px}
        .chuyi-ai-relay-result__images a{background:#fff;border:1px solid #dcdcde;border-radius:2px;display:block;line-height:0;overflow:hidden}
        .chuyi-ai-relay-result__images img{background:#fff;display:block;height:auto;max-height:360px;object-fit:contain;width:100%}
        .chuyi-ai-relay-result__text{background:transparent;border:0;color:#50575e;margin:0;max-height:360px;overflow:auto;padding:0;white-space:pre-wrap;word-break:break-word}
        .chuyi-ai-relay-empty{align-items:center;background:#fff;border:1px dashed #c3c4c7;border-radius:2px;color:#646970;display:flex;flex-direction:column;gap:8px;grid-column:1/-1;justify-content:center;min-height:220px;padding:32px;text-align:center}
        .chuyi-ai-relay-empty__mark{align-items:center;background:#f0f0f1;border-radius:999px;color:#50575e;display:flex;font-size:12px;font-weight:600;height:44px;justify-content:center;letter-spacing:.08em;width:44px}
        .chuyi-ai-relay-empty h3{color:#1e1e1e;font-size:18px;font-weight:500;margin:6px 0 0}
        .chuyi-ai-relay-empty p{margin:0;max-width:420px}
        .chuyi-ai-relay-empty__action{margin-top:8px}
        .chuyi-ai-relay-help{display:grid;gap:24px}
        .chuyi-ai-relay-help__hero{background:#1e1e1e;border-radius:2px;color:#fff;padding:32px}
        .chuyi-ai-relay-help__hero span{color:#b8c5ff;display:block;font-size:12px;font-weight:600;letter-spacing:.08em;margin-bottom:10px;text-transform:uppercase}
        .chuyi-ai-relay-help__hero h2{color:#fff;font-size:28px;font-weight:500;line-height:1.25;margin:0 0 12px;max-width:760px}
        .chuyi-ai-relay-help__hero p{color:#dcdcde;font-size:14px;line-height:1.7;margin:0;max-width:820px}
        .chuyi-ai-relay-help__grid{display:grid;gap:16px;grid-template-columns:repeat(2,minmax(0,1fr))}
        .chuyi-ai-relay-help-card{border:1px solid #dcdcde!important;border-radius:2px!important;box-shadow:none!important}
        .chuyi-ai-relay-help-card .components-card__body{padding:22px!important}
        .chuyi-ai-relay-help-card--wide{grid-column:1/-1}
        .chuyi-ai-relay-help-card h3{color:#1e1e1e;font-size:18px;font-weight:500;margin:0 0 12px}
        .chuyi-ai-relay-help-card p,.chuyi-ai-relay-help-card li{color:#50575e;font-size:14px;line-height:1.7}
        .chuyi-ai-relay-help-card ul,.chuyi-ai-relay-help-card ol{margin:0 0 0 20px;padding:0}
        .chuyi-ai-relay-help__actions{display:flex;flex-wrap:wrap;gap:8px;margin-top:16px}
        .chuyi-ai-relay-donate{border-top:1px solid #dcdcde;padding-top:24px}
        .chuyi-ai-relay-donate__grid{display:grid;gap:16px;grid-template-columns:repeat(3,minmax(0,1fr))}
        .chuyi-ai-relay-donate figure{background:#fff;border:1px solid #dcdcde;border-radius:2px;margin:0;padding:14px;text-align:center}
        .chuyi-ai-relay-donate img{background:#f6f7f7;display:block;height:auto;max-height:360px;object-fit:contain;width:100%}
        .chuyi-ai-relay-donate figcaption{color:#1e1e1e;font-size:13px;font-weight:600;margin-top:10px}
        .chuyi-ai-relay-donate__crypto{align-content:center;background:#f6f7f7;border:1px solid #dcdcde;border-radius:2px;display:grid;gap:8px;padding:20px}
        .chuyi-ai-relay-donate__crypto span{color:#646970;font-size:12px;font-weight:600;letter-spacing:.04em;text-transform:uppercase}
        .chuyi-ai-relay-donate__crypto code{background:#fff;color:#1e1e1e;font-size:13px;line-height:1.6;overflow:auto;padding:8px;white-space:normal;word-break:break-all}
        @media (max-width:960px){body.chuyi-ai-relay-admin-page #wpcontent{padding-left:0}.chuyi-ai-relay-page-head{padding:0 20px}.chuyi-ai-relay-page-head__inner{flex-direction:column;padding-top:24px}.chuyi-ai-relay-section,.chuyi-ai-relay-test-layout,.chuyi-ai-relay-notice{padding-left:20px;padding-right:20px}.chuyi-ai-relay-test-layout,.chuyi-ai-relay-help__grid,.chuyi-ai-relay-donate__grid{grid-template-columns:1fr}.chuyi-ai-relay-form-grid,.chuyi-ai-relay-model-row{grid-template-columns:1fr}.chuyi-ai-relay-capabilities{grid-column:auto}}
    ';
}