<?php
/**
 * Fully-owned base64 image import ability for 初一 AI 中转.
 *
 * @package WordPress\ChuyiAiRelay\Abilities\Image
 */

declare(strict_types=1);

namespace WordPress\ChuyiAiRelay\Abilities\Image;

use WordPress\AI\Abilities\Image\Import_Base64_Image as CoreImportBase64Image;

/**
 * Keeps media import behavior compatible with the AI plugin frontend.
 */
final class ImportBase64Image extends CoreImportBase64Image
{
}