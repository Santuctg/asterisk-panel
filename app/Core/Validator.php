<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Lightweight validator for inbound form data.
 *
 *   $v = Validator::make($request->all(), [
 *       'extension' => 'required|integer|between:2,6',
 *       'email'     => 'email',
 *   ]);
 *   if ($v->fails()) { ... $v->errors() ... }
 */
final class Validator
{
    /** @var array<string, string> */
    private array $errors = [];

    /** @param array<string, mixed> $data */
    private function __construct(private array $data)
    {
    }

    /** @param array<string, string> $rules e.g. ['name' => 'required|min:2'] */
    public static function make(array $data, array $rules): self
    {
        $v = new self($data);

        foreach ($rules as $field => $ruleString) {
            foreach (explode('|', $ruleString) as $rule) {
                [$name, $param] = array_pad(explode(':', $rule, 2), 2, null);
                $v->apply($field, $name, $param);
            }
        }

        return $v;
    }

    private function apply(string $field, string $rule, ?string $param): void
    {
        $value = $this->data[$field] ?? null;
        $empty = $value === null || $value === '' || $value === [];

        if ($rule === 'nullable' && $empty) {
            return;
        }

        switch ($rule) {
            case 'required':
                if ($empty) {
                    $this->errors[$field] ??= "The {$this->label($field)} field is required.";
                }
                break;

            case 'nullable':
            case 'sometimes':
                break;

            case 'integer':
                if (!$empty && filter_var($value, FILTER_VALIDATE_INT) === false) {
                    $this->errors[$field] ??= "The {$this->label($field)} must be a whole number.";
                }
                break;

            case 'numeric':
                if (!$empty && !is_numeric($value)) {
                    $this->errors[$field] ??= "The {$this->label($field)} must be numeric.";
                }
                break;

            case 'email':
                if (!$empty && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $this->addError($field, "The {$this->label($field)} must be a valid email address.");
                }
                break;

            case 'url':
                if (!$empty && !filter_var($value, FILTER_VALIDATE_URL)) {
                    $this->addError($field, "The {$this->label($field)} must be a valid URL.");
                }
                break;

            case 'alpha_dash':
                if (!$empty && !preg_match('/^[a-zA-Z0-9_-]+$/', (string) $value)) {
                    $this->addError($field, "The {$this->label($field)} may only contain letters, numbers, dashes and underscores.");
                }
                break;

            case 'min':
                if (!$empty && mb_strlen((string) $value) < (int) $param) {
                    $this->addError($field, "The {$this->label($field)} must be at least {$param} characters.");
                }
                break;

            case 'max':
                if (!$empty && mb_strlen((string) $value) > (int) $param) {
                    $this->addError($field, "The {$this->label($field)} may not exceed {$param} characters.");
                }
                break;

            case 'between':
                [$min, $max] = array_map('intval', explode(',', (string) $param));
                $len = is_numeric($value) ? strlen((string) $value) : mb_strlen((string) $value);
                if (!$empty && ($len < $min || $len > $max)) {
                    $this->addError($field, "The {$this->label($field)} must be between {$min} and {$max} characters.");
                }
                break;

            case 'in':
                if (!$empty && !in_array((string) $value, explode(',', (string) $param), true)) {
                    $this->addError($field, "The selected {$this->label($field)} is invalid.");
                }
                break;

            case 'mac':
                if (!$empty && !preg_match('/^([0-9A-Fa-f]{2}[:-]){5}([0-9A-Fa-f]{2})$/', (string) $value)) {
                    $this->addError($field, "The {$this->label($field)} must be a valid MAC address (e.g. 00:15:65:aa:bb:cc).");
                }
                break;

            case 'digits':
                if (!$empty && !preg_match('/^\d{' . (int) $param . '}$/', (string) $value)) {
                    $this->addError($field, "The {$this->label($field)} must be exactly {$param} digits.");
                }
                break;

            case 'digits_between':
                [$min, $max] = array_map('intval', explode(',', (string) $param));
                if (!$empty && !preg_match('/^\d{' . $min . ',' . $max . '}$/', (string) $value)) {
                    $this->addError($field, "The {$this->label($field)} must be between {$min} and {$max} digits.");
                }
                break;

            case 'confirmed':
                if (!$empty && $value !== ($this->data[$field . '_confirmation'] ?? null)) {
                    $this->addError($field, "The {$this->label($field)} confirmation does not match.");
                }
                break;

            case 'unique':
                // Format: unique:table,column[,id[,tenant_scope]]
                [$table, $column, $id, $scopeField] = array_pad(explode(',', (string) $param), 4, null);
                $column ??= $field;

                $sql    = "SELECT COUNT(*) FROM {$table} WHERE {$column} = ?";
                $params = [$value];

                if ($id) {
                    $sql .= ' AND id != ?';
                    $params[] = (int) $id;
                }
                if ($scopeField && !empty($this->data['tenant_id'])) {
                    $sql .= ' AND tenant_id = ?';
                    $params[] = (int) $this->data['tenant_id'];
                }

                if (App::getInstance()->db()->scalar($sql, $params) > 0) {
                    $this->addError($field, "That {$this->label($field)} is already in use.");
                }
                break;

            case 'exists':
                // Format: exists:table,column
                [$table, $column] = array_pad(explode(',', (string) $param), 2, 'id');
                $exists = App::getInstance()->db()->scalar(
                    "SELECT COUNT(*) FROM {$table} WHERE {$column} = ?",
                    [$value]
                );
                if (!$empty && (int) $exists === 0) {
                    $this->addError($field, "The selected {$this->label($field)} does not exist.");
                }
                break;
        }

        unset($GLOBALS['__v_err']);
    }

    private function addError(string $field, string $message): void
    {
        $this->errors[$field] ??= $message;
    }

    private function label(string $field): string
    {
        return str_replace('_', ' ', $field);
    }

    public function fails(): bool
    {
        return $this->errors !== [];
    }

    public function passes(): bool
    {
        return $this->errors === [];
    }

    /** @return array<string, string> */
    public function errors(): array
    {
        return $this->errors;
    }

    public function firstError(): ?string
    {
        return $this->errors === [] ? null : (string) reset($this->errors);
    }

    /** Return only the validated/known fields from the source data. */
    public function only(array $keys): array
    {
        $out = [];
        foreach ($keys as $k) {
            $out[$k] = $this->data[$k] ?? null;
        }
        return $out;
    }
}
