<?php
/**
 * Default prompt catalog backed by the official AI plugin instruction files.
 *
 * @package WordPress\ChuyiAiRelay\Prompts
 */

declare(strict_types=1);

namespace WordPress\ChuyiAiRelay\Prompts;

if (!defined('ABSPATH')) {
    return;
}

final class DefaultPrompts
{
    /**
     * Returns managed AI ability prompt definitions.
     *
     * @return array<string,array{ability:string,label:string,description:string,file:string,data:array<string,mixed>}>
     */
    public static function definitions(): array
    {
        return array(
            'ai/title-generation' => array(
                'ability'     => 'ai/title-generation',
                'label'       => __('标题生成', 'chuyi-ai-relay'),
                'description' => __('AI 标题生成能力使用的默认系统提示词。', 'chuyi-ai-relay'),
                'file'        => 'Title_Generation/system-instruction.php',
                'data'        => array(),
            ),
            'ai/excerpt-generation' => array(
                'ability'     => 'ai/excerpt-generation',
                'label'       => __('摘要生成', 'chuyi-ai-relay'),
                'description' => __('AI 摘要生成能力使用的默认系统提示词。', 'chuyi-ai-relay'),
                'file'        => 'Excerpt_Generation/system-instruction.php',
                'data'        => array(),
            ),
            'ai/summarization' => array(
                'ability'     => 'ai/summarization',
                'label'       => __('内容总结', 'chuyi-ai-relay'),
                'description' => __('AI 内容总结能力使用的默认系统提示词。可编辑基线使用中等长度版本。', 'chuyi-ai-relay'),
                'file'        => 'Summarization/system-instruction.php',
                'data'        => array('length' => 'medium'),
            ),
            'ai/meta-description' => array(
                'ability'     => 'ai/meta-description',
                'label'       => __('Meta 描述', 'chuyi-ai-relay'),
                'description' => __('AI Meta 描述能力使用的默认系统提示词。', 'chuyi-ai-relay'),
                'file'        => 'Meta_Description/system-instruction.php',
                'data'        => array(),
            ),
            'ai/content-classification' => array(
                'ability'     => 'ai/content-classification',
                'label'       => __('内容分类', 'chuyi-ai-relay'),
                'description' => __('AI 内容分类能力使用的默认系统提示词。', 'chuyi-ai-relay'),
                'file'        => 'Content_Classification/system-instruction.php',
                'data'        => array(),
            ),
            'ai/content-resizing' => array(
                'ability'     => 'ai/content-resizing',
                'label'       => __('内容改写', 'chuyi-ai-relay'),
                'description' => __('AI 内容改写能力使用的默认系统提示词。可编辑基线使用重述版本。', 'chuyi-ai-relay'),
                'file'        => 'Content_Resizing/system-instruction.php',
                'data'        => array('action' => 'rephrase'),
            ),
            'ai/editorial-notes' => array(
                'ability'     => 'ai/editorial-notes',
                'label'       => __('编辑建议', 'chuyi-ai-relay'),
                'description' => __('AI 编辑建议能力使用的默认系统提示词。', 'chuyi-ai-relay'),
                'file'        => 'Editorial_Notes/system-instruction.php',
                'data'        => array('block_name' => 'core/paragraph'),
            ),
            'ai/editorial-updates' => array(
                'ability'     => 'ai/editorial-updates',
                'label'       => __('编辑更新', 'chuyi-ai-relay'),
                'description' => __('AI 编辑更新能力使用的默认系统提示词。', 'chuyi-ai-relay'),
                'file'        => 'Editorial_Updates/system-instruction.php',
                'data'        => array(),
            ),
            'ai/comment-analysis' => array(
                'ability'     => 'ai/comment-analysis',
                'label'       => __('评论分析', 'chuyi-ai-relay'),
                'description' => __('AI 评论分析能力使用的默认系统提示词。', 'chuyi-ai-relay'),
                'file'        => 'Comment_Moderation/system-instruction.php',
                'data'        => array(),
            ),
            'ai/alt-text-generation' => array(
                'ability'     => 'ai/alt-text-generation',
                'label'       => __('替代文本生成', 'chuyi-ai-relay'),
                'description' => __('AI 替代文本生成能力使用的默认系统提示词。', 'chuyi-ai-relay'),
                'file'        => 'Image/alt-text-system-instruction.php',
                'data'        => array(),
            ),
            'ai/image-prompt-generation' => array(
                'ability'     => 'ai/image-prompt-generation',
                'label'       => __('图片提示词生成', 'chuyi-ai-relay'),
                'description' => __('AI 图片提示词生成能力使用的默认系统提示词。', 'chuyi-ai-relay'),
                'file'        => 'Image/image-prompt-system-instruction.php',
                'data'        => array(),
            ),
        );
    }

    /**
     * Returns one managed prompt definition.
     *
     * @return array{ability:string,label:string,description:string,file:string,data:array<string,mixed>}|null
     */
    public static function definition(string $ability): ?array
    {
        $definitions = self::definitions();
        return $definitions[$ability] ?? null;
    }

    /**
     * Returns the default instruction for an ability.
     */
    public static function instruction(string $ability): string
    {
        $definition = self::definition($ability);
        if ($definition === null) {
            return '';
        }

        return self::loadInstruction($definition['file'], $definition['data']);
    }

    /**
     * Returns managed prompts with current override state.
     *
     * @return list<array<string,mixed>>
     */
    public static function managed(): array
    {
        $items = array();
        foreach (self::definitions() as $definition) {
            $ability = $definition['ability'];
            $defaultInstruction = self::instruction($ability);
            $override = PromptOverrides::get($ability);

            $items[] = array(
                'ability'             => $ability,
                'label'               => $definition['label'],
                'description'         => $definition['description'],
                'default_instruction' => $defaultInstruction,
                'instruction'         => $override['instruction'] ?? $defaultInstruction,
                'mode'                => $override['mode'] ?? PromptOverrides::MODE_REPLACE,
                'enabled'             => $override['enabled'] ?? false,
                'customized'          => $override !== null,
                'updated_at'          => $override['updated_at'] ?? '',
            );
        }

        return $items;
    }

    /**
     * @param array<string,mixed> $data
     */
    private static function loadInstruction(string $relativeFile, array $data): string
    {
        $baseDirs = array();
        if (defined('WPAI_PLUGIN_DIR')) {
            $baseDirs[] = trailingslashit((string) WPAI_PLUGIN_DIR);
        }
        if (defined('WP_PLUGIN_DIR')) {
            $baseDirs[] = trailingslashit((string) WP_PLUGIN_DIR) . 'ai/';
        }
        $baseDirs[] = trailingslashit(dirname(__DIR__, 3)) . 'ai/';

        foreach (array_unique($baseDirs) as $baseDir) {
            $file = $baseDir . 'includes/Abilities/' . ltrim($relativeFile, '/');
            if (!is_readable($file)) {
                continue;
            }

            if (!empty($data)) {
                extract($data, EXTR_SKIP); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract
            }

            $content = require $file;
            if (is_string($content) && trim($content) !== '') {
                return trim($content);
            }
        }

        return self::fallbackInstruction($relativeFile);
    }

    private static function fallbackInstruction(string $relativeFile): string
    {
        $fallbacks = array(
            'Title_Generation/system-instruction.php' => '你是一个专业中文内容编辑。请基于用户提供的内容生成简洁、准确、适合发布的标题建议。只输出标题，不要附加解释。',
            'Excerpt_Generation/system-instruction.php' => '你是一个专业中文内容编辑。请基于用户提供的内容生成一段简洁摘要，突出核心信息，避免夸张和无根据表达。',
            'Summarization/system-instruction.php' => '你是一个专业中文内容编辑。请基于用户提供的内容生成结构清晰、信息准确的摘要，保留关键事实和结论。',
            'Meta_Description/system-instruction.php' => '你是一个专业 SEO 编辑。请基于用户提供的内容生成一段中文 Meta 描述，简洁自然，适合搜索结果展示。',
            'Content_Classification/system-instruction.php' => '你是一个内容分类助手。请基于用户提供的内容推荐合适的分类、标签或主题关键词，保持准确、克制、可用于站点归档。',
            'Content_Resizing/system-instruction.php' => '你是一个专业中文编辑。请按照用户要求调整内容长度或表达方式，保持原意、语气自然、结构清晰。',
            'Editorial_Notes/system-instruction.php' => '你是一个专业编辑审阅助手。请针对内容给出可执行的编辑建议，重点关注可读性、准确性、语法、结构和 SEO。',
            'Editorial_Updates/system-instruction.php' => '你是一个专业编辑执行助手。请根据已有编辑建议更新内容，保持原意和格式稳定，不添加无关信息。',
            'Comment_Moderation/system-instruction.php' => '你是一个评论审核助手。请分析评论的情绪、毒性和质量风险，输出可用于审核决策的简洁结果。',
            'Image/alt-text-system-instruction.php' => '你是一个图片替代文本助手。请根据图片和上下文生成简洁、准确、可访问性友好的中文 alt 文本。',
            'Image/image-prompt-system-instruction.php' => '你是一个图片生成提示词助手。请基于用户内容生成清晰、具体、适合图像模型理解的提示词。',
        );

        return $fallbacks[$relativeFile] ?? '你是一个 WordPress AI 助手。请基于用户输入完成指定任务，输出准确、简洁、可直接使用的中文结果。';
    }
}