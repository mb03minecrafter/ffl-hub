<?php

declare(strict_types=1);

namespace FFLHub\Brand;

use WP_Term;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Dynamic FAQ block for product brand and product tag archive templates.
 *
 * The block itself stays generic: it reads the current queried brand/tag term
 * and renders FAQ entries stored on that term. That lets one archive template
 * serve every brand and collection page while each term owns its own SEO copy.
 */
final class ArchiveFaqBlock
{
    private const META_KEY = 'fflhub_archive_faq_items';
    private const NONCE_ACTION = 'fflhub_archive_faq';
    private const NONCE_FIELD = 'fflhub_archive_faq_nonce';
    private const FIELD_NAME = 'fflhub_archive_faq_items';
    private const PAYLOAD_FIELD = 'fflhub_archive_faq_payload';
    private const CLEAR_FIELD = 'fflhub_archive_faq_clear';
    private const SUPPORTED_TAXONOMIES = ['product_brand', 'product_tag'];

    public static function init(): void
    {
        add_action('init', [self::class, 'register']);

        foreach (self::SUPPORTED_TAXONOMIES as $taxonomy) {
            add_action("{$taxonomy}_add_form_fields", [self::class, 'render_add_field']);
            add_action("{$taxonomy}_edit_form_fields", [self::class, 'render_edit_field']);
            add_action("created_{$taxonomy}", [self::class, 'save']);
            add_action("edited_{$taxonomy}", [self::class, 'save']);
        }

        add_action('admin_enqueue_scripts', [self::class, 'enqueue_admin_assets']);
        add_filter('wpseo_schema_graph', [self::class, 'add_yoast_schema'], 20, 2);
    }

    public static function register(): void
    {
        if (!function_exists('register_block_type')) {
            return;
        }

        register_block_type('fflhub/archive-faq', [
            'api_version'     => 2,
            'title'           => __('FFLHub Archive FAQ', 'ffl-hub'),
            'category'        => 'widgets',
            'description'     => __('Displays FAQ entries stored on the current product brand or product tag archive.', 'ffl-hub'),
            'render_callback' => [self::class, 'render'],
            'supports'        => [
                'align' => ['wide', 'full'],
                'html'  => false,
            ],
        ]);
    }

    /**
     * @param array<string,mixed> $attributes
     */
    public static function render(array $attributes = []): string
    {
        $term = get_queried_object();
        if (!$term instanceof WP_Term || !in_array($term->taxonomy, self::SUPPORTED_TAXONOMIES, true)) {
            return '';
        }

        $items = self::faq_items((int) $term->term_id);
        if (empty($items)) {
            return '';
        }

        ob_start();
        ?>
        <section class="fflhub-archive-faq alignwide" aria-labelledby="fflhub-archive-faq-title">
            <div class="fflhub-archive-faq__inner">
                <h2 id="fflhub-archive-faq-title" class="fflhub-archive-faq__title"><?php echo esc_html__('Frequently Asked Questions', 'ffl-hub'); ?></h2>
                <div class="fflhub-archive-faq__items">
                    <?php foreach ($items as $item) : ?>
                        <details class="fflhub-archive-faq__item">
                            <summary class="fflhub-archive-faq__question"><?php echo esc_html($item['question']); ?></summary>
                            <div class="fflhub-archive-faq__answer">
                                <?php echo self::format_answer_html($item['answer']); ?>
                            </div>
                        </details>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
        <?php

        return self::styles() . (string) ob_get_clean();
    }

    /**
     * Add term FAQ rows to Yoast's schema graph as a FAQPage node.
     *
     * Yoast renders the final JSON-LD graph. We only append a small node when
     * the current archive term has visible FAQ content, so the HTML FAQ and the
     * structured data stay sourced from the same term meta.
     *
     * @param array<int,array<string,mixed>> $graph
     * @param mixed                          $context Yoast Meta_Tags_Context.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function add_yoast_schema(array $graph, $context): array
    {
        $term = self::current_archive_term();
        if (!$term instanceof WP_Term) {
            return $graph;
        }

        $items = self::faq_items((int) $term->term_id);
        if (empty($items) || self::graph_has_faq_page($graph)) {
            return $graph;
        }

        $canonical = self::schema_canonical_url($term, $context);
        if ($canonical === '') {
            return $graph;
        }

        $faq_id = trailingslashit($canonical) . '#faq';
        $main_entity = [];
        foreach ($items as $index => $item) {
            $question_id = $faq_id . '-question-' . ($index + 1);
            $main_entity[] = [
                '@type' => 'Question',
                '@id' => $question_id,
                'name' => $item['question'],
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text' => self::format_answer_html($item['answer']),
                ],
            ];
        }

        $piece = [
            '@type' => 'FAQPage',
            '@id' => $faq_id,
            'url' => $canonical,
            'name' => sprintf('%s FAQ', $term->name),
            'mainEntity' => $main_entity,
        ];

        $webpage_id = self::webpage_id_from_graph($graph);
        if ($webpage_id !== '') {
            $piece['isPartOf'] = ['@id' => $webpage_id];
        }

        $language = trim((string) get_bloginfo('language'));
        if ($language !== '') {
            $piece['inLanguage'] = $language;
        }

        $graph[] = $piece;

        return $graph;
    }

    private static function current_archive_term(): ?WP_Term
    {
        $term = get_queried_object();
        if (!$term instanceof WP_Term || !in_array($term->taxonomy, self::SUPPORTED_TAXONOMIES, true)) {
            return null;
        }

        return $term;
    }

    /**
     * @return array<int,array{question:string,answer:string}>
     */
    private static function faq_items(int $term_id): array
    {
        $raw = get_term_meta($term_id, self::META_KEY, true);
        $items = is_string($raw) ? json_decode($raw, true) : $raw;
        if (!is_array($items)) {
            return [];
        }

        $out = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $question = trim((string) ($item['question'] ?? ''));
            $answer = trim((string) ($item['answer'] ?? ''));
            if ($question === '' || $answer === '') {
                continue;
            }

            $out[] = [
                'question' => $question,
                'answer' => $answer,
            ];
        }

        return $out;
    }

    /**
     * @param array<int,array<string,mixed>> $graph
     */
    private static function graph_has_faq_page(array $graph): bool
    {
        foreach ($graph as $piece) {
            if (!is_array($piece) || !isset($piece['@type'])) {
                continue;
            }

            $types = is_array($piece['@type']) ? $piece['@type'] : [(string) $piece['@type']];
            if (in_array('FAQPage', $types, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int,array<string,mixed>> $graph
     */
    private static function webpage_id_from_graph(array $graph): string
    {
        foreach ($graph as $piece) {
            if (!is_array($piece) || empty($piece['@id']) || empty($piece['@type'])) {
                continue;
            }

            $types = is_array($piece['@type']) ? $piece['@type'] : [(string) $piece['@type']];
            if (in_array('WebPage', $types, true) || in_array('CollectionPage', $types, true)) {
                return (string) $piece['@id'];
            }
        }

        return '';
    }

    /**
     * @param mixed $context Yoast Meta_Tags_Context.
     */
    private static function schema_canonical_url(WP_Term $term, $context): string
    {
        if (is_object($context) && isset($context->canonical) && is_string($context->canonical)) {
            $canonical = trim($context->canonical);
            if ($canonical !== '') {
                return $canonical;
            }
        }

        $link = get_term_link($term);
        if (is_wp_error($link)) {
            return '';
        }

        return (string) $link;
    }

    public static function render_add_field(string $taxonomy): void
    {
        if (!in_array($taxonomy, self::SUPPORTED_TAXONOMIES, true) || !self::can_manage_taxonomy($taxonomy)) {
            return;
        }

        wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD);
        ?>
        <div class="form-field term-fflhub-archive-faq-wrap">
            <label><?php echo esc_html__('Archive FAQ', 'ffl-hub'); ?></label>
            <?php self::render_admin_rows([]); ?>
            <p><?php echo esc_html__('Optional FAQ entries shown below the product collection on this archive page.', 'ffl-hub'); ?></p>
        </div>
        <?php
    }

    public static function render_edit_field(WP_Term $term): void
    {
        if (!in_array($term->taxonomy, self::SUPPORTED_TAXONOMIES, true) || !self::can_manage_taxonomy($term->taxonomy)) {
            return;
        }

        wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD);
        ?>
        <tr class="form-field term-fflhub-archive-faq-wrap">
            <th scope="row">
                <label><?php echo esc_html__('Archive FAQ', 'ffl-hub'); ?></label>
            </th>
            <td>
                <?php self::render_admin_rows(self::faq_items((int) $term->term_id)); ?>
                <p class="description"><?php echo esc_html__('Optional FAQ entries shown below the product collection on this archive page.', 'ffl-hub'); ?></p>
                <label class="fflhub-archive-faq-admin__clear">
                    <input type="checkbox" name="<?php echo esc_attr(self::CLEAR_FIELD); ?>" value="1" />
                    <?php echo esc_html__('Clear all FAQ rows on save', 'ffl-hub'); ?>
                </label>
                <p class="description"><?php echo esc_html__('FAQ rows are only deleted when this box is checked. This protects pasted editor content from accidentally clearing the whole FAQ.', 'ffl-hub'); ?></p>
            </td>
        </tr>
        <?php
    }

    /**
     * @param array<int,array{question:string,answer:string}> $items
     */
    private static function render_admin_rows(array $items): void
    {
        $rows = $items;
        $rows[] = ['question' => '', 'answer' => ''];
        $rows[] = ['question' => '', 'answer' => ''];
        ?>
        <div class="fflhub-archive-faq-admin" data-next-index="<?php echo esc_attr((string) count($rows)); ?>">
            <input type="hidden" class="fflhub-archive-faq-admin__payload" name="<?php echo esc_attr(self::PAYLOAD_FIELD); ?>" value="" />
            <div class="fflhub-archive-faq-admin__rows">
                <?php foreach ($rows as $index => $item) : ?>
                    <?php self::render_admin_row((int) $index, $item['question'], $item['answer']); ?>
                <?php endforeach; ?>
            </div>
            <button type="button" class="button fflhub-archive-faq-admin__add"><?php echo esc_html__('Add FAQ row', 'ffl-hub'); ?></button>
            <template class="fflhub-archive-faq-admin__template">
                <?php self::render_admin_row('__INDEX__', '', ''); ?>
            </template>
        </div>
        <?php
    }

    /**
     * @param int|string $index
     */
    private static function render_admin_row($index, string $question, string $answer): void
    {
        $base = self::FIELD_NAME . '[' . $index . ']';
        $editor_id = 'fflhub_archive_faq_answer_' . preg_replace('/[^A-Za-z0-9_-]/', '_', (string) $index);
        $is_template = (string) $index === '__INDEX__';
        ?>
        <div class="fflhub-archive-faq-admin__row">
            <input
                type="text"
                class="widefat fflhub-archive-faq-admin__question"
                name="<?php echo esc_attr($base . '[question]'); ?>"
                value="<?php echo esc_attr($question); ?>"
                placeholder="<?php echo esc_attr__('Question', 'ffl-hub'); ?>"
            />
            <div class="fflhub-archive-faq-admin__editor">
                <?php if (!$is_template && function_exists('wp_editor')) : ?>
                    <?php wp_editor($answer, $editor_id, self::editor_settings($base . '[answer]')); ?>
                <?php else : ?>
                    <textarea
                        id="<?php echo esc_attr($editor_id); ?>"
                        class="widefat fflhub-archive-faq-admin__answer"
                        name="<?php echo esc_attr($base . '[answer]'); ?>"
                        rows="7"
                        placeholder="<?php echo esc_attr__('Answer', 'ffl-hub'); ?>"
                    ><?php echo esc_textarea($answer); ?></textarea>
                <?php endif; ?>
            </div>
            <p class="description fflhub-archive-faq-admin__hint">
                <?php echo esc_html__('Use the Visual editor toolbar for bold text, links, bullet lists, numbered lists, and paragraph spacing.', 'ffl-hub'); ?>
            </p>
            <button type="button" class="button-link-delete fflhub-archive-faq-admin__remove"><?php echo esc_html__('Remove', 'ffl-hub'); ?></button>
        </div>
        <?php
    }

    public static function save(int $term_id): void
    {
        $taxonomy = isset($_POST['taxonomy']) ? sanitize_key((string) wp_unslash($_POST['taxonomy'])) : '';
        if (!in_array($taxonomy, self::SUPPORTED_TAXONOMIES, true) || !self::can_manage_taxonomy($taxonomy)) {
            return;
        }

        $nonce = isset($_POST[self::NONCE_FIELD])
            ? sanitize_text_field(wp_unslash((string) $_POST[self::NONCE_FIELD]))
            : '';
        if ($nonce === '' || !wp_verify_nonce($nonce, self::NONCE_ACTION)) {
            return;
        }

        $raw_rows = self::submitted_rows_from_payload();
        if (empty($raw_rows) && isset($_POST[self::FIELD_NAME]) && is_array($_POST[self::FIELD_NAME])) {
            $raw_rows = wp_unslash($_POST[self::FIELD_NAME]);
        }
        $clear_faq = isset($_POST[self::CLEAR_FIELD]) && (string) wp_unslash($_POST[self::CLEAR_FIELD]) === '1';
        if ($clear_faq) {
            delete_term_meta($term_id, self::META_KEY);
            return;
        }

        $items = [];

        foreach ($raw_rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $raw_question = (string) ($row['question'] ?? '');
            $raw_answer = self::normalize_submitted_answer((string) ($row['answer'] ?? ''));
            $normalized_row = self::normalize_submitted_row($raw_question, $raw_answer);

            $question = sanitize_text_field($normalized_row['question']);
            $answer = self::sanitize_answer_html($normalized_row['answer']);
            $question = trim($question);
            $answer = trim($answer);
            if ($question === '' || $answer === '') {
                continue;
            }

            $items[] = [
                'question' => $question,
                'answer' => $answer,
            ];
        }

        if (empty($items)) {
            return;
        }

        update_term_meta($term_id, self::META_KEY, wp_json_encode(array_slice($items, 0, 30)));
    }

    /**
     * The WordPress term edit screen can fail to sync dynamically-initialized
     * rich editors back into their nested textarea names. The admin script
     * writes a flat JSON payload immediately before submit, and the save path
     * prefers that payload when present.
     *
     * @return array<int,array{question?:string,answer?:string}>
     */
    private static function submitted_rows_from_payload(): array
    {
        $payload = isset($_POST[self::PAYLOAD_FIELD])
            ? (string) wp_unslash($_POST[self::PAYLOAD_FIELD])
            : '';
        if ($payload === '') {
            return [];
        }

        $decoded = json_decode($payload, true);
        if (!is_array($decoded)) {
            return [];
        }

        $rows = [];
        foreach ($decoded as $row) {
            if (!is_array($row)) {
                continue;
            }

            $rows[] = [
                'question' => (string) ($row['question'] ?? ''),
                'answer' => (string) ($row['answer'] ?? ''),
            ];
        }

        return $rows;
    }

    public static function enqueue_admin_assets(string $hook_suffix): void
    {
        if (!in_array($hook_suffix, ['edit-tags.php', 'term.php'], true)) {
            return;
        }

        $taxonomy = isset($_GET['taxonomy']) ? sanitize_key((string) wp_unslash($_GET['taxonomy'])) : '';
        if (!in_array($taxonomy, self::SUPPORTED_TAXONOMIES, true) || !self::can_manage_taxonomy($taxonomy)) {
            return;
        }

        if (function_exists('wp_enqueue_editor')) {
            wp_enqueue_editor();
        }

        wp_enqueue_script('jquery');
        wp_add_inline_script('jquery', self::admin_script());
        wp_register_style('fflhub-archive-faq-admin', false, [], FFLHUB_PLUGIN_VERSION);
        wp_enqueue_style('fflhub-archive-faq-admin');
        wp_add_inline_style('fflhub-archive-faq-admin', self::admin_styles());
    }

    private static function can_manage_taxonomy(string $taxonomy): bool
    {
        $tax = get_taxonomy($taxonomy);
        $cap = $tax && isset($tax->cap->edit_terms)
            ? (string) $tax->cap->edit_terms
            : 'manage_product_terms';

        return current_user_can($cap);
    }

    private static function normalize_submitted_answer(string $answer): string
    {
        $answer = wp_check_invalid_utf8($answer, true);
        $answer = str_replace(["\r\n", "\r"], "\n", $answer);
        $answer = str_replace(['\\u00a0', 'u00a0', "\xc2\xa0"], ' ', $answer);
        $answer = (string) preg_replace('/\x{00A0}/u', ' ', $answer);

        return self::normalize_bullet_styled_lists($answer);
    }

    private static function normalize_bullet_styled_lists(string $html): string
    {
        return (string) preg_replace_callback(
            '/<ol\\b([^>]*)>(.*?)<\\/ol>/is',
            static function (array $match): string {
                $attributes = strtolower((string) ($match[1] ?? ''));
                $classes = '';
                if (preg_match('/class\\s*=\\s*"([^"]*)"/is', $attributes, $class_match)) {
                    $classes = strtolower((string) ($class_match[1] ?? ''));
                } elseif (preg_match("/class\\s*=\\s*'([^']*)'/is", $attributes, $class_match)) {
                    $classes = strtolower((string) ($class_match[1] ?? ''));
                }

                if ($classes === '') {
                    return $match[0];
                }

                $looks_like_bullets = str_contains($classes, 'bullet')
                    || str_contains($classes, 'unordered')
                    || preg_match('/(^|\\s)u-list-[^\\s]*-b(\\s|$)/', $classes) === 1
                    || preg_match('/(^|\\s)[^\\s]*-bullets?(\\s|$)/', $classes) === 1;

                if (!$looks_like_bullets) {
                    return $match[0];
                }

                return '<ul>' . (string) ($match[2] ?? '') . '</ul>';
            },
            $html
        );
    }

    /**
     * @return array{question:string,answer:string}
     */
    private static function normalize_submitted_row(string $question, string $answer): array
    {
        $question = trim($question);
        $answer = trim($answer);

        if ($answer === '') {
            return [
                'question' => $question,
                'answer' => $answer,
            ];
        }

        if (!preg_match('/<h([1-6])\\b[^>]*>(.*?)<\\/h\\1>/is', $answer, $match)) {
            return self::normalize_plain_text_question_row($question, $answer);
        }

        $heading_text = trim(wp_strip_all_tags((string) $match[2]));
        if ($heading_text === '') {
            return [
                'question' => $question,
                'answer' => $answer,
            ];
        }

        $answer_without_heading = trim((string) preg_replace('/<h([1-6])\\b[^>]*>.*?<\\/h\\1>/is', '', $answer, 1));

        return [
            'question' => $question !== '' ? $question : $heading_text,
            'answer' => $answer_without_heading,
        ];
    }

    /**
     * Pasting from ChatGPT, Notepad, or a rendered page often lands as one blob
     * in the answer editor. When the question field is blank, treat the first
     * visible line as the FAQ question and keep the remaining lines as the
     * answer. Typed rows with a filled question field keep their answer exactly
     * as submitted.
     *
     * @return array{question:string,answer:string}
     */
    private static function normalize_plain_text_question_row(string $question, string $answer): array
    {
        if ($question !== '') {
            return [
                'question' => $question,
                'answer' => $answer,
            ];
        }

        $lines = self::visible_answer_lines($answer);
        if (count($lines) < 2) {
            return [
                'question' => $question,
                'answer' => $answer,
            ];
        }

        return [
            'question' => $lines[0],
            'answer' => self::plain_lines_to_answer_html(array_slice($lines, 1)),
        ];
    }

    /**
     * @return array<int,string>
     */
    private static function visible_answer_lines(string $answer): array
    {
        $text = preg_replace('/<\\/?(p|div|h[1-6]|li|ul|ol)\\b[^>]*>/i', "\n", $answer);
        $text = preg_replace('/<br\\s*\\/?>/i', "\n", (string) $text);
        $text = html_entity_decode(wp_strip_all_tags((string) $text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = str_replace(['\\u00a0', 'u00a0', "\xc2\xa0"], ' ', $text);

        $lines = [];
        foreach (explode("\n", (string) $text) as $line) {
            $line = trim((string) preg_replace('/\\s+/u', ' ', $line));
            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * @param array<int,string> $lines
     */
    private static function plain_lines_to_answer_html(array $lines): string
    {
        $lines = array_values(array_filter(array_map('trim', $lines), static fn (string $line): bool => $line !== ''));
        if (empty($lines)) {
            return '';
        }

        $looks_like_list = count($lines) > 1;
        foreach ($lines as $line) {
            if (!preg_match('/^([\\x{2022}*\\-]|\\d+[.)])\\s+/u', $line) && !str_contains($line, ':')) {
                $looks_like_list = false;
                break;
            }
        }

        if ($looks_like_list) {
            $items = [];
            foreach ($lines as $line) {
                $line = (string) preg_replace('/^([\\x{2022}*\\-]|\\d+[.)])\\s+/u', '', $line);
                $items[] = '<li>' . esc_html($line) . '</li>';
            }

            return '<ul>' . implode('', $items) . '</ul>';
        }

        return wpautop(esc_html(implode("\n\n", $lines)));
    }

    private static function sanitize_answer_html(string $answer): string
    {
        /*
         * FAQ answers are normal WordPress rich text. Use the same broad post
         * sanitizer WordPress relies on for post content instead of a tiny
         * custom whitelist; pasted content from docs/pages often includes
         * wrappers, headings, list markup, and safe attributes that should not
         * make the saved FAQ row collapse to empty.
         */
        $answer = wp_kses_post($answer);
        $answer = preg_replace('/<li>\\s*<p>(.*?)<\\/p>\\s*<\\/li>/is', '<li>$1</li>', $answer);

        return trim((string) $answer);
    }

    /**
     * @return array<string,mixed>
     */
    private static function editor_settings(string $textarea_name): array
    {
        return [
            'textarea_name' => $textarea_name,
            'textarea_rows' => 7,
            'editor_class' => 'fflhub-archive-faq-admin__answer',
            'media_buttons' => false,
            'teeny' => false,
            'drag_drop_upload' => false,
            'quicktags' => [
                'buttons' => 'strong,em,link,ul,ol,li,close',
            ],
            'tinymce' => [
                'toolbar1' => 'formatselect,bold,italic,bullist,numlist,link,unlink,undo,redo',
                'toolbar2' => '',
                'block_formats' => 'Paragraph=p;Heading 3=h3;Heading 4=h4',
                'paste_as_text' => false,
            ],
        ];
    }

    private static function format_answer_html(string $answer): string
    {
        $answer = self::apply_light_formatting($answer);

        return wp_kses_post(wpautop($answer));
    }

    private static function apply_light_formatting(string $answer): string
    {
        $answer = str_replace(["\r\n", "\r"], "\n", $answer);

        return (string) preg_replace(
            '/(?<!\\\\)(\\*\\*|__)([^\\n<>][\\s\\S]*?[^\\n<>])\\1/',
            '<strong>$2</strong>',
            $answer
        );
    }

    private static function admin_script(): string
    {
        return <<<'JS'
jQuery(function($) {
    $('.fflhub-archive-faq-admin').each(function() {
        var $wrap = $(this);
        var $rows = $wrap.find('.fflhub-archive-faq-admin__rows');
        var editorSettings = {
            tinymce: {
                toolbar1: 'formatselect,bold,italic,bullist,numlist,link,unlink,undo,redo',
                toolbar2: '',
                block_formats: 'Paragraph=p;Heading 3=h3;Heading 4=h4',
                paste_as_text: false,
                paste_preprocess: function(plugin, args) {
                    args.content = cleanPastedFaqContent(args.content || '');
                }
            },
            quicktags: {
                buttons: 'strong,em,link,ul,ol,li,close'
            },
            mediaButtons: false
        };

        function normalizeClipboardSpaces(value) {
            return String(value || '')
                .replace(/\\u00a0/g, ' ')
                .replace(/u00a0/g, ' ')
                .replace(/\u00a0/g, ' ');
        }

        function plainTextToHtml(text) {
            var lines = normalizeClipboardSpaces(text)
                .replace(/\r\n/g, '\n')
                .replace(/\r/g, '\n')
                .split('\n')
                .map(function(line) {
                    return line.replace(/\s+/g, ' ').trim();
                })
                .filter(Boolean);
            if (!lines.length) {
                return '';
            }

            var html = '';
            var listType = '';
            var listItems = [];

            function flushList() {
                if (!listType || !listItems.length) {
                    listType = '';
                    listItems = [];
                    return;
                }

                html += '<' + listType + '>' + listItems.map(function(item) {
                    return '<li>' + $('<div>').text(item).html() + '</li>';
                }).join('') + '</' + listType + '>';
                listType = '';
                listItems = [];
            }

            lines.forEach(function(line) {
                var bullet = line.match(/^([•*\-])\s+(.*)$/);
                var numbered = line.match(/^(\d+[.)])\s+(.*)$/);
                if (bullet) {
                    if (listType && listType !== 'ul') {
                        flushList();
                    }
                    listType = 'ul';
                    listItems.push(bullet[2]);
                    return;
                }
                if (numbered) {
                    if (listType && listType !== 'ol') {
                        flushList();
                    }
                    listType = 'ol';
                    listItems.push(numbered[2]);
                    return;
                }

                flushList();
                html += '<p>' + $('<div>').text(line).html() + '</p>';
            });

            flushList();
            return html;
        }

        function cleanPastedFaqContent(content) {
            content = normalizeClipboardSpaces(content)
                .replace(/<!--[\s\S]*?-->/g, '')
                .replace(/<script[\s\S]*?<\/script>/gi, '')
                .replace(/<style[\s\S]*?<\/style>/gi, '')
                .replace(/<meta[\s\S]*?>/gi, '');

            if (!/<[a-z][\s\S]*>/i.test(content)) {
                return plainTextToHtml(content);
            }

            var $scratch = $('<div>').html(content);
            $scratch.find('ol').each(function() {
                var className = String(this.className || '').toLowerCase();
                if (/(^|\s)u-list-[^\s]*-b(\s|$)/.test(className) || className.indexOf('bullet') !== -1 || className.indexOf('unordered') !== -1) {
                    var $ul = $('<ul>').html($(this).html());
                    $(this).replaceWith($ul);
                }
            });

            $scratch.find('*').each(function() {
                var tag = this.tagName.toLowerCase();
                var allowed = ['p', 'br', 'strong', 'b', 'em', 'i', 'a', 'ul', 'ol', 'li', 'h3', 'h4', 'div', 'span'];
                if (allowed.indexOf(tag) === -1) {
                    $(this).replaceWith($(this).contents());
                    return;
                }

                $.each(Array.prototype.slice.call(this.attributes), function(_, attr) {
                    var name = attr.name.toLowerCase();
                    if (tag === 'a' && (name === 'href' || name === 'title' || name === 'target' || name === 'rel')) {
                        return;
                    }
                    this.ownerElement.removeAttribute(attr.name);
                });
            });

            $scratch.find('b').each(function() {
                $(this).replaceWith($('<strong>').html($(this).html()));
            });
            $scratch.find('i').each(function() {
                $(this).replaceWith($('<em>').html($(this).html()));
            });

            return $scratch.html();
        }

        function bindPasteCleanupToEditor(editor) {
            if (!editor || editor.fflhubArchiveFaqPasteBound) {
                return;
            }

            editor.fflhubArchiveFaqPasteBound = true;
            editor.on('PastePreProcess', function(args) {
                args.content = cleanPastedFaqContent(args.content || '');
            });
        }

        function bindPasteCleanup() {
            if (!window.tinyMCE) {
                return;
            }

            if (tinyMCE.editors && tinyMCE.editors.length) {
                $.each(tinyMCE.editors, function(_, editor) {
                    bindPasteCleanupToEditor(editor);
                });
            }

            if (!tinyMCE.fflhubArchiveFaqAddEditorBound && tinyMCE.on) {
                tinyMCE.fflhubArchiveFaqAddEditorBound = true;
                tinyMCE.on('AddEditor', function(event) {
                    bindPasteCleanupToEditor(event.editor);
                });
            }
        }

        function initializeEditor($row) {
            if (!window.wp || !wp.editor || !wp.editor.initialize) {
                return;
            }

            var $textarea = $row.find('textarea.fflhub-archive-faq-admin__answer');
            var id = $textarea.attr('id');
            if (id) {
                wp.editor.initialize(id, editorSettings);
                setTimeout(bindPasteCleanup, 50);
            }
        }

        function removeEditor($row) {
            if (!window.wp || !wp.editor || !wp.editor.remove) {
                return;
            }

            var $textarea = $row.find('textarea.fflhub-archive-faq-admin__answer');
            var id = $textarea.attr('id');
            if (id) {
                wp.editor.remove(id);
            }
        }

        function syncEditors() {
            if (window.tinyMCE && tinyMCE.triggerSave) {
                tinyMCE.triggerSave();
            }

            $wrap.find('textarea.fflhub-archive-faq-admin__answer').each(function() {
                var id = $(this).attr('id');
                if (!id || !window.tinyMCE) {
                    return;
                }

                var editor = tinyMCE.get(id);
                if (editor && !editor.isHidden()) {
                    this.value = editor.getContent({ format: 'html' });
                }
            });
        }

        function answerForRow($row) {
            var $textarea = $row.find('textarea.fflhub-archive-faq-admin__answer');
            var id = $textarea.attr('id');
            if (id && window.tinyMCE) {
                var editor = tinyMCE.get(id);
                if (editor && !editor.isHidden()) {
                    return editor.getContent({ format: 'html' });
                }
            }

            return String($textarea.val() || '');
        }

        function buildPayload() {
            var rows = [];
            $wrap.find('.fflhub-archive-faq-admin__row').each(function() {
                var $row = $(this);
                rows.push({
                    question: String($row.find('.fflhub-archive-faq-admin__question').val() || ''),
                    answer: answerForRow($row)
                });
            });

            $wrap.find('.fflhub-archive-faq-admin__payload').val(JSON.stringify(rows));
        }

        function hasVisibleEditorContent(value) {
            return String(value || '')
                .replace(/<[^>]*>/g, '')
                .replace(/&nbsp;/g, ' ')
                .trim() !== '';
        }

        function ensureEditorContentBeforeSubmit(e) {
            if ($wrap.closest('form').find('input[name="fflhub_archive_faq_clear"]:checked').length) {
                return;
            }

            syncEditors();
            buildPayload();

            var suspicious = false;
            $wrap.find('.fflhub-archive-faq-admin__row').each(function() {
                var question = String($(this).find('.fflhub-archive-faq-admin__question').val() || '').trim();
                var answer = answerForRow($(this));
                if (question && !hasVisibleEditorContent(answer)) {
                    suspicious = true;
                }
            });

            if (suspicious) {
                e.preventDefault();
                alert('One or more FAQ questions has an empty answer. Please click into the FAQ answer editor and try saving again.');
            }
        }

        $wrap.on('click', '.fflhub-archive-faq-admin__add', function(e) {
            e.preventDefault();
            var index = parseInt($wrap.attr('data-next-index') || '0', 10);
            var html = $wrap.find('.fflhub-archive-faq-admin__template').html().replace(/__INDEX__/g, String(index));
            $wrap.attr('data-next-index', String(index + 1));
            var $row = $(html);
            $rows.append($row);
            initializeEditor($row);
        });

        $wrap.on('click', '.fflhub-archive-faq-admin__remove', function(e) {
            e.preventDefault();
            var $row = $(this).closest('.fflhub-archive-faq-admin__row');
            removeEditor($row);
            $row.remove();
        });

        $wrap.on('paste', 'textarea.fflhub-archive-faq-admin__answer', function(e) {
            var event = e.originalEvent || e;
            var clipboard = event.clipboardData || window.clipboardData;
            if (!clipboard) {
                return;
            }

            var html = clipboard.getData('text/html');
            var text = clipboard.getData('text/plain');
            var insert = cleanPastedFaqContent(html || text || '');
            if (!insert) {
                return;
            }

            e.preventDefault();
            var start = this.selectionStart || 0;
            var end = this.selectionEnd || 0;
            var current = String(this.value || '');
            this.value = current.substring(0, start) + insert + current.substring(end);
            this.selectionStart = this.selectionEnd = start + insert.length;
        });

        bindPasteCleanup();
        $wrap.closest('form').on('submit', ensureEditorContentBeforeSubmit);
    });
});
JS;
    }

    private static function admin_styles(): string
    {
        return '.fflhub-archive-faq-admin__row{position:relative;margin:0 0 16px;padding:12px;border:1px solid #ccd0d4;background:#fff}.fflhub-archive-faq-admin__question{margin-bottom:8px}.fflhub-archive-faq-admin__editor{margin-bottom:6px}.fflhub-archive-faq-admin__editor .wp-editor-wrap{max-width:900px}.fflhub-archive-faq-admin__answer{display:block;margin-bottom:8px;min-height:150px}.fflhub-archive-faq-admin__hint{margin:0 0 8px}.fflhub-archive-faq-admin__add{margin-top:2px}.fflhub-archive-faq-admin__clear{display:inline-flex;align-items:center;gap:6px;margin:10px 0 2px;padding:8px 10px;border:1px solid #d63638;background:#fff7f7;color:#8a2424}';
    }

    private static function styles(): string
    {
        return '<style>
.fflhub-archive-faq{box-sizing:border-box;margin-top:clamp(34px,5vw,72px);margin-bottom:clamp(34px,5vw,72px)}
.fflhub-archive-faq__inner{box-sizing:border-box;padding:clamp(26px,4vw,48px);border-top:1px solid rgba(0,0,0,.10);border-bottom:1px solid rgba(0,0,0,.10);background:#f7f8f9}
.fflhub-archive-faq__title{margin:0 0 22px;font-size:clamp(26px,3vw,40px);font-weight:800;line-height:1.12;letter-spacing:0;color:#101214}
.fflhub-archive-faq__items{display:grid;gap:10px}
.fflhub-archive-faq__item{border:1px solid rgba(0,0,0,.12);background:#fff}
.fflhub-archive-faq__question{cursor:pointer;padding:16px 18px;font-weight:750;line-height:1.35;color:#111}
.fflhub-archive-faq__question::marker{color:#555}
.fflhub-archive-faq__answer{padding:0 18px 18px;color:#30343a;font-size:16px;line-height:1.65}
.fflhub-archive-faq__answer p{margin:0 0 1em}
.fflhub-archive-faq__answer p:last-child{margin-bottom:0}
.fflhub-archive-faq__answer ul,.fflhub-archive-faq__answer ol{margin:0 0 1em 1.25em;padding:0}
.fflhub-archive-faq__answer li{margin:.35em 0}
@media (max-width:720px){.fflhub-archive-faq__inner{padding:24px 16px}.fflhub-archive-faq__question{padding:15px 14px}.fflhub-archive-faq__answer{padding:0 14px 15px}}
</style>';
    }
}
