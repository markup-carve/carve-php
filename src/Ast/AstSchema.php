<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Ast;

use JsonException;
use RuntimeException;
use function array_is_list;
use function array_key_exists;
use function dirname;
use function file_get_contents;
use function in_array;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_string;
use function json_decode;
use function sprintf;
use function str_starts_with;
use function substr;
use const JSON_THROW_ON_ERROR;

/**
 * PART 12 §12(d): AN INGEST VALIDATES THE WHOLE PAYLOAD AGAINST THE AST SCHEMA
 * (markup-carve/carve#881).
 *
 * Types and required fields together, at DECODE, refused with the same typed
 * error §12(a), (b) and (c) already require. Not a fourth list of leniency
 * points: the schema IS the list, it already describes every row that diverged,
 * and those rows were only ever divergent because nothing consulted it.
 *
 * WHY A VALIDATOR RATHER THAN MORE HAND-WRITTEN CHECKS. Ruling the rows one at
 * a time is what produced the state this replaces - a payload whose `text.value`
 * was the number 7 rendered `<p>7</p>`, a `children: null` read as an empty
 * document, and two shapes that failed with a bare `TypeError`, which §9(b)
 * forbids. Every future schema addition becomes a rejection for a producer that
 * has not caught up, and that is the point rather than a side effect: it is what
 * makes the schema the contract instead of a description of it.
 *
 * THE SCHEMA IS VENDORED, as `resources/prosemirror-schema-map.json` already is.
 * The spec repo is a TEST fixture here and is not installed at runtime, so a
 * runtime rule cannot read it from there. `AstSchemaVendoredCopyTest` compares
 * the two byte for byte, so the copy cannot drift.
 *
 * THE KEYWORD SET IS THE ONE THE SCHEMA USES, and no more: `$ref` (local only),
 * `type`, `properties`, `required`, `additionalProperties`, `items`, `const`,
 * `enum`, `anyOf`, `oneOf`, `allOf`, `if`/`then`, `minimum` and `maximum`. A
 * general JSON Schema implementation would be a dependency this package does not
 * have and a much larger surface to be wrong in; a keyword the schema starts
 * using and this does not support would silently accept everything, so
 * `AstSchemaKeywordCoverageTest` walks the schema and fails on an unknown one.
 */
final class AstSchema
{
    /**
     * Keywords this validator implements. See the class docblock: the guard
     * against the schema growing past it is a test, not a runtime check, so
     * that the failure lands on whoever bumps the submodule.
     *
     * @var list<string>
     */
    public const SUPPORTED_KEYWORDS = [
        '$ref',
        'type',
        'properties',
        'required',
        'additionalProperties',
        'items',
        'const',
        'enum',
        'anyOf',
        'oneOf',
        'allOf',
        'if',
        'then',
        'minimum',
        'maximum',
    ];

    /**
     * Keywords that carry prose rather than a constraint, and are skipped
     * WITHOUT being reported as unsupported.
     *
     * @var list<string>
     */
    public const ANNOTATION_KEYWORDS = ['$schema', '$id', 'title', 'description', '$defs', 'examples', 'default'];

    /**
     * @var array<string, mixed>|null
     */
    private static ?array $schema = null;

    /**
     * The first way `$payload` fails the schema, or null when it satisfies it.
     *
     * FIRST rather than all: the message is an error a producer acts on, and the
     * rows this clause closes are single mistakes - a `class` map where `attrs`
     * belongs, a `children` that is null. Reporting every consequence of one
     * mistake buries it.
     *
     * AN APPLICATION TYPE IS EXEMPT, subtree and all. `AstCodec::register()`
     * is how a consuming application teaches this codec a node class defined
     * outside the package (docs/ast-json.md), and the schema cannot name a type
     * it has never heard of - so §12(d) has nothing to say about one. The
     * exemption is by NAME and only for types actually registered, so it cannot
     * be used to smuggle a core type past the rule.
     *
     * @param array<mixed> $payload
     * @param list<string> $exemptTypes
     *
     * @return string|null
     */
    public static function firstViolation(array $payload, array $exemptTypes = []): ?string
    {
        $schema = self::schema();

        return self::check($payload, $schema, $schema, '$', $exemptTypes);
    }

    /**
     * The schema as vendored, decoded once.
     *
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        if (self::$schema !== null) {
            return self::$schema;
        }

        $path = dirname(__DIR__, 2) . '/resources/ast-schema.json';
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException(sprintf('Cannot read the AST schema at %s', $path));
        }

        try {
            /** @var array<string, mixed> $schema */
            $schema = json_decode($contents, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException(sprintf('The AST schema at %s is not valid JSON', $path), 0, $e);
        }

        self::$schema = $schema;

        return $schema;
    }

    /**
     * @param mixed $value
     * @param array<mixed> $schema
     * @param array<string, mixed> $root
     * @param string $path
     * @param list<string> $exempt
     *
     * @throws \RuntimeException
     *
     * @return string|null
     */
    private static function check(mixed $value, array $schema, array $root, string $path, array $exempt): ?string
    {
        if ($exempt !== [] && is_array($value) && is_string($value['type'] ?? null) && in_array($value['type'], $exempt, true)) {
            return null;
        }

        if (isset($schema['$ref']) && is_string($schema['$ref'])) {
            $resolved = self::resolve($schema['$ref'], $root);
            if ($resolved === null) {
                throw new RuntimeException(sprintf('The AST schema has an unresolvable ref %s', $schema['$ref']));
            }

            return self::check($value, $resolved, $root, $path, $exempt);
        }

        foreach (
            [
                'type' => 'checkType',
                'const' => 'checkConst',
                'enum' => 'checkEnum',
                'minimum' => 'checkMinimum',
                'maximum' => 'checkMaximum',
                'required' => 'checkRequired',
            ] as $keyword => $method
        ) {
            if (!array_key_exists($keyword, $schema)) {
                continue;
            }
            $failure = self::$method($value, $schema[$keyword], $path);
            if ($failure !== null) {
                return $failure;
            }
        }

        $failure = self::checkComposition($value, $schema, $root, $path, $exempt);
        if ($failure !== null) {
            return $failure;
        }

        return self::checkChildren($value, $schema, $root, $path, $exempt);
    }

    /**
     * `anyOf`, `oneOf`, `allOf` and the `if`/`then` pair.
     *
     * `if` WITHOUT `then` constrains nothing, and the schema never writes it, so
     * a missing `then` is a no-op rather than an error - and `else` is absent
     * from the keyword list because the schema does not use it, which the
     * coverage test enforces rather than this comment.
     *
     * @param mixed $value
     * @param array<mixed> $schema
     * @param array<string, mixed> $root
     * @param string $path
     * @param list<string> $exempt
     *
     * @return string|null
     */
    private static function checkComposition(mixed $value, array $schema, array $root, string $path, array $exempt): ?string
    {
        if (isset($schema['allOf']) && is_array($schema['allOf'])) {
            foreach ($schema['allOf'] as $branch) {
                if (!is_array($branch)) {
                    continue;
                }
                $failure = self::check($value, $branch, $root, $path, $exempt);
                if ($failure !== null) {
                    return $failure;
                }
            }
        }

        foreach (['anyOf', 'oneOf'] as $keyword) {
            if (!isset($schema[$keyword]) || !is_array($schema[$keyword])) {
                continue;
            }
            $matched = 0;
            $first = null;
            foreach ($schema[$keyword] as $branch) {
                if (!is_array($branch)) {
                    continue;
                }
                $failure = self::check($value, $branch, $root, $path, $exempt);
                if ($failure === null) {
                    $matched++;

                    continue;
                }
                $first ??= $failure;
            }
            if ($matched === 0) {
                return $first ?? sprintf('%s matches no branch of `%s`', $path, $keyword);
            }
        }

        if (isset($schema['if']) && is_array($schema['if']) && isset($schema['then']) && is_array($schema['then'])) {
            if (self::check($value, $schema['if'], $root, $path, $exempt) === null) {
                return self::check($value, $schema['then'], $root, $path, $exempt);
            }
        }

        return null;
    }

    /**
     * `properties`, `additionalProperties` and `items`.
     *
     * A PHP array is both of JSON's containers, so the distinction is taken
     * from the SCHEMA: `properties` names object members and `items` describes
     * a list. An empty array is legitimately either, and is left to whichever
     * one the schema asked for.
     *
     * @param mixed $value
     * @param array<mixed> $schema
     * @param array<string, mixed> $root
     * @param string $path
     * @param list<string> $exempt
     *
     * @return string|null
     */
    private static function checkChildren(mixed $value, array $schema, array $root, string $path, array $exempt): ?string
    {
        if (!is_array($value)) {
            return null;
        }

        if (isset($schema['items']) && is_array($schema['items']) && array_is_list($value)) {
            foreach ($value as $index => $item) {
                $failure = self::check($item, $schema['items'], $root, sprintf('%s[%d]', $path, $index), $exempt);
                if ($failure !== null) {
                    return $failure;
                }
            }
        }

        $properties = isset($schema['properties']) && is_array($schema['properties']) ? $schema['properties'] : [];
        foreach ($properties as $name => $subschema) {
            if (!is_array($subschema) || !array_key_exists($name, $value)) {
                continue;
            }
            $failure = self::check($value[$name], $subschema, $root, $path . '.' . $name, $exempt);
            if ($failure !== null) {
                return $failure;
            }
        }

        // NOT gated on `properties` being non-empty. `attrs.keyValues` is the
        // one place in the schema that carries `additionalProperties` ALONE -
        // every key is free, every value must be a string - so skipping it
        // there let `{"foo": 7}` through, and the decoder then dropped the
        // non-string value while reporting success. A whole-payload rule that
        // stops at the one wide-open object is not a whole-payload rule.
        if (!array_key_exists('additionalProperties', $schema)) {
            return null;
        }
        $additional = $schema['additionalProperties'];
        foreach ($value as $name => $member) {
            if (array_key_exists($name, $properties)) {
                continue;
            }
            if ($additional === false) {
                return sprintf('%s carries `%s`, which the schema does not name', $path, (string)$name);
            }
            if (is_array($additional)) {
                $failure = self::check($member, $additional, $root, $path . '.' . (string)$name, $exempt);
                if ($failure !== null) {
                    return $failure;
                }
            }
        }

        return null;
    }

    /**
     * @param mixed $value
     * @param mixed $expected
     * @param string $path
     *
     * @return string|null
     */
    private static function checkType(mixed $value, mixed $expected, string $path): ?string
    {
        $names = is_array($expected) ? $expected : [$expected];
        foreach ($names as $name) {
            if (is_string($name) && self::isOfType($value, $name)) {
                return null;
            }
        }

        return sprintf(
            '%s is %s where the schema requires %s',
            $path,
            self::describe($value),
            is_array($expected)
                ? 'one of ' . implode('/', array_map(static fn (mixed $name): string => (string)(is_string($name) ? $name : ''), $expected))
                : (is_string($expected) ? $expected : get_debug_type($expected)),
        );
    }

    private static function isOfType(mixed $value, string $name): bool
    {
        return match ($name) {
            // An empty PHP array is both an empty object and an empty list;
            // `properties`/`items` decide which one was meant.
            'object' => is_array($value) && (!array_is_list($value) || $value === []),
            'array' => is_array($value) && array_is_list($value),
            'string' => is_string($value),
            // `true` and `false` are not integers here, though PHP will happily
            // compare them as such.
            'integer' => is_int($value),
            'number' => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            'null' => $value === null,
            default => false,
        };
    }

    /**
     * @param mixed $value
     * @param mixed $expected
     * @param string $path
     *
     * @return string|null
     */
    private static function checkConst(mixed $value, mixed $expected, string $path): ?string
    {
        return $value === $expected
            ? null
            : sprintf('%s is %s where the schema requires %s', $path, self::describe($value), self::describe($expected));
    }

    /**
     * @param mixed $value
     * @param mixed $allowed
     * @param string $path
     *
     * @return string|null
     */
    private static function checkEnum(mixed $value, mixed $allowed, string $path): ?string
    {
        if (!is_array($allowed) || in_array($value, $allowed, true)) {
            return null;
        }

        return sprintf('%s is %s, which the schema does not list', $path, self::describe($value));
    }

    /**
     * @param mixed $value
     * @param mixed $bound
     * @param string $path
     *
     * @return string|null
     */
    private static function checkMinimum(mixed $value, mixed $bound, string $path): ?string
    {
        if ((!is_int($value) && !is_float($value)) || (!is_int($bound) && !is_float($bound))) {
            return null;
        }

        return $value >= $bound ? null : sprintf('%s is %s, below the schema minimum %s', $path, (string)$value, (string)$bound);
    }

    /**
     * @param mixed $value
     * @param mixed $bound
     * @param string $path
     *
     * @return string|null
     */
    private static function checkMaximum(mixed $value, mixed $bound, string $path): ?string
    {
        if ((!is_int($value) && !is_float($value)) || (!is_int($bound) && !is_float($bound))) {
            return null;
        }

        return $value <= $bound ? null : sprintf('%s is %s, above the schema maximum %s', $path, (string)$value, (string)$bound);
    }

    /**
     * @param mixed $value
     * @param mixed $names
     * @param string $path
     *
     * @return string|null
     */
    private static function checkRequired(mixed $value, mixed $names, string $path): ?string
    {
        if (!is_array($value) || !is_array($names)) {
            return null;
        }
        foreach ($names as $name) {
            if (!is_string($name)) {
                continue;
            }
            if (!array_key_exists($name, $value)) {
                return sprintf('%s is missing `%s`, which the schema requires', $path, $name);
            }
        }

        return null;
    }

    /**
     * @param string $ref
     * @param array<string, mixed> $root
     *
     * @return array<string, mixed>|null
     */
    private static function resolve(string $ref, array $root): ?array
    {
        if (!str_starts_with($ref, '#/')) {
            return null;
        }
        $node = $root;
        foreach (explode('/', substr($ref, 2)) as $step) {
            $step = str_replace(['~1', '~0'], ['/', '~'], $step);
            if (!array_key_exists($step, $node)) {
                return null;
            }
            if (!is_array($node[$step])) {
                return null;
            }
            /** @var array<string, mixed> $child */
            $child = $node[$step];
            $node = $child;
        }

        return $node;
    }

    private static function describe(mixed $value): string
    {
        if (is_string($value)) {
            return sprintf('the string "%s"', $value);
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if ($value === null) {
            return 'null';
        }
        if (is_int($value) || is_float($value)) {
            return sprintf('the number %s', (string)$value);
        }
        if (is_array($value)) {
            return array_is_list($value) ? 'an array' : 'an object';
        }

        return get_debug_type($value);
    }
}
