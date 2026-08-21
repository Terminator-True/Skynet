<?php

namespace Tests\Eval;

/**
 * Hand-rolled structural JSON-Schema subset validator (~60 LOC by design —
 * schemas here have 0-2 properties; a dependency would be overkill).
 *
 * Checks required presence and top-level types only.
 */
class ToolCallValidator
{
    /**
     * @param  array<string, mixed>  $schema  JSON Schema object
     * @param  array<string, mixed>  $args  decoded model arguments
     * @return list<string> empty list == valid
     */
    public static function validate(array $schema, array $args): array
    {
        $errors = [];
        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];

        foreach ((array) ($schema['required'] ?? []) as $required) {
            if (! array_key_exists($required, $args) || $args[$required] === null || $args[$required] === '') {
                $errors[] = "Missing required argument [$required].";
            }
        }

        foreach ($properties as $prop => $definition) {
            if (! array_key_exists($prop, $args)) {
                continue;
            }

            $type = $definition['type'] ?? 'any';
            $value = $args[$prop];

            $ok = match ($type) {
                'string' => is_string($value),
                'number' => is_int($value) || is_float($value),
                'integer' => is_int($value),
                'boolean' => is_bool($value),
                'object' => is_array($value),
                'array' => is_array($value) && array_is_list($value),
                default => true,
            };

            if (! $ok) {
                $errors[] = "Argument [$prop] must be of type {$type}.";
            }
        }

        // Unknown extra keys are tolerated (models sometimes over-specify).
        return $errors;
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $args
     */
    public static function isValid(array $schema, array $args): bool
    {
        return self::validate($schema, $args) === [];
    }
}
