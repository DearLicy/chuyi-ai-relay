<?php
/**
 * Fully-owned image generation ability for 初一 AI 中转.
 *
 * @package WordPress\ChuyiAiRelay\Abilities\Image
 */

declare(strict_types=1);

namespace WordPress\ChuyiAiRelay\Abilities\Image;

use Throwable;
use WP_Error;
use WordPress\AI\Abilities\Image\Generate_Image as CoreGenerateImage;

/**
 * Keeps the public AI ability name while routing execution through relay-controlled image models.
 */
final class GenerateImage extends CoreGenerateImage
{
    /**
     * @return array{data: string, provider_metadata: array<string, string>, model_metadata: array<string, string>}|WP_Error
     */
    protected function generate_image(string $prompt, ?string $reference_image = null)
    {
        try {
            return parent::generate_image($prompt, $reference_image);
        } catch (Throwable $throwable) {
            return new WP_Error(
                'chuyi_relay_image_generation_failed',
                $throwable->getMessage() !== ''
                    ? $throwable->getMessage()
                    : esc_html__('图片生成失败。', 'chuyi-ai-relay')
            );
        }
    }
}