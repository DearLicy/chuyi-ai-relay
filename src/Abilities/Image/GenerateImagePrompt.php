<?php
/**
 * Image prompt generation ability for 初一 AI 中转.
 *
 * @package WordPress\ChuyiAiRelay\Abilities\Image
 */

declare(strict_types=1);

namespace WordPress\ChuyiAiRelay\Abilities\Image;

use WP_Error;
use WordPress\AI\Abilities\Image\Generate_Image_Prompt as CoreGenerateImagePrompt;
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;
use WordPress\ChuyiAiRelay\Settings;

/**
 * Keeps the AI plugin image prompt generation flow, but pins this prompt stage to relay text models first.
 */
final class GenerateImagePrompt extends CoreGenerateImagePrompt
{
    /**
     * @param string|array<string,string> $context
     * @return string|WP_Error
     */
    protected function generate_prompt(string $content, $context, string $style)
    {
        if (is_array($context)) {
            $context = implode(
                "\n",
                array_map(
                    static function ($key, $value): string {
                        return sprintf('%s: %s', ucwords(str_replace('_', ' ', (string) $key)), (string) $value);
                    },
                    array_keys($context),
                    $context
                )
            );
        }

        $prompt = '<content>' . $content . '</content>';

        if ($context) {
            $prompt .= "\n\n<additional-context>" . $context . '</additional-context>';
        }

        if ($style) {
            $prompt .= "\n\n<style>" . $style . '</style>';
        }

        $relayTextModels = $this->getRelayTextModelPreference();
        $this->logRelayTextModelPreference($relayTextModels);
        if (empty($relayTextModels)) {
            return new WP_Error(
                'chuyi_relay_no_prompt_text_model',
                esc_html__('图片提示词生成需要在当前中转配置中添加 text_generation 或 vision 文本模型。', 'chuyi-ai-relay')
            );
        }

        $lastError = null;
        foreach ($relayTextModels as $relayTextModel) {
            $promptBuilder = $this->getRelayPromptBuilder($prompt, $relayTextModel);
            if (is_wp_error($promptBuilder)) {
                $lastError = $promptBuilder;
                continue;
            }

            $result = $promptBuilder->generate_text();
            if (is_wp_error($result)) {
                $lastError = $result;
                continue;
            }

            if (!empty($result)) {
                return $result;
            }

            $lastError = new WP_Error(
                'chuyi_relay_empty_prompt_result',
                esc_html__('图片提示词生成返回为空。', 'chuyi-ai-relay')
            );
        }

        return $lastError instanceof WP_Error ? $lastError : new WP_Error(
            'chuyi_relay_prompt_generation_failed',
            esc_html__('图片提示词生成失败，所有中转文本模型均不可用。', 'chuyi-ai-relay')
        );
    }

    /**
     * @param array{string, string} $relayTextModel
     * @return \WP_AI_Client_Prompt_Builder|WP_Error
     */
    private function getRelayPromptBuilder(string $prompt, array $relayTextModel)
    {
        $requestOptions = new RequestOptions();
        $requestOptions->setTimeout((float) Settings::getImageGenerationTimeout());

        $promptBuilder = wp_ai_client_prompt($prompt)
            ->using_system_instruction($this->get_system_instruction('image-prompt-system-instruction.php'))
            ->using_temperature(0.9)
            ->using_request_options($requestOptions)
            ->using_model_preference($relayTextModel);

        return $this->ensure_text_generation_supported(
            $promptBuilder,
            esc_html__('Image prompt generation failed. Please ensure you have a connected relay provider that supports text generation.', 'chuyi-ai-relay')
        );
    }

    /**
     * @return array<int, array{string, string}>
     */
    private function getRelayTextModelPreference(): array
    {
        $preferred = $this->getConfiguredPromptModelPreference();
        $seen = array();
        foreach ($preferred as $item) {
            if (!is_array($item) || count($item) < 2) {
                continue;
            }

            $providerId = is_string($item[0]) ? $item[0] : '';
            $modelId = is_string($item[1]) ? $item[1] : '';
            if ($providerId === '' || $modelId === '') {
                continue;
            }

            $seen[$providerId . '|' . $modelId] = true;
        }

        $imageProviderId = $this->getConfiguredImageProviderId();
        if ($imageProviderId !== '') {
            $slotId = Settings::getSlotIdForProviderId($imageProviderId);
            $slot = Settings::getSlot($slotId);
            $this->appendRelayTextModels($preferred, $seen, $imageProviderId, $slot);
        }

        return $preferred;
    }

    /**
     * Returns the provider selected by the image-generation setting.
     */
    private function getConfiguredImageProviderId(): string
    {
        $configured = get_option('wpai_feature_image-generation_field_developer', array());
        if (!is_array($configured) || empty($configured['provider']) || !is_string($configured['provider'])) {
            return '';
        }

        $providerId = sanitize_key($configured['provider']);
        return strpos($providerId, 'chuyi-relay') === 0 ? $providerId : '';
    }

    /**
     * @return array<int, array{string, string}>
     */
    private function getConfiguredPromptModelPreference(): array
    {
        $configured = get_option('wpai_feature_image-prompt-generation_field_developer', array());
        if (!is_array($configured) || empty($configured['provider']) || empty($configured['model'])) {
            return array();
        }

        $providerId = is_string($configured['provider']) ? sanitize_key($configured['provider']) : '';
        $modelId = is_string($configured['model']) ? sanitize_text_field($configured['model']) : '';
        if ($providerId === '' || $modelId === '' || strpos($providerId, 'chuyi-relay') !== 0) {
            return array();
        }

        $slotId = Settings::getSlotIdForProviderId($providerId);
        $slot = Settings::getSlot($slotId);
        if (!$this->slotHasTextModel($slot, $modelId)) {
            return array();
        }

        return array(array($providerId, $modelId));
    }

    /**
     * @param array<string, mixed> $slot
     */
    private function slotHasTextModel(array $slot, string $modelId): bool
    {
        $models = isset($slot['models']) && is_array($slot['models']) ? $slot['models'] : array();
        foreach ($models as $model) {
            if (!is_array($model) || empty($model['id']) || !is_string($model['id']) || $model['id'] !== $modelId) {
                continue;
            }

            $capabilities = isset($model['capabilities']) && is_array($model['capabilities'])
                ? Settings::sanitizeCapabilities($model['capabilities'])
                : Settings::inferCapabilities($modelId);

            return !empty(array_intersect(array('text_generation', 'vision'), $capabilities));
        }

        return false;
    }

    /**
     * @param array<int, array{string, string}> $preferred
     * @param array<string, bool> $seen
     * @param array<string, mixed> $slot
     */
    private function appendRelayTextModels(array &$preferred, array &$seen, string $providerId, array $slot): void
    {
        $models = isset($slot['models']) && is_array($slot['models']) ? $slot['models'] : array();
        foreach ($models as $model) {
            if (!is_array($model) || empty($model['id']) || !is_string($model['id'])) {
                continue;
            }

            $capabilities = isset($model['capabilities']) && is_array($model['capabilities'])
                ? Settings::sanitizeCapabilities($model['capabilities'])
                : Settings::inferCapabilities($model['id']);
            if (empty(array_intersect(array('text_generation', 'vision'), $capabilities))) {
                continue;
            }

            $key = $providerId . '|' . $model['id'];
            if (isset($seen[$key])) {
                continue;
            }

            $preferred[] = array($providerId, $model['id']);
            $seen[$key] = true;
        }
    }

    /**
     * @param array<int, array{string, string}> $relayTextModels
     */
    private function logRelayTextModelPreference(array $relayTextModels): void
    {
        $items = array();
        foreach ($relayTextModels as $relayTextModel) {
            if (!is_array($relayTextModel) || count($relayTextModel) < 2) {
                continue;
            }

            $providerId = is_string($relayTextModel[0]) ? $relayTextModel[0] : '';
            $modelId = is_string($relayTextModel[1]) ? $relayTextModel[1] : '';
            if ($providerId === '' || $modelId === '') {
                continue;
            }

            $slotId = Settings::getSlotIdForProviderId($providerId);
            $items[] = sprintf('%s/%s model=%s base_url=%s', $providerId, $slotId, $modelId, Settings::getBaseUrl($slotId));
        }

        error_log('[chuyi-ai-relay] image prompt text model preference ' . (empty($items) ? 'empty' : implode(' | ', $items)));
    }
}
