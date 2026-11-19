<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Json;

use stdClass;

/**
 * A JSON Schema validator for the keywords ast-schema.json uses (draft 2020-12 subset).
 * Values are decoded with objects as stdClass, so an empty object and an empty array differ.
 */
final class SchemaValidator
{
    /**
     * @var array<string>
     */
    public array $errors = [];

    /**
     * @param \stdClass $root
     */
    public function __construct(private stdClass $root)
    {
    }

    /**
     * @param mixed $value
     * @param \stdClass|bool $schema
     * @param string $path
     *
     * @return bool
     */
    public function valid(mixed $value, stdClass|bool $schema, string $path = '$'): bool
    {
        if (is_bool($schema)) {
            return $schema || $this->fail($path, 'false schema');
        }
        if (isset($schema->{'$ref'})) {
            if (!$this->valid($value, $this->resolve((string)$schema->{'$ref'}), $path)) {
                return false;
            }
        }
        if (isset($schema->type) && !$this->typeOk($value, $schema->type)) {
            return $this->fail($path, 'type ' . json_encode($schema->type));
        }
        if (property_exists($schema, 'const') && !self::same($value, $schema->const)) {
            return $this->fail($path, 'const ' . json_encode($schema->const));
        }
        if (isset($schema->enum) && !self::inEnum($value, (array)$schema->enum)) {
            return $this->fail($path, 'enum');
        }

        return $this->combinators($value, $schema, $path)
            && $this->scalarRules($value, $schema, $path)
            && ($value instanceof stdClass ? $this->objectRules($value, $schema, $path) : true)
            && (is_array($value) ? $this->arrayRules($value, $schema, $path) : true);
    }

    /**
     * JSON equality: no loose comparison, so `"csv"` is not `true`.
     *
     * @param mixed $a
     * @param mixed $b
     *
     * @return bool
     */
    private static function same(mixed $a, mixed $b): bool
    {
        return json_encode($a) === json_encode($b);
    }

    /**
     * @param mixed $value
     * @param array<mixed> $enum
     *
     * @return bool
     */
    private static function inEnum(mixed $value, array $enum): bool
    {
        foreach ($enum as $candidate) {
            if (self::same($value, $candidate)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string $ref
     *
     * @return \stdClass|bool
     */
    private function resolve(string $ref): stdClass|bool
    {
        $node = $this->root;
        foreach (explode('/', substr($ref, 2)) as $part) {
            $node = $node->{str_replace(['~1', '~0'], ['/', '~'], $part)};
        }

        return $node;
    }

    /**
     * @param mixed $value
     * @param mixed $type
     *
     * @return bool
     */
    private function typeOk(mixed $value, mixed $type): bool
    {
        foreach ((array)$type as $t) {
            $ok = match ($t) {
                'object' => $value instanceof stdClass,
                'array' => is_array($value),
                'string' => is_string($value),
                'integer' => is_int($value) || (is_float($value) && floor($value) === $value),
                'number' => is_int($value) || is_float($value),
                'boolean' => is_bool($value),
                'null' => $value === null,
                default => false,
            };
            if ($ok) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param mixed $value
     * @param \stdClass $schema
     * @param string $path
     *
     * @return bool
     */
    private function combinators(mixed $value, stdClass $schema, string $path): bool
    {
        foreach ($schema->allOf ?? [] as $sub) {
            if (!$this->valid($value, $sub, $path)) {
                return false;
            }
        }
        if (isset($schema->anyOf) && !$this->count($value, $schema->anyOf, $path, true)) {
            return $this->fail($path, 'anyOf');
        }
        if (isset($schema->oneOf) && $this->count($value, $schema->oneOf, $path, false) !== 1) {
            return $this->fail($path, 'oneOf');
        }
        if (isset($schema->not) && $this->quiet($value, $schema->not, $path)) {
            return $this->fail($path, 'not');
        }
        if (isset($schema->if)) {
            $branch = $this->quiet($value, $schema->if, $path) ? ($schema->then ?? true) : ($schema->else ?? true);

            return $this->valid($value, $branch, $path);
        }

        return true;
    }

    /**
     * @param mixed $value
     * @param array<\stdClass|bool> $schemas
     * @param string $path
     * @param bool $any
     *
     * @return int
     */
    private function count(mixed $value, array $schemas, string $path, bool $any): int
    {
        $n = 0;
        foreach ($schemas as $sub) {
            if ($this->quiet($value, $sub, $path)) {
                $n++;
                if ($any) {
                    return $n;
                }
            }
        }
        if ($n === 0 && $value instanceof stdClass && isset($value->type)) {
            // Report the branch whose type matches, which is the useful error for a node.
            foreach ($schemas as $sub) {
                if ($sub instanceof stdClass && isset($sub->{'$ref'}) && str_ends_with((string)$sub->{'$ref'}, '/' . $value->type)) {
                    $this->valid($value, $sub, $path);
                }
            }
        }

        return $n;
    }

    /**
     * @param mixed $value
     * @param \stdClass|bool $schema
     * @param string $path
     *
     * @return bool
     */
    private function quiet(mixed $value, stdClass|bool $schema, string $path): bool
    {
        $saved = $this->errors;
        $ok = $this->valid($value, $schema, $path);
        $this->errors = $saved;

        return $ok;
    }

    /**
     * @param mixed $value
     * @param \stdClass $schema
     * @param string $path
     *
     * @return bool
     */
    private function scalarRules(mixed $value, stdClass $schema, string $path): bool
    {
        if (is_string($value)) {
            if (isset($schema->minLength) && mb_strlen($value) < $schema->minLength) {
                return $this->fail($path, 'minLength');
            }
            if (isset($schema->pattern) && !preg_match('/' . str_replace('/', '\/', (string)$schema->pattern) . '/u', $value)) {
                return $this->fail($path, 'pattern ' . $schema->pattern);
            }
        }
        if (is_int($value) || is_float($value)) {
            if (isset($schema->minimum) && $value < $schema->minimum) {
                return $this->fail($path, 'minimum');
            }
            if (isset($schema->maximum) && $value > $schema->maximum) {
                return $this->fail($path, 'maximum');
            }
            if (isset($schema->exclusiveMinimum) && $value <= $schema->exclusiveMinimum) {
                return $this->fail($path, 'exclusiveMinimum');
            }
        }

        return true;
    }

    /**
     * @param \stdClass $value
     * @param \stdClass $schema
     * @param string $path
     *
     * @return bool
     */
    private function objectRules(stdClass $value, stdClass $schema, string $path): bool
    {
        $props = get_object_vars($value);
        foreach ($schema->required ?? [] as $key) {
            if (!array_key_exists($key, $props)) {
                return $this->fail($path, 'required ' . $key);
            }
        }
        $n = count($props);
        if (
            (isset($schema->minProperties) && $n < $schema->minProperties) || (isset($schema->maxProperties)
            && $n > $schema->maxProperties)
        ) {
            return $this->fail($path, 'property count');
        }
        foreach ((array)($schema->dependentRequired ?? []) as $key => $needs) {
            foreach (array_key_exists($key, $props) ? $needs : [] as $need) {
                if (!array_key_exists($need, $props)) {
                    return $this->fail($path, "dependentRequired $key $need");
                }
            }
        }
        foreach ($props as $key => $v) {
            $key = (string)$key;
            if (isset($schema->propertyNames) && !$this->valid($key, $schema->propertyNames, "$path.$key")) {
                return false;
            }
            if (!$this->property($key, $v, $schema, $path)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param string $key
     * @param mixed $v
     * @param \stdClass $schema
     * @param string $path
     *
     * @return bool
     */
    private function property(string $key, mixed $v, stdClass $schema, string $path): bool
    {
        $seen = false;
        if (isset($schema->properties) && property_exists($schema->properties, $key)) {
            $seen = true;
            if (!$this->valid($v, $schema->properties->{$key}, "$path.$key")) {
                return false;
            }
        }
        foreach ((array)($schema->patternProperties ?? []) as $pattern => $sub) {
            if (preg_match('/' . str_replace('/', '\/', (string)$pattern) . '/u', $key)) {
                $seen = true;
                if (!$this->valid($v, $sub, "$path.$key")) {
                    return false;
                }
            }
        }
        if (!$seen && isset($schema->additionalProperties)) {
            return $this->valid($v, $schema->additionalProperties, "$path.$key") || $this->fail("$path.$key", 'additional property');
        }

        return true;
    }

    /**
     * @param array<mixed> $value
     * @param \stdClass $schema
     * @param string $path
     *
     * @return bool
     */
    private function arrayRules(array $value, stdClass $schema, string $path): bool
    {
        $n = count($value);
        if ((isset($schema->minItems) && $n < $schema->minItems) || (isset($schema->maxItems) && $n > $schema->maxItems)) {
            return $this->fail($path, 'item count');
        }
        $prefix = $schema->prefixItems ?? [];
        foreach ($value as $i => $item) {
            $sub = $prefix[$i] ?? ($schema->items ?? true);
            if (!$this->valid($item, $sub, "{$path}[$i]")) {
                return false;
            }
        }
        if (!empty($schema->uniqueItems) && count(array_unique(array_map('serialize', $value))) !== $n) {
            return $this->fail($path, 'uniqueItems');
        }

        return true;
    }

    /**
     * @param string $path
     * @param string $why
     *
     * @return bool
     */
    private function fail(string $path, string $why): bool
    {
        $this->errors[] = "$path: $why";

        return false;
    }
}
