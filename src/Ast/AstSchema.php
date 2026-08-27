<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Ast;

use JsonException;
use RuntimeException;
use function array_is_list;
use function array_key_exists;
use function dirname;
use function file_get_contents;
use function implode;
use function in_array;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_string;
use function json_decode;
use function sort;
use function sprintf;
use function str_starts_with;
use function substr;
use const JSON_THROW_ON_ERROR;

/**
 * PART 12 §12(d): AN INGEST VALIDATES THE WHOLE PAYLOAD AGAINST THE AST SCHEMA
 * (markup-carve/carve#881).
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
        'exclusiveMinimum',
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
     * @param array<mixed> $payload
     * @param list<string> $exemptTypes
     *
     * @return string|null
     */
    public static function firstViolation(array $payload, array $exemptTypes = []): ?string
    {
        // `check()` recurses, and this method is public: a caller reaching it
        // without going through `AstCodec::decode()` gets no other bound. Too
        // deep is reported as the violation it is rather than crashing the
        // process, and it is asked first because every other answer requires
        // descending into the payload.
        if (!PayloadDepth::within($payload, AstCodec::MAX_JSON_DEPTH)) {
            return sprintf(
                '$: payload nests deeper than %d levels, the bound the AST reader applies.',
                AstCodec::MAX_JSON_DEPTH,
            );
        }

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
     * @return string|null
     */
    private static function check(mixed $value, array $schema, array $root, string $path, array $exempt): ?string
    {
        if ($exempt !== [] && is_array($value) && is_string($value['type'] ?? null) && in_array($value['type'], $exempt, true)) {
            return null;
        }

        // EVERY REF RESOLVES, asserted by `testEveryRefInTheSchemaResolves`.
        // A ref that did not would otherwise validate NOTHING at that node,
        // which is the silent-acceptance failure this clause exists to end.
        if (isset($schema['$ref']) && is_string($schema['$ref'])) {
            return self::check($value, self::resolve($schema['$ref'], $root), $root, $path, $exempt);
        }

        foreach (
            [
                'type' => 'checkType',
                'const' => 'checkConst',
                'enum' => 'checkEnum',
                'minimum' => 'checkMinimum',
                'exclusiveMinimum' => 'checkExclusiveMinimum',
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
        // EVERY BRANCH IS AN OBJECT, asserted by
        // `testEveryCompositionBranchIsASchema` rather than defended against
        // here: a `continue` for a branch that cannot occur silently skips a
        // constraint if it ever does.
        if (isset($schema['allOf']) && is_array($schema['allOf'])) {
            foreach ($schema['allOf'] as $branch) {
                /** @var array<mixed> $branch */
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
                /** @var array<mixed> $branch */
                $failure = self::check($value, $branch, $root, $path, $exempt);
                if ($failure === null) {
                    $matched++;

                    continue;
                }
                $first ??= $failure;
            }
            // A branch list is never EMPTY here - same assertion - so a failure
            // was recorded whenever none matched.
            if ($matched === 0 && $first !== null) {
                return self::typedNodeUnionMismatch($value, $schema[$keyword], $root, $path) ?? $first;
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
     * Why the value's own TYPE is not admitted here, when that is the reason
     * no branch matched. Null whenever it is not, so the caller keeps the
     * branch failure it already has.
     *
     * A union of typed node definitions - `figure.target`, `blockNode`,
     * `inlineNode` - fails in two different ways, and the first branch's own
     * complaint tells only one of them. A node whose type IS admitted but which
     * is missing a field it requires wants that field named. A node of a type
     * the position never admits wants the ADMITTED SET named: reporting the
     * first branch's missing `src` for a `block_quote` at `figure.target` sends
     * a producer to add `src` to a block quote.
     *
     * Both conditions are required before a message is built: the value has to
     * identify itself as a node, and every branch has to pin a type constant.
     * Otherwise the union is something else - a union of records, a mixed one -
     * and this has nothing to say about it.
     *
     * @param mixed $value
     * @param array<mixed> $branches
     * @param array<string, mixed> $root
     * @param string $path
     *
     * @return string|null
     */
    private static function typedNodeUnionMismatch(mixed $value, array $branches, array $root, string $path): ?string
    {
        if (!is_array($value)) {
            return null;
        }
        $type = $value['type'] ?? null;
        if (!is_string($type)) {
            return null;
        }

        // THE THREE `return null`s BELOW ARE TYPE NARROWING, not guards against a
        // schema this repo ships. Both unions the published schema writes today -
        // `figure.target` and `definition_list.items` - are typed node unions, so
        // the branch shapes always resolve; the checks exist because the values
        // come out of decoded JSON as `mixed` and the function must be total for a
        // union some later schema writes differently. They are therefore not
        // reachable from any payload, which is why the tests do not cover them.
        $admitted = [];
        foreach ($branches as $branch) {
            /** @var array<mixed> $branch */
            if (isset($branch['$ref']) && is_string($branch['$ref'])) {
                $branch = self::resolve($branch['$ref'], $root);
            }
            $properties = $branch['properties'] ?? null;
            if (!is_array($properties)) {
                return null;
            }
            $declared = $properties['type'] ?? null;
            if (!is_array($declared)) {
                return null;
            }
            $constant = $declared['const'] ?? null;
            if (!is_string($constant)) {
                return null;
            }
            $admitted[] = $constant;
        }

        if (in_array($type, $admitted, true)) {
            return null;
        }
        sort($admitted);

        return sprintf('%s holds a "%s" node where the schema admits only %s', $path, $type, implode(', ', $admitted));
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
        // A SINGLE NAME, never a list. JSON Schema allows `type` to be an
        // array of names and this schema never writes one, so handling that
        // form here would be a branch no input reaches - and a branch no input
        // reaches is indistinguishable from one that is wrong. The assumption
        // is checked instead, by `testTheSchemaSpellsEveryTypeAsOneSupportedName`.
        if (!is_string($expected) || self::isOfType($value, $expected)) {
            return null;
        }

        return sprintf('%s is %s where the schema requires %s', $path, self::describe($value), $expected);
    }

    private static function isOfType(mixed $value, string $name): bool
    {
        // The names this schema uses, and no more; `default => false` is what an
        // unsupported name gets, and a test says the schema contains none.
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
        if (in_array($value, is_array($allowed) ? $allowed : [], true)) {
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
        // NO TYPE GUARD. Every bounded field in this schema also declares
        // `type: integer`, and `check()` runs `type` before `minimum` and
        // returns on its failure, so a non-numeric value never reaches here.
        // The pairing is asserted by `testEveryBoundedFieldAlsoDeclaresItsType`
        // rather than guarded against, because a guard for a case that cannot
        // arise cannot be wrong and cannot be right either.
        return $value >= $bound
            ? null
            : sprintf('%s is %s, below the schema minimum %s', $path, self::number($value), self::number($bound));
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
        // See `checkMinimum`.
        return $value <= $bound
            ? null
            : sprintf('%s is %s, above the schema maximum %s', $path, self::number($value), self::number($bound));
    }

    private static function checkExclusiveMinimum(mixed $value, mixed $bound, string $path): ?string
    {
        return $value > $bound
            ? null
            : sprintf('%s is %s, not above the schema exclusive minimum %s', $path, self::number($value), self::number($bound));
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
        // A GUARD RATHER THAN AN ASSERTION, unlike the bounds above. `required`
        // does NOT always sit beside `type: object`: it also appears on the
        // `if` half of every `allOf` dispatch, whose own schema constrains only
        // `type`. Nothing reaches here with a non-array today because the
        // enclosing `blockNode`/`inlineNode` declares the object type and
        // `check()` returns on its failure - but that is a property of where
        // the dispatch sits, not of this keyword.
        if (!is_array($value)) {
            return null;
        }
        foreach (is_array($names) ? $names : [] as $name) {
            /** @var string $name */
            if (!array_key_exists($name, $value)) {
                return sprintf('%s is missing `%s`, which the schema requires', $path, $name);
            }
        }

        return null;
    }

    /**
     * A LOCAL ref, which is the only kind this schema writes.
     *
     * Returns an empty schema - which constrains nothing - for a ref that does
     * not resolve, and `testEveryRefInTheSchemaResolves` says there is none.
     * The alternative was throwing, which turned a schema mistake into a
     * runtime failure on a payload that had done nothing wrong.
     *
     * @param string $ref
     * @param array<string, mixed> $root
     *
     * @return array<string, mixed>
     */
    public static function resolve(string $ref, array $root): array
    {
        if (!str_starts_with($ref, '#/')) {
            return [];
        }
        $node = $root;
        foreach (explode('/', substr($ref, 2)) as $step) {
            $step = str_replace(['~1', '~0'], ['/', '~'], $step);
            if (!array_key_exists($step, $node) || !is_array($node[$step])) {
                return [];
            }
            /** @var array<string, mixed> $child */
            $child = $node[$step];
            $node = $child;
        }

        return $node;
    }

    /**
     * A schema bound as text. Not `(string)` on the raw value: the bound comes
     * out of decoded JSON, so its static type is `mixed` even where the schema
     * only ever writes an integer.
     */
    private static function number(mixed $bound): string
    {
        return is_int($bound) || is_float($bound) ? (string)$bound : get_debug_type($bound);
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

        // A DECODED JSON VALUE is a string, a number, a boolean, null or an
        // array - there is no sixth case to fall through to.
        return is_array($value) && array_is_list($value) && $value !== [] ? 'an array' : 'an object';
    }
}
