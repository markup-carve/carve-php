<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Json;

use stdClass;
use WeakMap;

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
     * Per schema object, the `anyOf`/`oneOf` branch by node type, or null when its branches are not all
     * type-discriminated node refs.
     *
     * @var \WeakMap<\stdClass, array<string, array<string, \stdClass>|null>>|null
     */
    private static ?WeakMap $dispatch = null;

    /**
     * @var \WeakMap<\stdClass, \MarkupCarve\Carve\Json\CompiledSchema>|null
     */
    private static ?WeakMap $compiled = null;

    /**
     * @var array<string, \stdClass|bool>
     */
    private array $refs = [];

    /**
     * @var array<string, string>
     */
    private static array $regexes = [];

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
        self::$compiled ??= new WeakMap();
        $compiled = self::$compiled[$schema] ?? null;
        if ($compiled === null) {
            $compiled = new CompiledSchema($schema);
            self::$compiled[$schema] = $compiled;
        }

        return $this->run($compiled, $value, $path);
    }

    /**
     * The checks of one schema, keyword by keyword in a fixed order.
     *
     * @param \MarkupCarve\Carve\Json\CompiledSchema $c
     * @param mixed $value
     * @param string $path
     *
     * @return bool
     */
    private function run(CompiledSchema $c, mixed $value, string $path): bool
    {
        if ($c->ref !== null && !$this->valid($value, $this->resolve($c->ref), $path)) {
            return false;
        }
        if ($c->type !== null && !self::typeOk($value, $c->type)) {
            return $this->fail($path, $c->typeError);
        }
        if ($c->hasConst && !self::same($value, $c->const)) {
            return $this->fail($path, $c->constError);
        }
        if ($c->enum !== null && !self::inEnum($value, $c->enum)) {
            return $this->fail($path, 'enum');
        }
        if (!$this->combinators($c, $value, $path)) {
            return false;
        }
        if (is_string($value)) {
            return $this->stringRules($c, $value, $path);
        }
        if (is_int($value) || is_float($value)) {
            return $this->numberRules($c, $value, $path);
        }
        if ($value instanceof stdClass) {
            return !$c->objectRules || $this->objectRules($c, $value, $path);
        }

        return !is_array($value) || !$c->arrayRules || $this->arrayRules($c, $value, $path);
    }

    /**
     * @param \MarkupCarve\Carve\Json\CompiledSchema $c
     * @param mixed $value
     * @param string $path
     *
     * @return bool
     */
    private function combinators(CompiledSchema $c, mixed $value, string $path): bool
    {
        foreach ($c->allOf as $sub) {
            if (!$this->valid($value, $sub, $path)) {
                return false;
            }
        }
        if ($c->anyOf !== null && !$this->count($value, $c->anyOf, $path, true, $c->schema, 'anyOf')) {
            return $this->fail($path, 'anyOf');
        }
        if ($c->oneOf !== null && $this->count($value, $c->oneOf, $path, false, $c->schema, 'oneOf') !== 1) {
            return $this->fail($path, 'oneOf');
        }
        if ($c->not !== null && $this->quiet($value, $c->not, $path)) {
            return $this->fail($path, 'not');
        }

        return !$c->hasIf || $this->valid($value, $this->quiet($value, $c->if, $path) ? $c->then : $c->else, $path);
    }

    private function stringRules(CompiledSchema $c, string $value, string $path): bool
    {
        if ($c->minLength !== null && mb_strlen($value) < $c->minLength) {
            return $this->fail($path, 'minLength');
        }
        if ($c->pattern !== null && !preg_match($c->regex, $value)) {
            return $this->fail($path, 'pattern ' . $c->pattern);
        }

        return true;
    }

    private function numberRules(CompiledSchema $c, float|int $value, string $path): bool
    {
        if ($c->minimum !== null && $value < $c->minimum) {
            return $this->fail($path, 'minimum');
        }
        if ($c->maximum !== null && $value > $c->maximum) {
            return $this->fail($path, 'maximum');
        }
        if ($c->exclusiveMinimum !== null && $value <= $c->exclusiveMinimum) {
            return $this->fail($path, 'exclusiveMinimum');
        }

        return true;
    }

    private function objectRules(CompiledSchema $c, stdClass $value, string $path): bool
    {
        $props = get_object_vars($value);
        foreach ($c->required as $key) {
            if (!array_key_exists($key, $props)) {
                return $this->fail($path, 'required ' . $key);
            }
        }
        $n = count($props);
        if (($c->minProperties !== null && $n < $c->minProperties) || ($c->maxProperties !== null && $n > $c->maxProperties)) {
            return $this->fail($path, 'property count');
        }
        foreach ($c->dependentRequired as $key => $needs) {
            foreach (array_key_exists($key, $props) ? $needs : [] as $need) {
                if (!array_key_exists($need, $props)) {
                    return $this->fail($path, "dependentRequired $key $need");
                }
            }
        }
        foreach ($props as $key => $item) {
            $key = (string)$key;
            if (!$this->member($c, $key, $item, "$path.$key")) {
                return false;
            }
        }

        return true;
    }

    private function member(CompiledSchema $c, string $key, mixed $item, string $at): bool
    {
        if ($c->propertyNames !== null && !$this->valid($key, $c->propertyNames, $at)) {
            return false;
        }
        $seen = false;
        $sub = $c->propertyChecks[$key] ?? null;
        if ($sub !== null) {
            $seen = true;
            if (!$this->compiled($sub, $item, $at)) {
                return false;
            }
        }
        foreach ($c->patternProperties as [$keyRegex, $pattern]) {
            if (preg_match($keyRegex, $key)) {
                $seen = true;
                if (!$this->valid($item, $pattern, $at)) {
                    return false;
                }
            }
        }
        if (!$seen && $c->additional !== null && !$this->valid($item, $c->additional, $at)) {
            return $this->fail($at, 'additional property');
        }

        return true;
    }

    /**
     * @param \MarkupCarve\Carve\Json\CompiledSchema $c
     * @param array<mixed> $value
     * @param string $path
     *
     * @return bool
     */
    private function arrayRules(CompiledSchema $c, array $value, string $path): bool
    {
        $n = count($value);
        if (($c->minItems !== null && $n < $c->minItems) || ($c->maxItems !== null && $n > $c->maxItems)) {
            return $this->fail($path, 'item count');
        }
        foreach ($value as $i => $item) {
            if (!$this->compiled($c->prefixChecks[$i] ?? $c->itemsCheck, $item, "{$path}[$i]")) {
                return false;
            }
        }
        if ($c->uniqueItems && count(array_unique(array_map('serialize', $value))) !== $n) {
            return $this->fail($path, 'uniqueItems');
        }

        return true;
    }

    private function compiled(CompiledSchema|bool $schema, mixed $value, string $path): bool
    {
        if (is_bool($schema)) {
            return $schema || $this->fail($path, 'false schema');
        }

        return $this->run($schema, $value, $path);
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
        if ((is_string($a) || is_int($a) || is_bool($a) || $a === null) && (is_string($b) || is_int($b) || is_bool($b) || $b === null)) {
            return $a === $b;
        }

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
        if (isset($this->refs[$ref])) {
            return $this->refs[$ref];
        }
        $node = $this->root;
        foreach (explode('/', substr($ref, 2)) as $part) {
            $node = $node->{str_replace(['~1', '~0'], ['/', '~'], $part)};
        }

        return $this->refs[$ref] = $node;
    }

    /**
     * @param mixed $value
     * @param mixed $type
     *
     * @return bool
     */
    private static function typeOk(mixed $value, mixed $type): bool
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
     * @param array<\stdClass|bool> $schemas
     * @param string $path
     * @param bool $any
     * @param \stdClass $owner
     * @param string $keyword
     *
     * @return int
     */
    private function count(mixed $value, array $schemas, string $path, bool $any, stdClass $owner, string $keyword): int
    {
        $byType = $this->dispatch($owner, $keyword, $schemas);
        if ($byType !== null) {
            // Every other branch fails on its `type` const, so only the branch of the node's own type can match.
            $sub = $value instanceof stdClass && isset($value->type) && is_string($value->type) ? ($byType[$value->type] ?? null) : null;
            if ($sub === null) {
                return 0;
            }
            if (str_ends_with((string)$sub->{'$ref'}, '/' . $value->type)) {
                return $this->valid($value, $sub, $path) ? 1 : 0;
            }

            return $this->quiet($value, $sub, $path) ? 1 : 0;
        }
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

    public static function regex(string $pattern): string
    {
        return self::$regexes[$pattern] ??= '/' . str_replace('/', '\/', $pattern) . '/u';
    }

    /**
     * @param \stdClass $owner
     * @param string $keyword
     * @param array<\stdClass|bool> $schemas
     *
     * @return array<string, \stdClass>|null
     */
    private function dispatch(stdClass $owner, string $keyword, array $schemas): ?array
    {
        self::$dispatch ??= new WeakMap();
        $cached = self::$dispatch[$owner] ?? [];
        if (array_key_exists($keyword, $cached)) {
            return $cached[$keyword];
        }
        $byType = [];
        foreach ($schemas as $sub) {
            $target = $sub instanceof stdClass && array_keys(get_object_vars($sub)) === ['$ref']
                ? $this->resolve((string)$sub->{'$ref'}) : null;
            $const = $target instanceof stdClass && ($target->type ?? null) === 'object'
                && in_array('type', (array)($target->required ?? []), true) ? ($target->properties->type->const ?? null) : null;
            if (!is_string($const) || isset($byType[$const])) {
                $byType = null;

                break;
            }
            $byType[$const] = $sub;
        }
        $cached[$keyword] = $byType;
        self::$dispatch[$owner] = $cached;

        return $byType;
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
