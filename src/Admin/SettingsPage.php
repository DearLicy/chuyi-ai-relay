<?php
/**
 * Admin settings page for 初一中转.
 *
 * @package WordPress\ChuyiAiRelay\Admin
 */

declare(strict_types=1);

namespace WordPress\ChuyiAiRelay\Admin;

use WordPress\ChuyiAiRelay\Settings;

/**
 * Renders and handles the 初一中转 settings page.
 */
final class SettingsPage
{
    private const MENU_SLUG = 'chuyi-ai-relay';
    private const SAVE_ACTION = 'chuyi_ai_relay_save_settings';
    private const FETCH_ACTION = 'chuyi_ai_relay_fetch_models';
    private const TEST_TEXT_ACTION = 'chuyi_ai_relay_test_text';
    private const TEST_IMAGE_ACTION = 'chuyi_ai_relay_test_image';
    private const NONCE_ACTION = 'chuyi_ai_relay_settings';
    private const MESSAGE_SAVED = 'saved';
    private const MESSAGE_FETCHED = 'fetched';
    private const MESSAGE_TEST_TEXT_OK = 'test_text_ok';
    private const MESSAGE_TEST_IMAGE_OK = 'test_image_ok';
    private const MESSAGE_TEST_FAILED = 'test_failed';
    private const MESSAGE_NO_BASE_URL = 'no_base_url';
    private const MESSAGE_NO_API_KEY = 'no_api_key';
    private const MESSAGE_FETCH_FAILED = 'fetch_failed';
    private const MESSAGE_NO_MODELS = 'no_models';

    /**
     * Registers admin hooks.
     */
    public static function init(): void
    {
        add_action('admin_menu', array(__CLASS__, 'registerMenu'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueueAssets'));
        add_action('admin_post_' . self::SAVE_ACTION, array(__CLASS__, 'handleSave'));
        add_action('admin_post_' . self::FETCH_ACTION, array(__CLASS__, 'handleFetchModels'));
        add_action('admin_post_' . self::TEST_TEXT_ACTION, array(__CLASS__, 'handleTestText'));
        add_action('admin_post_' . self::TEST_IMAGE_ACTION, array(__CLASS__, 'handleTestImage'));
        add_action('wp_ajax_' . self::SAVE_ACTION, array(__CLASS__, 'handleAjaxSave'));
        add_action('wp_ajax_' . self::FETCH_ACTION, array(__CLASS__, 'handleAjaxFetchModels'));
        add_action('wp_ajax_' . self::TEST_TEXT_ACTION, array(__CLASS__, 'handleAjaxTestText'));
        add_action('wp_ajax_' . self::TEST_IMAGE_ACTION, array(__CLASS__, 'handleAjaxTestImage'));
        add_filter('plugin_action_links_' . plugin_basename(\CHUYI_AI_RELAY_FILE), array(__CLASS__, 'addPluginActionLinks'));
    }

    /**
     * Adds the settings page under Settings.
     */
    public static function registerMenu(): void
    {
        add_options_page(
            '初一中转设置',
            '初一中转',
            'manage_options',
            self::MENU_SLUG,
            array(__CLASS__, 'render')
        );
    }

    /**
     * Enqueues the settings page script.
     */
    public static function enqueueAssets(string $hookSuffix): void
    {
        if ($hookSuffix !== 'settings_page_' . self::MENU_SLUG) {
            return;
        }

        wp_enqueue_style('wp-components');

        wp_register_style(
            'chuyi-ai-relay-admin-settings',
            false,
            array('wp-components'),
            \CHUYI_AI_RELAY_VERSION
        );
        wp_enqueue_style('chuyi-ai-relay-admin-settings');
        wp_add_inline_style('chuyi-ai-relay-admin-settings', self::getInlineStyles());

        wp_enqueue_script(
            'chuyi-ai-relay-admin-settings',
            \CHUYI_AI_RELAY_URL . 'assets/js/admin-settings.js',
            array(),
            \CHUYI_AI_RELAY_VERSION,
            true
        );

        wp_localize_script(
            'chuyi-ai-relay-admin-settings',
            'chuyiAiRelaySettings',
            array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce'   => wp_create_nonce(self::NONCE_ACTION),
                'actions' => array(
                    'save'      => self::SAVE_ACTION,
                    'fetch'     => self::FETCH_ACTION,
                    'testText'  => self::TEST_TEXT_ACTION,
                    'testImage' => self::TEST_IMAGE_ACTION,
                ),
                'texts'   => array(
                    'requestFailed' => __('请求失败，请稍后重试。', 'chuyi-ai-relay'),
                    'saving'        => __('保存中...', 'chuyi-ai-relay'),
                    'saved'         => __('设置已保存。', 'chuyi-ai-relay'),
                    'saveFailed'    => __('保存失败', 'chuyi-ai-relay'),
                    'modelsFetched' => __('模型已获取并保存。', 'chuyi-ai-relay'),
                    'testSucceeded' => __('测试成功。', 'chuyi-ai-relay'),
                ),
            )
        );
    }

    /**
     * Adds a quick settings link on the Plugins screen.
     *
     * @param list<string> $links Existing plugin action links.
     * @return list<string>
     */
    public static function addPluginActionLinks(array $links): array
    {
        array_unshift(
            $links,
            '<a href="' . esc_url(self::getPageUrl()) . '">' . esc_html__('设置', 'chuyi-ai-relay') . '</a>'
        );

        return $links;
    }

    /**
     * Renders the settings page.
     */
    public static function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('你没有权限管理初一中转。', 'chuyi-ai-relay'));
        }

        $baseUrl = Settings::getBaseUrl();
        $apiKey = Settings::getApiKey();
        $models = Settings::getModels();
        $capabilities = Settings::getModelCapabilities();
        $textModelCount = count(self::filterModelsByCapability($models, $capabilities, 'text_generation'));
        $imageModelCount = count(self::filterModelsByCapability($models, $capabilities, 'image_generation'));
        ?>
        <div class="wrap chuyi-ai-relay-wrap">
            <div class="chuyi-ai-relay-page" role="region" aria-label="<?php echo esc_attr__('初一中转设置', 'chuyi-ai-relay'); ?>">
                <header class="chuyi-ai-relay-page__header">
                    <div class="chuyi-ai-relay-page__header-inner">
                        <div class="chuyi-ai-relay-brand">
                            <span class="chuyi-ai-relay-brand__visual">
                                <img class="chuyi-ai-relay-logo" src="<?php echo esc_url(\CHUYI_AI_RELAY_URL . 'assets/images/chuyi-relay.svg'); ?>" alt="" />
                            </span>
                            <div class="chuyi-ai-relay-brand__copy">
                                <span class="chuyi-ai-relay-eyebrow"><?php echo esc_html__('OpenAI 协议中转', 'chuyi-ai-relay'); ?></span>
                                <h1><?php echo esc_html__('初一中转', 'chuyi-ai-relay'); ?></h1>
                                <p><?php echo esc_html__('连接中转站、同步模型，并把文本、视觉和生图能力接入 WordPress AI。', 'chuyi-ai-relay'); ?></p>
                            </div>
                        </div>
                        <div class="chuyi-ai-relay-header-actions">
                            <span class="chuyi-ai-relay-status <?php echo $apiKey !== '' ? 'is-ok' : 'is-warning'; ?>">
                                <?php echo esc_html($apiKey !== '' ? __('API Key 已配置', 'chuyi-ai-relay') : __('API Key 未配置', 'chuyi-ai-relay')); ?>
                            </span>
                            <a class="button button-secondary" href="<?php echo esc_url(admin_url('options-connectors.php')); ?>">
                                <?php echo esc_html__('打开 Connectors', 'chuyi-ai-relay'); ?>
                            </a>
                        </div>
                    </div>
                </header>

                <main class="chuyi-ai-relay-page__content">
                    <?php self::renderNotice(); ?>

                    <div class="chuyi-ai-relay-overview" aria-label="<?php echo esc_attr__('当前配置概览', 'chuyi-ai-relay'); ?>">
                        <section class="chuyi-ai-relay-overview-card">
                            <span><?php echo esc_html__('接口地址', 'chuyi-ai-relay'); ?></span>
                            <strong id="chuyi-ai-relay-overview-base-url"><?php echo esc_html($baseUrl !== '' ? $baseUrl : __('未设置', 'chuyi-ai-relay')); ?></strong>
                            <p><?php echo esc_html__('保存后用于 /models 与 /chat/completions。', 'chuyi-ai-relay'); ?></p>
                        </section>
                        <section class="chuyi-ai-relay-overview-card">
                            <span><?php echo esc_html__('模型总数', 'chuyi-ai-relay'); ?></span>
                            <strong id="chuyi-ai-relay-overview-model-count"><?php echo esc_html((string) count($models)); ?></strong>
                            <p><?php echo esc_html__('来自一键获取或手动维护的模型清单。', 'chuyi-ai-relay'); ?></p>
                        </section>
                        <section class="chuyi-ai-relay-overview-card">
                            <span><?php echo esc_html__('可用能力', 'chuyi-ai-relay'); ?></span>
                            <strong id="chuyi-ai-relay-overview-capabilities">
                                <?php
                                echo esc_html(
                                    sprintf(
                                        /* translators: 1: text model count, 2: image model count. */
                                        __('文本 %1$d / 生图 %2$d', 'chuyi-ai-relay'),
                                        $textModelCount,
                                        $imageModelCount
                                    )
                                );
                                ?>
                            </strong>
                            <p><?php echo esc_html__('按下方模型能力勾选结果统计。', 'chuyi-ai-relay'); ?></p>
                        </section>
                    </div>

                    <form id="chuyi-ai-relay-settings-form" class="chuyi-ai-relay-settings-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field(self::NONCE_ACTION); ?>
                        <input type="hidden" name="action" value="<?php echo esc_attr(self::SAVE_ACTION); ?>" />

                        <div class="chuyi-ai-relay-layout">
                            <div class="chuyi-ai-relay-main-stack">
                                <section class="chuyi-ai-relay-card chuyi-ai-relay-card--hero">
                                    <div class="chuyi-ai-relay-card__header">
                                        <div>
                                            <span class="chuyi-ai-relay-section-label"><?php echo esc_html__('步骤 1', 'chuyi-ai-relay'); ?></span>
                                            <h2><?php echo esc_html__('接口地址', 'chuyi-ai-relay'); ?></h2>
                                            <p><?php echo esc_html__('填写中转站根地址。插件会统一规范成 OpenAI 兼容 /v1 基础地址。', 'chuyi-ai-relay'); ?></p>
                                        </div>
                                    </div>
                                    <label class="chuyi-ai-relay-field" for="chuyi_ai_relay_base_url">
                                        <span><?php echo esc_html__('OpenAI 兼容接口地址', 'chuyi-ai-relay'); ?></span>
                                        <input
                                            type="url"
                                            class="regular-text code"
                                            id="chuyi_ai_relay_base_url"
                                            name="base_url"
                                            value="<?php echo esc_attr($baseUrl); ?>"
                                            placeholder="https://ai.dearlicy.com"
                                        />
                                    </label>
                                    <p class="description">
                                        <?php echo esc_html__('不要填写 /models、/responses 或 /chat/completions；插件会自动补 /v1。', 'chuyi-ai-relay'); ?>
                                    </p>
                                </section>

                                <section class="chuyi-ai-relay-card">
                                    <?php self::renderManualModelsField($models); ?>
                                    <div class="chuyi-ai-relay-card__actions">
                                        <button type="button" class="button button-secondary" id="chuyi-ai-relay-fetch-models">
                                            <?php echo esc_html__('一键获取模型', 'chuyi-ai-relay'); ?>
                                        </button>
                                        <span class="spinner" id="chuyi-ai-relay-fetch-spinner" style="float:none;"></span>
                                    </div>
                                    <div id="chuyi-ai-relay-fetch-result" class="chuyi-ai-relay-ajax-result" aria-live="polite"></div>
                                </section>
                            </div>

                            <aside class="chuyi-ai-relay-sidebar" aria-label="<?php echo esc_attr__('模型能力', 'chuyi-ai-relay'); ?>">
                                <section class="chuyi-ai-relay-card chuyi-ai-relay-card--sticky chuyi-ai-relay-capability-panel">
                                    <div id="chuyi-ai-relay-models-table">
                                        <?php self::renderModelsTable($models, $capabilities); ?>
                                    </div>
                                </section>
                            </aside>
                        </div>

                        <div class="chuyi-ai-relay-floating-save" role="region" aria-label="<?php echo esc_attr__('保存设置', 'chuyi-ai-relay'); ?>">
                            <?php submit_button(__('保存设置', 'chuyi-ai-relay'), 'primary', 'submit', false, array('id' => 'chuyi-ai-relay-save-button')); ?>
                        </div>
                    </form>

                    <div id="chuyi-ai-relay-test-forms">
                        <?php self::renderModelTestForms($models, $capabilities); ?>
                    </div>
                </main>
            </div>
        </div>
        <?php
    }

    /**
     * Saves base URL and manual model capabilities.
     */
    public static function handleSave(): void
    {
        self::assertCanManage();
        self::savePostedSettings();

        self::redirect(self::MESSAGE_SAVED);
    }

    /**
     * Saves settings through WordPress admin AJAX.
     */
    public static function handleAjaxSave(): void
    {
        self::assertCanAjaxManage();
        self::savePostedSettings();

        wp_send_json_success(
            array_merge(
                array('message' => __('设置已保存。', 'chuyi-ai-relay')),
                self::getAjaxRenderedState()
            )
        );
    }

    /**
     * Fetches models through WordPress admin AJAX.
     */
    public static function handleAjaxFetchModels(): void
    {
        self::assertCanAjaxManage();

        $postedBaseUrl = isset($_POST['base_url']) ? Settings::normalizeBaseUrl((string) wp_unslash($_POST['base_url'])) : '';
        if (isset($_POST['base_url'])) {
            update_option(Settings::BASE_URL_OPTION, $postedBaseUrl, false);
        }

        $result = self::fetchModelsFromRelay();
        if (!$result['ok']) {
            wp_send_json_error(array('message' => $result['message']), 400);
        }

        Settings::saveFetchedModels($result['models']);

        wp_send_json_success(
            array_merge(
                array(
                    'message' => sprintf(
                        /* translators: %d: model count. */
                        __('已获取并保存 %d 个模型。', 'chuyi-ai-relay'),
                        count($result['models'])
                    ),
                ),
                self::getAjaxRenderedState()
            )
        );
    }

    /**
     * Tests a selected text model through WordPress admin AJAX.
     */
    public static function handleAjaxTestText(): void
    {
        self::assertCanAjaxManage();

        $model = isset($_POST['model']) ? sanitize_text_field((string) wp_unslash($_POST['model'])) : '';
        $prompt = isset($_POST['prompt']) ? sanitize_textarea_field((string) wp_unslash($_POST['prompt'])) : '';
        if ($prompt === '') {
            $prompt = '请回复：初一中转文本测试成功';
        }

        $result = self::requestChatCompletions($model, $prompt, false);
        if (!$result['ok']) {
            wp_send_json_error(array('message' => $result['detail']), 400);
        }

        wp_send_json_success(
            array(
                'message' => __('文本测试成功。', 'chuyi-ai-relay'),
                'detail'  => $result['detail'],
                'type'    => 'text',
            )
        );
    }

    /**
     * Tests a selected image model through WordPress admin AJAX.
     */
    public static function handleAjaxTestImage(): void
    {
        self::assertCanAjaxManage();

        $model = isset($_POST['model']) ? sanitize_text_field((string) wp_unslash($_POST['model'])) : '';
        $prompt = isset($_POST['prompt']) ? sanitize_textarea_field((string) wp_unslash($_POST['prompt'])) : '';
        if ($prompt === '') {
            $prompt = '生成一张极简风格的蓝色圆形图标';
        }

        $result = self::requestChatCompletions($model, $prompt, true);
        if (!$result['ok']) {
            wp_send_json_error(array('message' => $result['detail']), 400);
        }

        wp_send_json_success(
            array(
                'message'    => __('图像测试请求成功。', 'chuyi-ai-relay'),
                'detail'     => $result['detail'],
                'type'       => 'image',
                'previewUrl' => isset($result['previewUrl']) && is_string($result['previewUrl']) ? $result['previewUrl'] : '',
            )
        );
    }

    /**
     * Fetches models from the configured OpenAI-compatible /models endpoint.
     */
    public static function handleFetchModels(): void
    {
        self::assertCanManage();

        $postedBaseUrl = isset($_POST['base_url']) ? Settings::normalizeBaseUrl((string) wp_unslash($_POST['base_url'])) : '';
        if (isset($_POST['base_url'])) {
            update_option(Settings::BASE_URL_OPTION, $postedBaseUrl, false);
        }

        $baseUrl = Settings::getBaseUrl();
        if ($baseUrl === '') {
            self::redirect(self::MESSAGE_NO_BASE_URL);
        }

        $apiKey = Settings::getApiKey();
        if ($apiKey === '') {
            self::redirect(self::MESSAGE_NO_API_KEY);
        }

        $response = wp_remote_get(
            rtrim($baseUrl, '/') . '/models',
            array(
                'timeout' => 30,
                'headers' => array(
                    'Accept'        => 'application/json',
                    'Authorization' => 'Bearer ' . $apiKey,
                ),
            )
        );

        if (is_wp_error($response)) {
            self::redirect(self::MESSAGE_FETCH_FAILED, $response->get_error_message());
        }

        $statusCode = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        if ($statusCode < 200 || $statusCode >= 300) {
            self::redirect(self::MESSAGE_FETCH_FAILED, 'HTTP ' . $statusCode . '：' . wp_strip_all_tags($body));
        }

        $data = json_decode($body, true);
        if (!is_array($data) || empty($data['data']) || !is_array($data['data'])) {
            self::redirect(self::MESSAGE_NO_MODELS);
        }

        $models = self::normalizeModelsFromResponse($data['data']);
        if (empty($models)) {
            self::redirect(self::MESSAGE_NO_MODELS);
        }

        Settings::saveFetchedModels($models);
        self::redirect(self::MESSAGE_FETCHED);
    }

    /**
     * Tests a selected model with a text prompt through /chat/completions.
     */
    public static function handleTestText(): void
    {
        self::assertCanManage();

        $model = isset($_POST['model']) ? sanitize_text_field((string) wp_unslash($_POST['model'])) : '';
        $prompt = isset($_POST['prompt']) ? sanitize_textarea_field((string) wp_unslash($_POST['prompt'])) : '';
        if ($prompt === '') {
            $prompt = '请回复：初一中转文本测试成功';
        }

        $result = self::requestChatCompletions($model, $prompt, false);
        if ($result['ok']) {
            self::redirect(self::MESSAGE_TEST_TEXT_OK, $result['detail']);
        }

        self::redirect(self::MESSAGE_TEST_FAILED, $result['detail']);
    }

    /**
     * Tests a selected model with an image prompt through /chat/completions.
     */
    public static function handleTestImage(): void
    {
        self::assertCanManage();

        $model = isset($_POST['model']) ? sanitize_text_field((string) wp_unslash($_POST['model'])) : '';
        $prompt = isset($_POST['prompt']) ? sanitize_textarea_field((string) wp_unslash($_POST['prompt'])) : '';
        if ($prompt === '') {
            $prompt = '生成一张极简风格的蓝色圆形图标';
        }

        $result = self::requestChatCompletions($model, $prompt, true);
        if ($result['ok']) {
            self::redirect(self::MESSAGE_TEST_IMAGE_OK, $result['detail']);
        }

        self::redirect(self::MESSAGE_TEST_FAILED, $result['detail']);
    }

    /**
     * Normalizes OpenAI-compatible model response items.
     *
     * @param array<mixed> $items Response data items.
     * @return list<array{id:string,name:string}>
     */
    private static function normalizeModelsFromResponse(array $items): array
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
                : $id;

            $models[] = array(
                'id'   => $id,
                'name' => $name,
            );
            $seen[$id] = true;
        }

        usort(
            $models,
            static function (array $a, array $b): int {
                return strnatcasecmp($a['id'], $b['id']);
            }
        );

        return $models;
    }

    /**
     * Renders the manual model list textarea.
     *
     * @param list<array{id:string,name:string}> $models Saved model list.
     */
    private static function renderManualModelsField(array $models): void
    {
        $lines = array();
        foreach ($models as $model) {
            $lines[] = $model['name'] !== $model['id'] ? $model['id'] . '|' . $model['name'] : $model['id'];
        }
        ?>
        <h2><?php echo esc_html__('模型列表', 'chuyi-ai-relay'); ?></h2>
        <p><?php echo esc_html__('如果中转站没有 /models 接口，直接在这里手动填写模型 ID。每行一个模型；需要显示名称时使用“模型ID|显示名称”。', 'chuyi-ai-relay'); ?></p>
        <textarea
            class="large-text code"
            name="models"
            rows="8"
            placeholder="gpt-4o&#10;gpt-image-1|图像生成模型"
        ><?php echo esc_textarea(implode("\n", $lines)); ?></textarea>
        <?php
    }

    /**
     * Parses manual model textarea content.
     *
     * @return list<array{id:string,name:string}>
     */
    private static function parseModelsFromTextarea(string $text): array
    {
        $models = array();
        $seen = array();
        $lines = preg_split('/\R/', $text);
        if (!is_array($lines)) {
            return array();
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $parts = array_map('trim', explode('|', $line, 2));
            $id = sanitize_text_field($parts[0]);
            if ($id === '' || isset($seen[$id])) {
                continue;
            }

            $name = isset($parts[1]) && $parts[1] !== '' ? sanitize_text_field($parts[1]) : $id;
            $models[] = array(
                'id'   => $id,
                'name' => $name,
            );
            $seen[$id] = true;
        }

        return $models;
    }

    /**
     * Formats models for the manual textarea.
     *
     * @param list<array{id:string,name:string}> $models Saved model list.
     */
    private static function formatModelsTextarea(array $models): string
    {
        $lines = array();
        foreach ($models as $model) {
            $lines[] = $model['name'] !== $model['id'] ? $model['id'] . '|' . $model['name'] : $model['id'];
        }

        return implode("\n", $lines);
    }

    /**
     * Saves posted settings and model capability choices.
     */
    private static function savePostedSettings(): void
    {
        $baseUrl = isset($_POST['base_url']) ? Settings::normalizeBaseUrl((string) wp_unslash($_POST['base_url'])) : '';
        update_option(Settings::BASE_URL_OPTION, $baseUrl, false);

        $modelsText = isset($_POST['models']) ? (string) wp_unslash($_POST['models']) : '';
        Settings::saveFetchedModels(self::parseModelsFromTextarea($modelsText));

        $capabilities = array();
        if (isset($_POST['capabilities']) && is_array($_POST['capabilities'])) {
            $rawCapabilities = wp_unslash($_POST['capabilities']);
            if (is_array($rawCapabilities)) {
                foreach ($rawCapabilities as $modelId => $modelCapabilities) {
                    if (!is_string($modelId) || !is_array($modelCapabilities)) {
                        continue;
                    }
                    $capabilities[sanitize_text_field($modelId)] = array_map('sanitize_text_field', $modelCapabilities);
                }
            }
        }
        Settings::saveModelCapabilities($capabilities);
    }

    /**
     * Fetches model IDs from the configured relay.
     *
     * @return array{ok:bool,message:string,models:list<array{id:string,name:string}>}
     */
    private static function fetchModelsFromRelay(): array
    {
        $baseUrl = Settings::getBaseUrl();
        if ($baseUrl === '') {
            return array('ok' => false, 'message' => __('请先填写有效的接口地址。', 'chuyi-ai-relay'), 'models' => array());
        }

        $apiKey = Settings::getApiKey();
        if ($apiKey === '') {
            return array('ok' => false, 'message' => __('请先到 Connectors 页面填写初一中转 API Key。', 'chuyi-ai-relay'), 'models' => array());
        }

        $response = wp_remote_get(
            rtrim($baseUrl, '/') . '/models',
            array(
                'timeout' => 30,
                'headers' => array(
                    'Accept'        => 'application/json',
                    'Authorization' => 'Bearer ' . $apiKey,
                ),
            )
        );

        if (is_wp_error($response)) {
            return array('ok' => false, 'message' => $response->get_error_message(), 'models' => array());
        }

        $statusCode = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        if ($statusCode < 200 || $statusCode >= 300) {
            return array('ok' => false, 'message' => 'HTTP ' . $statusCode . '：' . self::trimDetail($body), 'models' => array());
        }

        $data = json_decode($body, true);
        if (!is_array($data) || empty($data['data']) || !is_array($data['data'])) {
            return array('ok' => false, 'message' => __('接口没有返回可用模型。', 'chuyi-ai-relay'), 'models' => array());
        }

        $models = self::normalizeModelsFromResponse($data['data']);
        if (empty($models)) {
            return array('ok' => false, 'message' => __('接口没有返回可用模型。', 'chuyi-ai-relay'), 'models' => array());
        }

        return array('ok' => true, 'message' => '', 'models' => $models);
    }

    /**
     * Returns freshly rendered page fragments for AJAX responses.
     *
     * @return array{baseUrl:string,baseUrlLabel:string,modelsText:string,modelCount:string,capabilitySummary:string,modelsTableHtml:string,testFormsHtml:string}
     */
    private static function getAjaxRenderedState(): array
    {
        $baseUrl = Settings::getBaseUrl();
        $models = Settings::getModels();
        $capabilities = Settings::getModelCapabilities();
        $textModelCount = count(self::filterModelsByCapability($models, $capabilities, 'text_generation'));
        $imageModelCount = count(self::filterModelsByCapability($models, $capabilities, 'image_generation'));

        ob_start();
        self::renderModelsTable($models, $capabilities);
        $modelsTableHtml = (string) ob_get_clean();

        ob_start();
        self::renderModelTestForms($models, $capabilities);
        $testFormsHtml = (string) ob_get_clean();

        return array(
            'baseUrl'           => $baseUrl,
            'baseUrlLabel'      => $baseUrl !== '' ? $baseUrl : __('未设置', 'chuyi-ai-relay'),
            'modelsText'        => self::formatModelsTextarea($models),
            'modelCount'        => (string) count($models),
            'capabilitySummary' => sprintf(
                /* translators: 1: text model count, 2: image model count. */
                __('文本 %1$d / 生图 %2$d', 'chuyi-ai-relay'),
                $textModelCount,
                $imageModelCount
            ),
            'modelsTableHtml'   => $modelsTableHtml,
            'testFormsHtml'     => $testFormsHtml,
        );
    }

    /**
     * Renders text and image test forms for saved models.
     *
     * @param list<array{id:string,name:string}> $models Saved model list.
     * @param array<string,list<string>>         $capabilities Saved model capabilities.
     */
    private static function renderModelTestForms(array $models, array $capabilities): void
    {
        $textModels = self::filterModelsByCapability($models, $capabilities, 'text_generation');
        $imageModels = self::filterModelsByCapability($models, $capabilities, 'image_generation');
        ?>
        <section class="chuyi-ai-relay-card chuyi-ai-relay-test-section">
            <div class="chuyi-ai-relay-card__header">
                <div>
                    <h2><?php echo esc_html__('模型测试', 'chuyi-ai-relay'); ?></h2>
                    <p><?php echo esc_html__('使用已保存的模型能力快速验证文本与图像请求。', 'chuyi-ai-relay'); ?></p>
                </div>
            </div>

            <?php if (empty($models)) : ?>
                <p><?php echo esc_html__('保存至少一个模型后，可以在这里测试文本和图像请求。', 'chuyi-ai-relay'); ?></p>
                <?php return; ?>
            <?php endif; ?>

            <div class="chuyi-ai-relay-test-grid">
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="chuyi-ai-relay-test-form chuyi-ai-relay-test-card" data-test-action="<?php echo esc_attr(self::TEST_TEXT_ACTION); ?>">
                    <?php wp_nonce_field(self::NONCE_ACTION); ?>
                    <input type="hidden" name="action" value="<?php echo esc_attr(self::TEST_TEXT_ACTION); ?>" />
                    <h3><?php echo esc_html__('测试文本', 'chuyi-ai-relay'); ?></h3>
                    <?php if (empty($textModels)) : ?>
                        <p class="description"><?php echo esc_html__('当前没有已标记为文本生成的模型。', 'chuyi-ai-relay'); ?></p>
                    <?php else : ?>
                        <?php self::renderModelSelect($textModels, 'text_model'); ?>
                        <p>
                            <label for="chuyi_ai_relay_text_prompt"><?php echo esc_html__('提示词', 'chuyi-ai-relay'); ?></label>
                            <textarea id="chuyi_ai_relay_text_prompt" name="prompt" class="large-text" rows="3">请用一句话回复：初一中转文本测试成功</textarea>
                        </p>
                        <?php submit_button(__('发送文本测试', 'chuyi-ai-relay'), 'secondary', 'submit', false); ?>
                        <span class="spinner" style="float:none;"></span>
                    <?php endif; ?>
                    <div class="chuyi-ai-relay-test-result chuyi-ai-relay-ajax-result" aria-live="polite"></div>
                </form>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="chuyi-ai-relay-test-form chuyi-ai-relay-test-card" data-test-action="<?php echo esc_attr(self::TEST_IMAGE_ACTION); ?>">
                    <?php wp_nonce_field(self::NONCE_ACTION); ?>
                    <input type="hidden" name="action" value="<?php echo esc_attr(self::TEST_IMAGE_ACTION); ?>" />
                    <h3><?php echo esc_html__('测试图像', 'chuyi-ai-relay'); ?></h3>
                    <?php if (empty($imageModels)) : ?>
                        <p class="description"><?php echo esc_html__('当前没有已标记为生图的模型。请先在“模型能力”里勾选生图并保存。', 'chuyi-ai-relay'); ?></p>
                    <?php else : ?>
                        <?php self::renderModelSelect($imageModels, 'image_model'); ?>
                        <p>
                            <label for="chuyi_ai_relay_image_prompt"><?php echo esc_html__('提示词', 'chuyi-ai-relay'); ?></label>
                            <textarea id="chuyi_ai_relay_image_prompt" name="prompt" class="large-text" rows="3">生成一张极简风格的蓝色圆形图标</textarea>
                        </p>
                        <?php submit_button(__('发送图像测试', 'chuyi-ai-relay'), 'secondary', 'submit', false); ?>
                        <span class="spinner" style="float:none;"></span>
                    <?php endif; ?>
                    <div class="chuyi-ai-relay-test-result chuyi-ai-relay-ajax-result" aria-live="polite"></div>
                </form>
            </div>
        </section>
        <?php
    }

    /**
     * Renders a saved model select field.
     *
     * @param list<array{id:string,name:string}> $models Saved model list.
     */
    private static function renderModelSelect(array $models, string $fieldId): void
    {
        ?>
        <p class="chuyi-ai-relay-field">
            <label for="chuyi_ai_relay_<?php echo esc_attr($fieldId); ?>"><?php echo esc_html__('模型', 'chuyi-ai-relay'); ?></label>
            <select id="chuyi_ai_relay_<?php echo esc_attr($fieldId); ?>" name="model" class="regular-text">
                <?php foreach ($models as $model) : ?>
                    <option value="<?php echo esc_attr($model['id']); ?>"><?php echo esc_html($model['name'] . ' — ' . $model['id']); ?></option>
                <?php endforeach; ?>
            </select>
        </p>
        <?php
    }

    /**
     * Filters saved models by an explicitly selected or inferred capability.
     *
     * @param list<array{id:string,name:string}> $models Saved model list.
     * @param array<string,list<string>>         $capabilities Saved model capabilities.
     * @return list<array{id:string,name:string}>
     */
    private static function filterModelsByCapability(array $models, array $capabilities, string $capability): array
    {
        $filtered = array();

        foreach ($models as $model) {
            $modelCapabilities = $capabilities[$model['id']] ?? Settings::inferCapabilities($model['id']);
            if (in_array($capability, $modelCapabilities, true)) {
                $filtered[] = $model;
            }
        }

        return $filtered;
    }

    /**
     * Checks whether a model is currently marked with a capability.
     */
    private static function modelSupportsCapability(string $modelId, string $capability): bool
    {
        if ($modelId === '') {
            return false;
        }

        $capabilities = Settings::getModelCapabilities();
        $modelCapabilities = $capabilities[$modelId] ?? Settings::inferCapabilities($modelId);

        return in_array($capability, $modelCapabilities, true);
    }

    /**
     * Sends a direct /chat/completions request for admin testing.
     *
     * @return array{ok:bool,detail:string,previewUrl?:string}
     */
    private static function requestChatCompletions(string $model, string $prompt, bool $wantsImage): array
    {
        $baseUrl = Settings::getBaseUrl();
        if ($baseUrl === '') {
            return array('ok' => false, 'detail' => __('请先填写有效的接口地址。', 'chuyi-ai-relay'));
        }

        $apiKey = Settings::getApiKey();
        if ($apiKey === '') {
            return array('ok' => false, 'detail' => __('请先到 Connectors 页面填写初一中转 API Key。', 'chuyi-ai-relay'));
        }

        if ($model === '') {
            return array('ok' => false, 'detail' => __('请选择要测试的模型。', 'chuyi-ai-relay'));
        }

        if ($wantsImage && !self::modelSupportsCapability($model, 'image_generation')) {
            return array('ok' => false, 'detail' => __('请选择已标记为生图的模型。', 'chuyi-ai-relay'));
        }

        if (!$wantsImage && !self::modelSupportsCapability($model, 'text_generation')) {
            return array('ok' => false, 'detail' => __('请选择已标记为文本生成的模型。', 'chuyi-ai-relay'));
        }

        $payload = array(
            'model'    => $model,
            'messages' => array(
                array(
                    'role'    => 'user',
                    'content' => $prompt,
                ),
            ),
        );
        if ($wantsImage) {
            $payload['modalities'] = array('image');
        } else {
            $payload['max_tokens'] = 128;
        }

        $body = wp_json_encode($payload);
        if (!is_string($body)) {
            return array('ok' => false, 'detail' => __('请求体编码失败。', 'chuyi-ai-relay'));
        }

        $response = wp_remote_post(
            rtrim($baseUrl, '/') . '/chat/completions',
            array(
                'timeout' => 60,
                'headers' => array(
                    'Accept'        => 'application/json',
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type'  => 'application/json',
                ),
                'body' => $body,
            )
        );

        if (is_wp_error($response)) {
            return array('ok' => false, 'detail' => $response->get_error_message());
        }

        $statusCode = (int) wp_remote_retrieve_response_code($response);
        $responseBody = (string) wp_remote_retrieve_body($response);
        if ($statusCode < 200 || $statusCode >= 300) {
            return array('ok' => false, 'detail' => 'HTTP ' . $statusCode . '：' . self::trimDetail($responseBody));
        }

        $data = json_decode($responseBody, true);
        if (!is_array($data)) {
            return array('ok' => true, 'detail' => self::trimDetail($responseBody));
        }

        if ($wantsImage) {
            $summary = self::summarizeImageResponse($data);
            return array(
                'ok'         => true,
                'detail'     => $summary['detail'] !== '' ? $summary['detail'] : __('请求成功，但响应中没有可展示内容。', 'chuyi-ai-relay'),
                'previewUrl' => $summary['previewUrl'],
            );
        }

        $detail = self::summarizeTextResponse($data);
        return array('ok' => true, 'detail' => $detail !== '' ? $detail : __('请求成功，但响应中没有可展示内容。', 'chuyi-ai-relay'));
    }

    /**
     * Extracts a text summary from a chat completions response.
     *
     * @param array<string,mixed> $data Decoded response.
     */
    private static function summarizeTextResponse(array $data): string
    {
        $fragments = array();

        if (isset($data['choices'][0]['message']['content'])) {
            self::collectTextFragments($data['choices'][0]['message']['content'], $fragments);
        }
        if (isset($data['choices'][0]['text'])) {
            self::collectTextFragments($data['choices'][0]['text'], $fragments);
        }
        if (isset($data['output_text'])) {
            self::collectTextFragments($data['output_text'], $fragments);
        }
        if (isset($data['output'])) {
            self::collectTextFragments($data['output'], $fragments);
        }

        $fragments = array_values(array_unique(array_filter($fragments)));
        if (!empty($fragments)) {
            return self::trimDetail(implode("\n", $fragments));
        }

        $cleanData = self::stripReasoningFromResponse($data);
        return self::trimDetail(wp_json_encode($cleanData) ?: '');
    }

    /**
     * Extracts an image summary from a chat completions response.
     *
     * @param array<string,mixed> $data Decoded response.
     * @return array{detail:string,previewUrl:string}
     */
    private static function summarizeImageResponse(array $data): array
    {
        $previewUrl = self::findImagePreviewUrl($data);
        if ($previewUrl !== '') {
            return array(
                'detail'     => $previewUrl,
                'previewUrl' => $previewUrl,
            );
        }

        $svg = self::findInlineSvg($data);
        if ($svg !== '') {
            return array(
                'detail'     => __('已返回 SVG 文本内容：', 'chuyi-ai-relay') . "\n" . self::trimDetail($svg),
                'previewUrl' => '',
            );
        }

        $text = self::summarizeTextResponse($data);
        return array(
            'detail'     => $text,
            'previewUrl' => '',
        );
    }

    /**
     * Detects response fragments that only describe model reasoning.
     *
     * @param mixed $value Response fragment.
     */
    private static function isReasoningFragment($value): bool
    {
        if (!is_array($value)) {
            return false;
        }

        foreach (array('type', 'role', 'name') as $key) {
            if (!isset($value[$key]) || !is_string($value[$key])) {
                continue;
            }

            $normalized = strtolower($value[$key]);
            if (strpos($normalized, 'reasoning') !== false || strpos($normalized, 'thinking') !== false || strpos($normalized, 'chain_of_thought') !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Checks whether a response key should be excluded from test output.
     */
    private static function isReasoningKey(string $key): bool
    {
        return in_array($key, array('reasoning', 'reasoning_content', 'thinking', 'thoughts', 'chain_of_thought'), true)
            || strpos($key, 'reasoning') !== false
            || strpos($key, 'thinking') !== false;
    }

    /**
     * Removes reasoning-only fields before falling back to raw JSON display.
     *
     * @param mixed $value Response fragment.
     * @return mixed
     */
    private static function stripReasoningFromResponse($value)
    {
        if (!is_array($value)) {
            return $value;
        }

        if (self::isReasoningFragment($value)) {
            return null;
        }

        $cleaned = array();
        foreach ($value as $key => $child) {
            if (is_string($key) && self::isReasoningKey(strtolower($key))) {
                continue;
            }

            $cleanedChild = self::stripReasoningFromResponse($child);
            if ($cleanedChild === null && is_array($child)) {
                continue;
            }
            $cleaned[$key] = $cleanedChild;
        }

        return $cleaned;
    }

    /**
     * Collects text fragments from common OpenAI-compatible response shapes.
     *
     * @param mixed        $value Response fragment.
     * @param list<string> $fragments Collected text fragments.
     */
    private static function collectTextFragments($value, array &$fragments): void
    {
        if (is_string($value)) {
            $value = trim($value);
            if ($value !== '') {
                $fragments[] = $value;
            }
            return;
        }

        if (!is_array($value) || self::isReasoningFragment($value)) {
            return;
        }

        foreach ($value as $key => $child) {
            if (is_int($key)) {
                self::collectTextFragments($child, $fragments);
                continue;
            }

            if (!is_string($key)) {
                continue;
            }

            $normalizedKey = strtolower($key);
            if (self::isReasoningKey($normalizedKey)) {
                continue;
            }
            if (in_array($normalizedKey, array('content', 'text', 'output_text', 'caption', 'description'), true)) {
                self::collectTextFragments($child, $fragments);
            }
        }
    }

    /**
     * Finds an explicit image URL or data URI without scanning arbitrary text URLs.
     *
     * @param mixed $value Response fragment.
     */
    private static function findImagePreviewUrl($value): string
    {
        if (is_string($value)) {
            $value = trim($value);
            if (preg_match('#^data:image/[a-z0-9.+-]+;base64,#i', $value)) {
                return $value;
            }
            if (preg_match('#!\[[^\]]*\]\((https?://[^)]+)\)#i', $value, $matches)) {
                return self::normalizeImageUrl($matches[1], false);
            }
            return '';
        }

        if (!is_array($value)) {
            return '';
        }

        foreach ($value as $key => $child) {
            $normalizedKey = is_string($key) ? strtolower($key) : '';
            if (in_array($normalizedKey, array('url', 'image_url', 'src', 'href'), true)) {
                if (is_string($child)) {
                    $url = self::normalizeImageUrl($child, true);
                    if ($url !== '') {
                        return $url;
                    }
                }
                if (is_array($child)) {
                    $url = self::findImagePreviewUrl($child);
                    if ($url !== '') {
                        return $url;
                    }
                }
            }

            if (in_array($normalizedKey, array('b64_json', 'base64'), true) && is_string($child)) {
                $base64 = preg_replace('/\s+/', '', $child);
                if (is_string($base64) && preg_match('#^[a-z0-9+/]{120,}={0,2}$#i', $base64)) {
                    return 'data:image/png;base64,' . $base64;
                }
            }

            if (is_int($key) || in_array($normalizedKey, array('data', 'images', 'image', 'content', 'message', 'choices', 'output'), true)) {
                $url = self::findImagePreviewUrl($child);
                if ($url !== '') {
                    return $url;
                }
            }
        }

        return '';
    }

    /**
     * Normalizes a URL that is expected to point to an image.
     */
    private static function normalizeImageUrl(string $url, bool $explicitImageField): string
    {
        $url = trim($url);
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            return '';
        }

        $url = rtrim($url, ".,;:!?)\"]'");
        if (preg_match('#^https?://www\.w3\.org/2000/svg#i', $url)) {
            return '';
        }

        if (!$explicitImageField && !preg_match('#\.(png|jpe?g|gif|webp|svg)(?:[?#]|$)#i', $url)) {
            return '';
        }

        return esc_url_raw($url);
    }

    /**
     * Finds inline SVG content returned as text.
     *
     * @param mixed $value Response fragment.
     */
    private static function findInlineSvg($value): string
    {
        if (is_string($value)) {
            $value = trim($value);
            return stripos($value, '<svg') !== false ? $value : '';
        }

        if (!is_array($value)) {
            return '';
        }

        foreach ($value as $child) {
            $svg = self::findInlineSvg($child);
            if ($svg !== '') {
                return $svg;
            }
        }

        return '';
    }

    /**
     * Keeps messages compact and readable.
     */
    private static function trimDetail(string $detail): string
    {
        $detail = trim($detail);
        if (function_exists('mb_substr')) {
            return mb_substr($detail, 0, 220);
        }

        return substr($detail, 0, 220);
    }

    /**
     * Renders the model capability table.
     *
     * @param list<array{id:string,name:string}> $models Saved model list.
     * @param array<string,list<string>>         $capabilities Saved model capabilities.
     */
    private static function renderModelsTable(array $models, array $capabilities): void
    {
        ?>
        <div class="chuyi-ai-relay-capability-panel__header">
            <h2><?php echo esc_html__('模型能力', 'chuyi-ai-relay'); ?></h2>
            <p><?php echo esc_html__('如果中转站的模型命名不标准，请在这里手动勾选。生图模型会被 WordPress 自带生图功能识别。', 'chuyi-ai-relay'); ?></p>
        </div>

        <?php if (empty($models)) : ?>
            <p class="chuyi-ai-relay-capability-panel__empty"><?php echo esc_html__('还没有模型。请先保存接口地址和 API Key，然后点击“一键获取模型”。', 'chuyi-ai-relay'); ?></p>
            <?php return; ?>
        <?php endif; ?>

        <div class="chuyi-ai-relay-capability-panel__body">
        <table class="widefat striped chuyi-ai-relay-model-table">
            <thead>
                <tr>
                    <th><?php echo esc_html__('模型', 'chuyi-ai-relay'); ?></th>
                    <th><?php echo esc_html__('文本生成', 'chuyi-ai-relay'); ?></th>
                    <th><?php echo esc_html__('视觉输入', 'chuyi-ai-relay'); ?></th>
                    <th><?php echo esc_html__('生图', 'chuyi-ai-relay'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($models as $model) : ?>
                    <?php $modelCapabilities = $capabilities[$model['id']] ?? Settings::inferCapabilities($model['id']); ?>
                    <tr>
                        <td data-label="<?php echo esc_attr__('模型', 'chuyi-ai-relay'); ?>">
                            <strong><?php echo esc_html($model['name']); ?></strong><br />
                            <code><?php echo esc_html($model['id']); ?></code>
                        </td>
                        <td data-label="<?php echo esc_attr__('文本生成', 'chuyi-ai-relay'); ?>">
                            <label>
                                <input
                                    type="checkbox"
                                    name="capabilities[<?php echo esc_attr($model['id']); ?>][]"
                                    value="text_generation"
                                    <?php checked(in_array('text_generation', $modelCapabilities, true)); ?>
                                />
                                <?php echo esc_html__('支持', 'chuyi-ai-relay'); ?>
                            </label>
                        </td>
                        <td data-label="<?php echo esc_attr__('视觉输入', 'chuyi-ai-relay'); ?>">
                            <label>
                                <input
                                    type="checkbox"
                                    name="capabilities[<?php echo esc_attr($model['id']); ?>][]"
                                    value="vision"
                                    <?php checked(in_array('vision', $modelCapabilities, true)); ?>
                                />
                                <?php echo esc_html__('支持', 'chuyi-ai-relay'); ?>
                            </label>
                        </td>
                        <td data-label="<?php echo esc_attr__('生图', 'chuyi-ai-relay'); ?>">
                            <label>
                                <input
                                    type="checkbox"
                                    name="capabilities[<?php echo esc_attr($model['id']); ?>][]"
                                    value="image_generation"
                                    <?php checked(in_array('image_generation', $modelCapabilities, true)); ?>
                                />
                                <?php echo esc_html__('支持', 'chuyi-ai-relay'); ?>
                            </label>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p class="description">
            <?php echo esc_html__('注意：如果勾选“生图”，插件会把该模型作为生图模型处理，不再同时声明为文本模型。', 'chuyi-ai-relay'); ?>
        </p>
        </div>
        <?php
    }

    /**
     * Shows the latest action result.
     */
    private static function renderNotice(): void
    {
        $message = isset($_GET['chuyi_ai_relay_message']) ? sanitize_key((string) wp_unslash($_GET['chuyi_ai_relay_message'])) : '';
        $detail = isset($_GET['chuyi_ai_relay_detail']) ? sanitize_text_field((string) wp_unslash($_GET['chuyi_ai_relay_detail'])) : '';

        if ($message === '') {
            return;
        }

        $type = 'notice-success';
        $text = '';

        switch ($message) {
            case self::MESSAGE_SAVED:
                return;
            case self::MESSAGE_FETCHED:
                $text = __('模型已获取并保存。', 'chuyi-ai-relay');
                break;
            case self::MESSAGE_TEST_TEXT_OK:
                $text = __('文本测试成功。', 'chuyi-ai-relay');
                if ($detail !== '') {
                    $text .= ' ' . $detail;
                }
                break;
            case self::MESSAGE_TEST_IMAGE_OK:
                $text = __('图像测试请求成功。', 'chuyi-ai-relay');
                if ($detail !== '') {
                    $text .= ' ' . $detail;
                }
                break;
            case self::MESSAGE_TEST_FAILED:
                $type = 'notice-error';
                $text = __('测试失败。', 'chuyi-ai-relay');
                if ($detail !== '') {
                    $text .= ' ' . $detail;
                }
                break;
            case self::MESSAGE_NO_BASE_URL:
                $type = 'notice-error';
                $text = __('请先填写有效的接口地址。', 'chuyi-ai-relay');
                break;
            case self::MESSAGE_NO_API_KEY:
                $type = 'notice-error';
                $text = __('请先到 Connectors 页面填写初一中转 API Key。', 'chuyi-ai-relay');
                break;
            case self::MESSAGE_FETCH_FAILED:
                $type = 'notice-error';
                $text = __('获取模型失败。', 'chuyi-ai-relay');
                if ($detail !== '') {
                    $text .= ' ' . $detail;
                }
                break;
            case self::MESSAGE_NO_MODELS:
                $type = 'notice-error';
                $text = __('接口没有返回可用模型。', 'chuyi-ai-relay');
                break;
        }

        if ($text === '') {
            return;
        }

        echo '<div class="notice ' . esc_attr($type) . ' is-dismissible"><p>' . esc_html($text) . '</p></div>';
    }

    /**
     * Validates capability and nonce for admin-post handlers.
     */
    private static function assertCanManage(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('你没有权限管理初一中转。', 'chuyi-ai-relay'));
        }

        check_admin_referer(self::NONCE_ACTION);
    }

    /**
     * Validates capability and nonce for admin AJAX handlers.
     */
    private static function assertCanAjaxManage(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('你没有权限管理初一中转。', 'chuyi-ai-relay')), 403);
        }

        if (!check_ajax_referer(self::NONCE_ACTION, 'nonce', false)) {
            wp_send_json_error(array('message' => __('安全校验失败，请刷新页面后重试。', 'chuyi-ai-relay')), 403);
        }
    }

    /**
     * Redirects back to the settings page.
     */
    private static function redirect(string $message, string $detail = ''): void
    {
        $args = array('chuyi_ai_relay_message' => $message);
        if ($detail !== '') {
            $args['chuyi_ai_relay_detail'] = substr(wp_strip_all_tags($detail), 0, 240);
        }

        wp_safe_redirect(add_query_arg($args, self::getPageUrl()));
        exit;
    }

    /**
     * Returns admin styles that mirror the Connectors page card layout.
     */
    private static function getInlineStyles(): string
    {
        return <<<'CSS'
.chuyi-ai-relay-wrap {
    margin: 0 0 0 -20px;
}
.chuyi-ai-relay-page {
    background: #f6f7f7;
    color: #1e1e1e;
    min-height: calc(100vh - 32px);
}
.chuyi-ai-relay-page__header {
    background: #fff;
    border-bottom: 1px solid #dcdcde;
    position: sticky;
    top: 32px;
    z-index: 10;
}
.chuyi-ai-relay-page__header-inner {
    align-items: center;
    display: flex;
    gap: 24px;
    justify-content: space-between;
    min-height: 72px;
    padding: 14px 32px;
}
.chuyi-ai-relay-brand {
    align-items: center;
    display: flex;
    gap: 14px;
    min-width: 0;
}
.chuyi-ai-relay-brand__visual {
    align-items: center;
    background: linear-gradient(135deg, #f0f6ff, #fff);
    border: 1px solid #d7e7ff;
    border-radius: 10px;
    box-shadow: 0 1px 1px rgba(0, 0, 0, 0.04);
    display: inline-flex;
    height: 44px;
    justify-content: center;
    width: 44px;
}
.chuyi-ai-relay-logo {
    display: block;
    height: 28px;
    width: 28px;
}
.chuyi-ai-relay-brand__copy {
    min-width: 0;
}
.chuyi-ai-relay-eyebrow,
.chuyi-ai-relay-section-label,
.chuyi-ai-relay-overview-card > span {
    color: #757575;
    display: block;
    font-size: 11px;
    font-weight: 600;
    letter-spacing: 0.06em;
    line-height: 16px;
    text-transform: uppercase;
}
.chuyi-ai-relay-brand h1 {
    font-size: 20px;
    font-weight: 500;
    line-height: 28px;
    margin: 0;
}
.chuyi-ai-relay-brand p,
.chuyi-ai-relay-card__header p,
.chuyi-ai-relay-overview-card p {
    color: #757575;
    font-size: 13px;
    line-height: 20px;
    margin: 0;
}
.chuyi-ai-relay-header-actions {
    align-items: center;
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    justify-content: flex-end;
}
.chuyi-ai-relay-page__content {
    box-sizing: border-box;
    display: grid;
    gap: 20px;
    margin: 0 auto;
    max-width: 1440px;
    padding: 28px 32px 116px;
    width: 100%;
}
.chuyi-ai-relay-settings-form,
.chuyi-ai-relay-main-stack,
.chuyi-ai-relay-sidebar,
#chuyi-ai-relay-test-forms {
    display: grid;
    gap: 20px;
}
.chuyi-ai-relay-layout {
    align-items: start;
    display: grid;
    gap: 20px;
    grid-template-columns: minmax(300px, 420px) minmax(0, 1fr);
}
.chuyi-ai-relay-overview,
.chuyi-ai-relay-test-grid {
    display: grid;
    gap: 16px;
}
.chuyi-ai-relay-overview {
    grid-template-columns: repeat(3, minmax(0, 1fr));
}
.chuyi-ai-relay-test-grid {
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
}
.chuyi-ai-relay-overview-card,
.chuyi-ai-relay-card,
.chuyi-ai-relay-test-card {
    background: #fff;
    border: 1px solid #dcdcde;
    border-radius: 4px;
    box-sizing: border-box;
    box-shadow: 0 1px 1px rgba(0, 0, 0, 0.02);
}
.chuyi-ai-relay-overview-card {
    display: grid;
    gap: 6px;
    min-height: 112px;
    padding: 16px;
}
.chuyi-ai-relay-overview-card strong {
    display: block;
    font-size: 18px;
    font-weight: 500;
    line-height: 26px;
    overflow-wrap: anywhere;
}
.chuyi-ai-relay-card,
.chuyi-ai-relay-test-card {
    padding: 20px;
}
.chuyi-ai-relay-card--hero {
    background: linear-gradient(180deg, #fff, #fbfcff);
}
.chuyi-ai-relay-card--flush {
    overflow-x: auto;
    padding: 0;
}
.chuyi-ai-relay-card--sticky {
    position: sticky;
    top: 124px;
}
.chuyi-ai-relay-capability-panel {
    overflow: hidden;
    padding: 0;
}
.chuyi-ai-relay-capability-panel > #chuyi-ai-relay-models-table {
    display: grid;
    grid-template-rows: auto minmax(0, 1fr);
    max-height: calc(100vh - 164px);
}
.chuyi-ai-relay-capability-panel__header {
    padding: 20px;
}
.chuyi-ai-relay-capability-panel__header p,
.chuyi-ai-relay-capability-panel__empty {
    color: #757575;
    font-size: 13px;
    line-height: 20px;
    margin: 0;
}
.chuyi-ai-relay-capability-panel__empty {
    padding: 0 20px 20px;
}
.chuyi-ai-relay-capability-panel__body {
    border-top: 1px solid #dcdcde;
    max-height: calc(100vh - 280px);
    overflow: auto;
}
.chuyi-ai-relay-card__header {
    align-items: flex-start;
    display: flex;
    gap: 16px;
    justify-content: space-between;
    margin-bottom: 18px;
}
.chuyi-ai-relay-card h2,
.chuyi-ai-relay-test-card h3 {
    color: #1e1e1e;
    font-size: 16px;
    font-weight: 500;
    line-height: 24px;
    margin: 2px 0 0;
}
.chuyi-ai-relay-card--flush h2,
.chuyi-ai-relay-card--flush > p,
.chuyi-ai-relay-card--flush .description {
    margin-left: 20px;
    margin-right: 20px;
}
.chuyi-ai-relay-card--flush h2 {
    padding-top: 20px;
}
.chuyi-ai-relay-field {
    display: grid;
    gap: 8px;
    margin: 0 0 12px;
}
.chuyi-ai-relay-field > span,
.chuyi-ai-relay-field > label,
.chuyi-ai-relay-test-card label {
    color: #1e1e1e;
    font-weight: 500;
}
.chuyi-ai-relay-field input,
.chuyi-ai-relay-field select,
.chuyi-ai-relay-field textarea,
.chuyi-ai-relay-test-card select,
.chuyi-ai-relay-test-card textarea {
    max-width: 100%;
    width: 100%;
}
.chuyi-ai-relay-field input[type="url"],
.chuyi-ai-relay-card textarea[name="models"],
.chuyi-ai-relay-test-card textarea,
.chuyi-ai-relay-test-card select {
    border-color: #c3c4c7;
    border-radius: 4px;
}
.chuyi-ai-relay-card textarea[name="models"] {
    min-height: 168px;
}
.chuyi-ai-relay-floating-save {
    background: transparent;
    bottom: 24px;
    padding: 0;
    position: fixed;
    right: 32px;
    z-index: 1001;
}
.chuyi-ai-relay-floating-save .submit {
    margin: 0;
    padding: 0;
}
.chuyi-ai-relay-floating-save .button {
    box-shadow: 0 8px 28px rgba(0, 0, 0, 0.14);
}
.chuyi-ai-relay-card__actions,
.chuyi-ai-relay-form-actions {
    align-items: center;
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 16px;
}
.chuyi-ai-relay-form-actions .submit {
    margin: 0;
    padding: 0;
}
.chuyi-ai-relay-button-wide {
    justify-content: center;
    text-align: center;
    width: 100%;
}
.chuyi-ai-relay-status {
    border-radius: 999px;
    font-size: 12px;
    line-height: 22px;
    padding: 0 10px;
    white-space: nowrap;
}
.chuyi-ai-relay-status.is-ok,
.chuyi-ai-relay-sidebar-status .is-ok {
    background: #edfaef;
    color: #008a20;
}
.chuyi-ai-relay-status.is-warning,
.chuyi-ai-relay-sidebar-status .is-warning {
    background: #fcf0f1;
    color: #b32d2e;
}
.chuyi-ai-relay-sidebar-status {
    align-items: center;
    background: #f6f7f7;
    border: 1px solid #e0e0e0;
    border-radius: 4px;
    display: flex;
    justify-content: space-between;
    margin-bottom: 12px;
    padding: 12px;
}
.chuyi-ai-relay-sidebar-status span {
    color: #646970;
}
.chuyi-ai-relay-sidebar-status strong {
    border-radius: 999px;
    font-size: 12px;
    font-weight: 500;
    line-height: 22px;
    padding: 0 10px;
}
.chuyi-ai-relay-steps {
    border-top: 1px solid #dcdcde;
    display: grid;
    gap: 10px;
    margin-top: 16px;
    padding-top: 16px;
}
.chuyi-ai-relay-steps div {
    align-items: center;
    color: #50575e;
    display: flex;
    font-size: 13px;
    gap: 8px;
}
.chuyi-ai-relay-steps span {
    align-items: center;
    background: #f0f0f1;
    border-radius: 999px;
    color: #1e1e1e;
    display: inline-flex;
    flex: 0 0 22px;
    font-size: 12px;
    height: 22px;
    justify-content: center;
    width: 22px;
}
.chuyi-ai-relay-card table.widefat {
    border: 0;
    box-shadow: none;
    margin: 0;
    max-width: none;
}
.chuyi-ai-relay-model-table {
    min-width: 640px;
}
.chuyi-ai-relay-card table.widefat thead th {
    background: #f6f7f7;
    border-bottom: 1px solid #dcdcde;
    color: #50575e;
    font-size: 12px;
    font-weight: 600;
    position: sticky;
    text-transform: uppercase;
    top: 0;
    z-index: 1;
}
.chuyi-ai-relay-card table.widefat td,
.chuyi-ai-relay-card table.widefat th {
    padding: 14px 20px;
    vertical-align: middle;
}
.chuyi-ai-relay-card table.widefat code {
    background: #f6f7f7;
    border-radius: 3px;
    color: #50575e;
    display: inline-block;
    margin-top: 4px;
    padding: 2px 6px;
}
.chuyi-ai-relay-test-section {
    margin-top: 0;
}
.chuyi-ai-relay-test-card {
    display: grid;
    gap: 12px;
}
.chuyi-ai-relay-test-card p {
    margin: 0;
}
.chuyi-ai-relay-ajax-result .notice {
    margin: 12px 0 0;
}
.chuyi-ai-relay-page .notice {
    margin-left: 0;
    margin-right: 0;
}
@media screen and (max-width: 1200px) {
    .chuyi-ai-relay-layout {
        grid-template-columns: 1fr;
    }
    .chuyi-ai-relay-card--sticky {
        position: static;
    }
    .chuyi-ai-relay-capability-panel > #chuyi-ai-relay-models-table,
    .chuyi-ai-relay-capability-panel__body {
        max-height: none;
    }
}
@media screen and (max-width: 960px) {
    .chuyi-ai-relay-overview,
    .chuyi-ai-relay-test-grid {
        grid-template-columns: 1fr;
    }
    .chuyi-ai-relay-page__header-inner {
        align-items: flex-start;
        flex-direction: column;
    }
    .chuyi-ai-relay-header-actions {
        justify-content: flex-start;
    }
}
@media screen and (max-width: 782px) {
    .chuyi-ai-relay-wrap {
        margin-left: -10px;
    }
    .chuyi-ai-relay-page__header {
        position: static;
    }
    .chuyi-ai-relay-page__header-inner,
    .chuyi-ai-relay-page__content {
        padding-left: 14px;
        padding-right: 14px;
    }
    .chuyi-ai-relay-page__content {
        padding-bottom: 132px;
    }
    .chuyi-ai-relay-brand {
        align-items: flex-start;
    }
    .chuyi-ai-relay-brand__visual {
        height: 38px;
        width: 38px;
    }
    .chuyi-ai-relay-logo {
        height: 24px;
        width: 24px;
    }
    .chuyi-ai-relay-card,
    .chuyi-ai-relay-test-card,
    .chuyi-ai-relay-capability-panel__header {
        padding: 16px;
    }
    .chuyi-ai-relay-capability-panel {
        padding: 0;
    }
    .chuyi-ai-relay-capability-panel__body {
        overflow: visible;
    }
    .chuyi-ai-relay-model-table,
    .chuyi-ai-relay-model-table thead,
    .chuyi-ai-relay-model-table tbody,
    .chuyi-ai-relay-model-table tr,
    .chuyi-ai-relay-model-table td {
        display: block;
        min-width: 0;
        width: 100%;
    }
    .chuyi-ai-relay-model-table thead {
        display: none;
    }
    .chuyi-ai-relay-model-table tr {
        border-bottom: 1px solid #dcdcde;
        padding: 12px 0;
    }
    .chuyi-ai-relay-model-table td {
        align-items: center;
        box-sizing: border-box;
        display: flex;
        justify-content: space-between;
        padding: 8px 16px !important;
    }
    .chuyi-ai-relay-model-table td::before {
        color: #646970;
        content: attr(data-label);
        flex: 0 0 86px;
        font-size: 12px;
        font-weight: 600;
    }
    .chuyi-ai-relay-model-table td:first-child {
        align-items: flex-start;
        display: block;
    }
    .chuyi-ai-relay-model-table td:first-child::before {
        display: none;
    }
    .chuyi-ai-relay-floating-save {
        bottom: 10px;
        left: auto;
        right: 10px;
    }
}
CSS;
    }

    /**
     * Returns the settings page URL.
     */
    private static function getPageUrl(): string
    {
        return admin_url('options-general.php?page=' . self::MENU_SLUG);
    }
}