<?php
/**
 * Shared form-field helpers used across the theme.
 * These are plain functions (not classes) so any view can call them.
 */

if (!function_exists('field_text')) {
    function field_text(string $name, string $label, mixed $value = '', array $opts = []): string
    {
        $type       = $opts['type'] ?? 'text';
        $required   = !empty($opts['required']) ? ' required' : '';
        $placeholder= isset($opts['placeholder']) ? ' placeholder="' . e($opts['placeholder']) . '"' : '';
        $hint       = isset($opts['hint']) ? '<span class="hint">' . e($opts['hint']) . '</span>' : '';
        $span       = !empty($opts['span']) ? ' span-2' : '';
        $autocomplete = $opts['autocomplete'] ?? 'off';
        $pattern    = isset($opts['pattern']) ? ' pattern="' . e($opts['pattern']) . '"' : '';
        $min        = isset($opts['min']) ? ' min="' . e((string) $opts['min']) . '"' : '';
        $max        = isset($opts['max']) ? ' max="' . e((string) $opts['max']) . '"' : '';
        $readonly   = !empty($opts['readonly']) ? ' readonly' : '';

        return '<div class="field' . $span . '">'
            . '<label for="' . e($name) . '">' . e($label) . '</label>'
            . '<input type="' . e($type) . '" id="' . e($name) . '" name="' . e($name) . '"'
            . ' value="' . e($value) . '"' . $required . $placeholder . $pattern . $min . $max . $readonly
            . ' autocomplete="' . e($autocomplete) . '">'
            . $hint
            . '</div>';
    }
}

if (!function_exists('field_select')) {
    function field_select(string $name, string $label, array $options, mixed $selected = null, array $opts = []): string
    {
        $required = !empty($opts['required']) ? ' required' : '';
        $hint     = isset($opts['hint']) ? '<span class="hint">' . e($opts['hint']) . '</span>' : '';
        $span     = !empty($opts['span']) ? ' span-2' : '';
        $attrs    = $opts['attrs'] ?? '';
        $multiple = !empty($opts['multiple']) ? ' multiple' : '';

        $html = '<div class="field' . $span . '">'
            . '<label for="' . e($name) . '">' . e($label) . '</label>'
            . '<select id="' . e($name) . '" name="' . e($name) . '"' . $required . $multiple . ' ' . $attrs . '>';

        if (!empty($opts['placeholder'])) {
            $html .= '<option value="">' . e($opts['placeholder']) . '</option>';
        }

        // Options may be flat [value => label], a list of option records, or
        // grouped optgroups containing either representation.
        $isRecord = static fn (mixed $value): bool => is_array($value)
            && (array_key_exists('id', $value) || array_key_exists('value', $value))
            && (array_key_exists('label', $value) || array_key_exists('name', $value));
        $isRecordList = $options !== [] && array_is_list($options)
            && array_reduce($options, static fn (bool $ok, mixed $value): bool => $ok && $isRecord($value), true);
        $isGrouped = !$isRecordList && array_reduce(
            $options,
            static fn (bool $found, mixed $value): bool => $found || is_array($value),
            false
        );

        $renderOption = static function (mixed $value, mixed $label) use ($selected): string {
            $sel = (string) $selected === (string) $value ? ' selected' : '';
            return '<option value="' . e((string) $value) . '"' . $sel . '>' . e((string) $label) . '</option>';
        };

        if ($isGrouped) {
            foreach ($options as $groupLabel => $group) {
                if (!is_array($group)) continue;
                $groupText = ucwords(str_replace(['_', '-'], ' ', (string) $groupLabel));
                $html .= '<optgroup label="' . e($groupText) . '" data-type="' . e((string) $groupLabel) . '">';
                foreach ($group as $key => $opt) {
                    if ($isRecord($opt)) {
                        $value = $opt['id'] ?? $opt['value'];
                        $label = $opt['label'] ?? $opt['name'];
                    } else {
                        $value = $key;
                        $label = $opt;
                    }
                    $html .= $renderOption($value, $label);
                }
                $html .= '</optgroup>';
            }
        } elseif ($isRecordList) {
            foreach ($options as $opt) {
                $value = $opt['id'] ?? $opt['value'];
                $label = $opt['label'] ?? $opt['name'];
                $html .= $renderOption($value, $label);
            }
        } else {
            foreach ($options as $value => $label) {
                $html .= $renderOption($value, $label);
            }
        }

        $html .= '</select>' . $hint . '</div>';
        return $html;
    }
}

if (!function_exists('field_check')) {
    function field_check(string $name, string $label, bool $checked, string $desc = '', string $span = ''): string
    {
        return '<label class="check' . ($span ? ' ' . $span : '') . '">'
            . '<input type="checkbox" name="' . e($name) . '" value="1"' . ($checked ? ' checked' : '') . '>'
            . '<span class="check-body"><strong>' . e($label) . '</strong>'
            . ($desc !== '' ? '<span>' . e($desc) . '</span>' : '')
            . '</span></label>';
    }
}

if (!function_exists('field_textarea')) {
    function field_textarea(string $name, string $label, string $value = '', array $opts = []): string
    {
        $hint = isset($opts['hint']) ? '<span class="hint">' . e($opts['hint']) . '</span>' : '';
        $span = !empty($opts['span']) ? ' span-2' : '';
        $rows = $opts['rows'] ?? 4;

        return '<div class="field' . $span . '">'
            . '<label for="' . e($name) . '">' . e($label) . '</label>'
            . '<textarea id="' . e($name) . '" name="' . e($name) . '" rows="' . (int) $rows . '">'
            . e($value) . '</textarea>' . $hint . '</div>';
    }
}

if (!function_exists('csrf')) {
    function csrf(): string
    {
        return \App\Core\Csrf::field();
    }
}
