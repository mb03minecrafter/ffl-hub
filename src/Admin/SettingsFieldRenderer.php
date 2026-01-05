<?php

namespace FFLHub\Admin;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Small helper to render settings fields consistently.
 *
 * Keeps AdminPage from becoming a giant HTML blob.
 */
final class SettingsFieldRenderer
{
    /**
     * Render one <tr> row for a setting field.
     *
     * @param string $input_id
     * @param string $name       form field name (option name)
     * @param array  $def        schema field definition
     * @param mixed  $value      current value
     */
    public static function render_row(string $input_id, string $name, array $def, $value): void
    {
        $type        = isset($def['type']) ? (string) $def['type'] : 'text';
        $label       = isset($def['label']) ? (string) $def['label'] : $name;
        $placeholder = isset($def['placeholder']) ? (string) $def['placeholder'] : '';
        $desc        = isset($def['description']) ? (string) $def['description'] : '';
        $default     = isset($def['default']) ? (string) $def['default'] : '';

        if ($value === null || $value === '') {
            $value = $default;
        }

        $type = strtolower(trim($type));

        ?>
        <tr>
            <th scope="row">
                <label for="<?php echo esc_attr($input_id); ?>">
                    <?php echo esc_html($label); ?>
                </label>
            </th>
            <td>
                <?php
                switch ($type) {
                    case 'checkbox':
                        self::render_checkbox($input_id, $name, $value, $desc);
                        break;

                    case 'textarea':
                        self::render_textarea($input_id, $name, (string) $value, $placeholder, $desc);
                        break;

                    case 'select':
                        $options = isset($def['options']) && is_array($def['options']) ? $def['options'] : [];
                        self::render_select($input_id, $name, (string) $value, $options, $desc);
                        break;

                    case 'number':
                        $min  = isset($def['min']) ? (string) $def['min'] : '';
                        $max  = isset($def['max']) ? (string) $def['max'] : '';
                        $step = isset($def['step']) ? (string) $def['step'] : '1';
                        self::render_number($input_id, $name, (string) $value, $placeholder, $min, $max, $step, $desc);
                        break;

                    case 'password':
                    case 'text':
                    default:
                        self::render_input($type, $input_id, $name, (string) $value, $placeholder, $desc);
                        break;
                }
                ?>
            </td>
        </tr>
        <?php
    }

    private static function render_input(
        string $type,
        string $input_id,
        string $name,
        string $value,
        string $placeholder,
        string $desc
    ): void {
        ?>
        <input
            type="<?php echo esc_attr($type); ?>"
            id="<?php echo esc_attr($input_id); ?>"
            name="<?php echo esc_attr($name); ?>"
            value="<?php echo esc_attr($value); ?>"
            placeholder="<?php echo esc_attr($placeholder); ?>"
            class="regular-text" />
        <?php if ($desc !== '') : ?>
            <p class="description"><?php echo esc_html($desc); ?></p>
        <?php endif; ?>
        <?php
    }

    private static function render_number(
        string $input_id,
        string $name,
        string $value,
        string $placeholder,
        string $min,
        string $max,
        string $step,
        string $desc
    ): void {
        ?>
        <input
            type="number"
            id="<?php echo esc_attr($input_id); ?>"
            name="<?php echo esc_attr($name); ?>"
            value="<?php echo esc_attr($value); ?>"
            placeholder="<?php echo esc_attr($placeholder); ?>"
            class="regular-text"
            step="<?php echo esc_attr($step); ?>"
            <?php if ($min !== '') : ?>min="<?php echo esc_attr($min); ?>"<?php endif; ?>
            <?php if ($max !== '') : ?>max="<?php echo esc_attr($max); ?>"<?php endif; ?> />
        <?php if ($desc !== '') : ?>
            <p class="description"><?php echo esc_html($desc); ?></p>
        <?php endif; ?>
        <?php
    }

    private static function render_checkbox(string $input_id, string $name, $value, string $desc): void
    {
        $is_checked = ((string) $value === '1');

        // Hidden field ensures unchecked submits "0".
        ?>
        <input type="hidden" name="<?php echo esc_attr($name); ?>" value="0" />
        <label>
            <input
                type="checkbox"
                id="<?php echo esc_attr($input_id); ?>"
                name="<?php echo esc_attr($name); ?>"
                value="1"
                <?php checked($is_checked); ?> />
            <?php if ($desc !== '') : ?>
                <span class="description"><?php echo esc_html($desc); ?></span>
            <?php endif; ?>
        </label>
        <?php
    }

    private static function render_textarea(
        string $input_id,
        string $name,
        string $value,
        string $placeholder,
        string $desc
    ): void {
        ?>
        <textarea
            id="<?php echo esc_attr($input_id); ?>"
            name="<?php echo esc_attr($name); ?>"
            placeholder="<?php echo esc_attr($placeholder); ?>"
            class="large-text"
            rows="5"><?php echo esc_textarea($value); ?></textarea>
        <?php if ($desc !== '') : ?>
            <p class="description"><?php echo esc_html($desc); ?></p>
        <?php endif; ?>
        <?php
    }

    private static function render_select(
        string $input_id,
        string $name,
        string $value,
        array $options,
        string $desc
    ): void {
        ?>
        <select id="<?php echo esc_attr($input_id); ?>" name="<?php echo esc_attr($name); ?>">
            <?php foreach ($options as $opt_value => $opt_label) : ?>
                <option value="<?php echo esc_attr((string) $opt_value); ?>" <?php selected((string) $opt_value, $value); ?>>
                    <?php echo esc_html((string) $opt_label); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php if ($desc !== '') : ?>
            <p class="description"><?php echo esc_html($desc); ?></p>
        <?php endif; ?>
        <?php
    }
}
