<?php

namespace App\Helpers;

class Validator
{
    public static function validate(array $data, array $rules): array
    {
        $errors = [];

        foreach ($rules as $field => $ruleString) {
            $value = $data[$field] ?? null;
            $fieldRules = explode('|', $ruleString);

            foreach ($fieldRules as $rule) {
                if ($rule === 'required' && ($value === null || trim((string)$value) === '')) {
                    $errors[$field][] = "The {$field} field is required.";
                    continue;
                }

                if ($value === null || $value === '') {
                    continue; // Skip further checks if optional and empty
                }

                if ($rule === 'email' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $errors[$field][] = "The {$field} must be a valid email address.";
                }

                if (str_starts_with($rule, 'min:')) {
                    $min = (int) substr($rule, 4);
                    if (strlen((string)$value) < $min) {
                        $errors[$field][] = "The {$field} must be at least {$min} characters.";
                    }
                }

                if (str_starts_with($rule, 'max:')) {
                    $max = (int) substr($rule, 4);
                    if (strlen((string)$value) > $max) {
                        $errors[$field][] = "The {$field} must not exceed {$max} characters.";
                    }
                }

                if ($rule === 'numeric' && !is_numeric($value)) {
                    $errors[$field][] = "The {$field} must be numeric.";
                }

                if ($rule === 'url' && !filter_var($value, FILTER_VALIDATE_URL)) {
                    $errors[$field][] = "The {$field} must be a valid URL.";
                }
            }
        }

        return $errors;
    }
}
