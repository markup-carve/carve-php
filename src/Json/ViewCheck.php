<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Json;

use Closure;
use MarkupCarve\Carve\Attrs;
use MarkupCarve\Carve\Generated\Tables;
use MarkupCarve\Carve\Node;
use stdClass;

/**
 * A shortcut for `TreeReader::checkTreeOf()` on a tree of nodes: the schema checks compiled into closures that read
 * the nodes as `TreeEncoder` would write them, and the tree checks of `TreeReader::checkTree()` beside them.
 *
 * `passes()` is true only where the full check passes. It answers false, and the caller runs the full check, for
 * every tree it does not mirror exactly: a float, invalid UTF-8, an object other than a node or attributes, a node
 * type with checks of its own (tables, includes, extensions, autolinks, smart punctuation, abbreviation expansions),
 * annotation ids, or nesting near the JSON depth limit. The schema closures follow `SchemaValidator` keyword for
 * keyword on the keywords `CompiledSchema` reads.
 */
final class ViewCheck
{
    /**
     * Node types `TreeReader::typeChecks()` or the tab and extension checks look into.
     *
     * @var array<string, true>
     */
    private const OWN_CHECKS = [
        'include' => true,
        'table' => true,
        'table_head' => true,
        'table_body' => true,
        'table_foot' => true,
        'table_cell' => true,
        'autolink' => true,
        'smart_punctuation' => true,
        'block_extension' => true,
        'inline_extension' => true,
    ];

    /**
     * @var array<int, \Closure(mixed, self): bool>
     */
    private static array $compiled = [];

    /**
     * @var array<string, \stdClass|bool>
     */
    private static array $refs = [];

    private static ?stdClass $root = null;

    /**
     * The fields of each node as `TreeEncoder` writes them, by object id, for one check.
     *
     * @var array<int, array<string, mixed>>
     */
    private array $fields = [];

    /**
     * @param \MarkupCarve\Carve\Node $tree
     * @param array<string, mixed> $options The options of `TreeReader::check()`.
     *
     * @return bool
     */
    public static function passes(Node $tree, array $options): bool
    {
        if (array_key_exists('annotations', $options) || $tree->type !== 'document') {
            return false;
        }
        $check = new self();
        try {
            $fields = $check->fieldsOf($tree);
            if (array_key_exists('document', $fields)) {
                return false;
            }
            $declared = self::declared($fields['extensions'] ?? null, $options);
            if ($declared === null || !$check->treeChecks($tree, $declared)) {
                return false;
            }
            $schema = TreeReader::schema();

            return self::compile($schema)($tree, $check);
        } catch (Unmirrored) {
            return false;
        }
    }

    /**
     * `TreeReader::declarations()` of a root without an envelope, or null where it throws or reads values the schema
     * has not checked yet.
     *
     * @param mixed $extensions
     * @param array<string, mixed> $options
     *
     * @return array<string, true>|null
     */
    private static function declared(mixed $extensions, array $options): ?array
    {
        $names = [];
        $implementations = $options['extensions'] ?? [];
        foreach (is_array($extensions) ? $extensions : [] as $item) {
            if (!$item instanceof stdClass || !is_string($item->name ?? null) || !is_bool($item->required ?? null)) {
                return null;
            }
            if (isset($names[$item->name])) {
                return null;
            }
            $names[$item->name] = true;
            $implemented = is_array($implementations) ? ($implementations[$item->name] ?? null) : null;
            if ($item->required && $implemented !== ($item->version ?? null)) {
                return null;
            }
        }

        return $extensions === null || is_array($extensions) ? $names : null;
    }

    /**
     * The walk of `TreeReader::checkTree()`: attribute keys, data and props, and the depth counts.
     *
     * @param \MarkupCarve\Carve\Node $tree
     * @param array<string, true> $declared
     *
     * @return bool
     */
    private function treeChecks(Node $tree, array $declared): bool
    {
        // Each entry: the node, its block and inline counts, and the JSON depth of its object.
        $pending = [[$tree, 0, 0, 1]];
        while ($pending !== []) {
            [$node, $blocks, $inlines, $depth] = array_pop($pending);
            $type = $node->type;
            if (isset(self::OWN_CHECKS[$type])) {
                return false;
            }
            $fields = $this->fieldsOf($node);
            if (!self::nodeChecks($type, $fields, $declared)) {
                return false;
            }
            if (isset(TreeReader::INLINE[$type])) {
                $inlines += isset($fields['children']) ? 1 : 0;
            } else {
                $inlines = 0;
                $blocks += isset(TreeReader::CONTAINERS[$type]) ? 1 : 0;
            }
            if ($blocks > Tables::BLOCK_DEPTH || $inlines > Tables::INLINE_DEPTH) {
                return false;
            }
            foreach ($fields as $key => $value) {
                if (!is_array($value) && !is_object($value)) {
                    continue;
                }
                $found = self::nodesOfField($key, $value, $depth);
                if ($found === null) {
                    return false;
                }
                foreach ($found as [$child, $childDepth]) {
                    $pending[] = [$child, $blocks, $inlines, $childDepth];
                }
            }
        }

        return true;
    }

    /**
     * The nodes `TreeReader::pushNodes()` takes from one field, last first, or null where the walk cannot follow.
     *
     * @param string $key
     * @param mixed $value
     * @param int $depth The JSON depth of the node object.
     *
     * @return array<array{\MarkupCarve\Carve\Node, int}>|null
     */
    private static function nodesOfField(string $key, mixed $value, int $depth): ?array
    {
        if (isset(TreeReader::OPAQUE_FIELDS[$key])) {
            return self::shallow($value, $depth + 1) ? [] : null;
        }
        if ($value instanceof Node) {
            return [[$value, $depth + 1]];
        }
        if (!is_array($value) && !$value instanceof stdClass) {
            return [];
        }
        if ($key === 'children' && is_array($value) && $depth + 2 < TreeReader::JSON_DEPTH - 1) {
            $out = [];
            for ($i = count($value) - 1; $i >= 0; $i--) {
                $child = $value[$i] ?? null;
                if ($child instanceof Node) {
                    $out[] = [$child, $depth + 2];
                } elseif (is_array($child) || is_object($child)) {
                    return null;
                }
            }

            return $out;
        }
        $found = [];

        return self::nodesIn($value, $depth + 1, $found) ? array_reverse($found) : null;
    }

    /**
     * `checkNode()` without throwing: unique attribute keys, declared data keys, no node in data or props.
     *
     * @param string $type
     * @param array<string, mixed> $fields
     * @param array<string, true> $declared
     *
     * @return bool
     */
    private static function nodeChecks(string $type, array $fields, array $declared): bool
    {
        if (!isset($fields['attrs']) && !isset($fields['data']) && !isset($fields['props'])) {
            return true;
        }
        $attrs = $fields['attrs'] ?? null;
        $seen = [];
        foreach ($attrs instanceof stdClass && is_array($attrs->keyValues ?? null) ? $attrs->keyValues : [] as $pair) {
            if (!is_array($pair) || !is_string($pair[0] ?? null)) {
                return false;
            }
            $key = strtolower($pair[0]);
            if ($type === 'span' && $pair[0] === $key && in_array($key, ['abbr', 'time', 'kbd'], true)) {
                $key = 'semantic:' . $key;
            }
            if (isset($seen[$key])) {
                return false;
            }
            $seen[$key] = true;
        }
        if (array_key_exists('data', $fields)) {
            if (!$fields['data'] instanceof stdClass) {
                return false;
            }
            foreach (get_object_vars($fields['data']) as $key => $value) {
                if (!isset($declared[$key]) || $key === 'carve.annotation' || !self::opaque($value)) {
                    return false;
                }
            }
        }

        return !array_key_exists('props', $fields) || self::opaque($fields['props']);
    }

    /**
     * `TreeReader::opaque()` without throwing: no object with a string `type` inside.
     */
    private static function opaque(mixed $value): bool
    {
        if ($value instanceof Node) {
            return false;
        }
        if ($value instanceof stdClass) {
            if (isset($value->type) && is_string($value->type)) {
                return false;
            }
            $value = get_object_vars($value);
        }
        foreach (is_array($value) ? $value : [] as $item) {
            if (!self::opaque($item)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The nodes `TreeReader::pushNodes()` finds in a field, each with its JSON depth; false near the depth limit or
     * where an object holds a string `type` without being a node.
     *
     * @param mixed $value
     * @param int $depth
     * @param array<array{\MarkupCarve\Carve\Node, int}> $found
     *
     * @return bool
     */
    private static function nodesIn(mixed $value, int $depth, array &$found): bool
    {
        if ($depth >= TreeReader::JSON_DEPTH - 1) {
            return false;
        }
        if ($value instanceof Node) {
            $found[] = [$value, $depth];

            return true;
        }
        if ($value instanceof stdClass) {
            if (isset($value->type) && is_string($value->type)) {
                return false;
            }
            $value = get_object_vars($value);
        }
        foreach (is_array($value) ? $value : [] as $item) {
            if ((is_array($item) || is_object($item)) && !self::nodesIn($item, $depth + 1, $found)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether a value stays below the JSON depth limit.
     */
    private static function shallow(mixed $value, int $depth): bool
    {
        if (!is_array($value) && !is_object($value)) {
            return true;
        }
        if ($depth >= TreeReader::JSON_DEPTH - 1) {
            return false;
        }
        foreach ($value instanceof stdClass ? get_object_vars($value) : (array)$value as $item) {
            if (!self::shallow($item, $depth + 1)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The fields `TreeEncoder::node()` writes for a node without positions, with nested nodes left as nodes.
     *
     * @param \MarkupCarve\Carve\Node $node
     *
     * @throws \MarkupCarve\Carve\Json\Unmirrored
     *
     * @return array<string, mixed>
     */
    private function fieldsOf(Node $node): array
    {
        $id = spl_object_id($node);
        if (isset($this->fields[$id])) {
            return $this->fields[$id];
        }
        if ($node->type === 'abbreviation' && array_key_exists('expansion', $node->f)) {
            throw new Unmirrored();
        }
        $out = ['type' => self::plain($node->type)];
        foreach ($node->f as $key => $value) {
            if ($value === null || $value instanceof Attrs && $value->id === null && $value->classes === [] && $value->pairs === []) {
                continue;
            }
            if (isset(TreeEncoder::OMIT_EMPTY[$key]) && ($value === [] || $value instanceof stdClass && get_object_vars($value) === [])) {
                continue;
            }
            $out[$key !== '' && ctype_alpha($key[0]) && mb_check_encoding($key, 'UTF-8') ? $key : self::key($key)]
                = is_int($value) || is_bool($value) || $value instanceof Node ? $value : self::plain($value);
        }
        if ($node->children !== null) {
            $out['children'] = self::plain($node->children);
        }
        // `TreeDecoder::position()` is null for a node with neither.
        if ($node->treePos !== '' || isset($node->layout['pos'])) {
            $pos = TreeDecoder::position($node);
            if ($pos !== null) {
                $out['pos'] = self::plain($pos);
            }
        }

        return $this->fields[$id] = $out;
    }

    /**
     * A value as `json_decode` reads its canonical JSON back, nodes kept as nodes.
     *
     * @throws \MarkupCarve\Carve\Json\Unmirrored
     */
    private static function plain(mixed $value): mixed
    {
        if (is_string($value)) {
            if (!mb_check_encoding($value, 'UTF-8')) {
                throw new Unmirrored();
            }

            return $value;
        }
        if ($value === null || is_int($value) || is_bool($value) || $value instanceof Node) {
            return $value;
        }
        if ($value instanceof Attrs) {
            if ($value->id === null && $value->classes === [] && $value->pairs === []) {
                return [];
            }
            $out = new stdClass();
            if ($value->id !== null) {
                $out->id = self::plain($value->id);
            }
            if ($value->classes !== []) {
                $out->classes = self::plain($value->classes);
            }
            if ($value->pairs !== []) {
                $out->keyValues = self::plain($value->pairs);
            }

            return $out;
        }
        if (!is_array($value)) {
            throw new Unmirrored();
        }
        if (array_is_list($value)) {
            foreach ($value as $i => $item) {
                if (!$item instanceof Node) {
                    $value[$i] = self::plain($item);
                }
            }

            return $value;
        }
        $out = new stdClass();
        foreach ($value as $key => $item) {
            $out->{self::key($key)} = self::plain($item);
        }

        return $out;
    }

    /**
     * @throws \MarkupCarve\Carve\Json\Unmirrored
     */
    private static function key(string|int $key): string
    {
        $key = (string)$key;
        if ($key === '' || str_starts_with($key, "\0") || !mb_check_encoding($key, 'UTF-8')) {
            throw new Unmirrored();
        }

        return $key;
    }

    /**
     * The fields of a value the schema reads as an object.
     *
     * @return array<string, mixed>
     */
    private function objectFields(Node|stdClass $value): array
    {
        return $value instanceof Node ? $this->fieldsOf($value) : get_object_vars($value);
    }

    /**
     * @return \Closure(mixed, self): bool
     */
    private static function compile(stdClass|bool $schema): Closure
    {
        if (is_bool($schema)) {
            return static fn (mixed $value, self $check): bool => $schema;
        }
        $id = spl_object_id($schema);
        if (isset(self::$compiled[$id])) {
            return self::$compiled[$id];
        }
        // A recursive schema reaches itself before its closure exists; the stand-in finds it once it does.
        self::$compiled[$id] = static fn (mixed $value, self $check): bool => self::$compiled[$id]($value, $check);
        $built = self::build(new CompiledSchema($schema));
        self::$compiled[$id] = $built;

        return $built;
    }

    /**
     * `SchemaValidator::run()` for one schema.
     *
     * @return \Closure(mixed, self): bool
     */
    private static function build(CompiledSchema $c): Closure
    {
        if ($c->kind === CompiledSchema::CONST_ONLY) {
            $const = $c->const;

            return static fn (mixed $value, self $check): bool => self::same($value, $const);
        }
        if ($c->kind === CompiledSchema::TYPE_ONLY) {
            return self::typeCheck($c->type);
        }
        $checks = [...self::valueChecks($c), ...self::combinators($c)];
        $string = self::stringRules($c);
        $number = self::numberRules($c);
        $onlyObject = $c->ref === null && $c->type === 'object' && count($checks) === 1;
        if ($onlyObject && $string === null && $number === null && !$c->arrayRules && $c->objectRules) {
            return self::objectRules($c, true);
        }
        $object = $c->objectRules ? self::objectRules($c) : null;
        $array = $c->arrayRules ? self::arrayRules($c) : null;
        if ($string === null && $number === null && $array === null && $object !== null && count($checks) <= 1) {
            return self::objectSchema($c, $checks[0] ?? null, $object);
        }

        return static function (mixed $value, self $check) use ($checks, $string, $number, $object, $array): bool {
            foreach ($checks as $one) {
                if (!$one($value, $check)) {
                    return false;
                }
            }
            if (is_string($value)) {
                return $string === null || $string($value);
            }
            if (is_int($value)) {
                return $number === null || $number($value);
            }
            if ($value instanceof Node || $value instanceof stdClass) {
                return $object === null || $object($value, $check);
            }

            return !is_array($value) || $array === null || $array($value, $check);
        };
    }

    /**
     * A schema whose only rules beside one check are object rules, as a node definition is.
     *
     * @param \MarkupCarve\Carve\Json\CompiledSchema $c
     * @param (\Closure(mixed, self): bool)|null $only
     * @param \Closure(\MarkupCarve\Carve\Node|\stdClass, self): bool $object
     *
     * @return \Closure(mixed, self): bool
     */
    private static function objectSchema(CompiledSchema $c, ?Closure $only, Closure $object): Closure
    {
        if ($only === null) {
            return static fn (mixed $value, self $check): bool => !($value instanceof Node || $value instanceof stdClass)
                || $object($value, $check);
        }
        if ($c->ref === null && $c->type === 'object') {
            return static fn (mixed $value, self $check): bool => ($value instanceof Node || $value instanceof stdClass)
                && $object($value, $check);
        }

        return static fn (mixed $value, self $check): bool => $only($value, $check)
            && (!($value instanceof Node || $value instanceof stdClass) || $object($value, $check));
    }

    /**
     * The `$ref`, `type`, `const` and `enum` checks of a schema.
     *
     * @return array<\Closure(mixed, self): bool>
     */
    private static function valueChecks(CompiledSchema $c): array
    {
        $checks = [];
        if ($c->ref !== null) {
            $checks[] = self::compile(self::resolve($c->ref));
        }
        if ($c->type !== null) {
            $checks[] = self::typeCheck($c->type);
        }
        if ($c->hasConst) {
            $const = $c->const;
            $checks[] = static fn (mixed $value, self $check): bool => self::same($value, $const);
        }
        if ($c->enum !== null) {
            $enum = $c->enum;
            $checks[] = static function (mixed $value, self $check) use ($enum): bool {
                foreach ($enum as $candidate) {
                    if (self::same($value, $candidate)) {
                        return true;
                    }
                }

                return false;
            };
        }

        return $checks;
    }

    /**
     * @return array<\Closure(mixed, self): bool>
     */
    private static function combinators(CompiledSchema $c): array
    {
        $checks = [];
        foreach ($c->allOf as $sub) {
            $checks[] = self::compile($sub);
        }
        if ($c->anyOf !== null) {
            $checks[] = self::branches($c->anyOf, true);
        }
        if ($c->oneOf !== null) {
            $checks[] = self::branches($c->oneOf, false);
        }
        if ($c->not !== null) {
            $not = self::compile($c->not);
            $checks[] = static fn (mixed $value, self $check): bool => !$not($value, $check);
        }
        if ($c->hasIf) {
            $if = self::compile($c->if);
            $then = self::compile($c->then);
            $else = self::compile($c->else);
            $checks[] = static fn (mixed $value, self $check): bool => $if($value, $check) ? $then($value, $check) : $else($value, $check);
        }

        return $checks;
    }

    /**
     * `SchemaValidator::count()`: a branch list that dispatches on the node type runs only the branch of that type.
     *
     * @param array<\stdClass|bool> $schemas
     * @param bool $any
     *
     * @return \Closure(mixed, self): bool
     */
    private static function branches(array $schemas, bool $any): Closure
    {
        $byType = self::dispatch($schemas);
        if ($byType !== null && $byType !== []) {
            $targets = [];
            foreach ($byType as $type => $sub) {
                $targets[$type] = self::compile(self::resolve((string)$sub->{'$ref'}));
            }

            return static function (mixed $value, self $check) use ($targets): bool {
                $type = $value instanceof Node ? $value->type
                    : ($value instanceof stdClass && isset($value->type) && is_string($value->type) ? $value->type : null);
                $target = $type === null ? null : ($targets[$type] ?? null);

                return $target !== null && $target($value, $check);
            };
        }
        $compiled = array_map(self::compile(...), $schemas);

        return static function (mixed $value, self $check) use ($compiled, $any): bool {
            $n = 0;
            foreach ($compiled as $one) {
                if ($one($value, $check)) {
                    $n++;
                    if ($any) {
                        return true;
                    }
                }
            }

            return $n === 1;
        };
    }

    /**
     * The branch of each node type when an item schema is nothing but a type-dispatched `oneOf` or `anyOf`.
     *
     * @return array<string, \Closure(mixed, self): bool>|null
     */
    private static function itemTargets(CompiledSchema $c): ?array
    {
        $only = $c->ref === null && $c->type === null && !$c->hasConst && $c->enum === null && $c->allOf === []
            && $c->not === null && !$c->hasIf && !$c->objectRules && !$c->arrayRules && $c->minLength === null
            && $c->pattern === null && $c->minimum === null && $c->maximum === null && $c->exclusiveMinimum === null;
        $list = $c->anyOf === null ? $c->oneOf : ($c->oneOf === null ? $c->anyOf : null);
        if (!$only || $list === null) {
            return null;
        }
        $byType = self::dispatch($list);
        if ($byType === null || $byType === []) {
            return null;
        }
        $targets = [];
        foreach ($byType as $type => $sub) {
            $targets[$type] = self::compile(self::resolve((string)$sub->{'$ref'}));
        }

        return $targets;
    }

    /**
     * `SchemaValidator::dispatch()`.
     *
     * @param array<\stdClass|bool> $schemas
     *
     * @return array<string, \stdClass>|null
     */
    private static function dispatch(array $schemas): ?array
    {
        $byType = [];
        foreach ($schemas as $sub) {
            $target = $sub instanceof stdClass && array_keys(get_object_vars($sub)) === ['$ref']
                ? self::resolve((string)$sub->{'$ref'}) : null;
            $const = $target instanceof stdClass && ($target->type ?? null) === 'object'
                && in_array('type', (array)($target->required ?? []), true) ? ($target->properties->type->const ?? null) : null;
            if (!is_string($const) || isset($byType[$const])) {
                return null;
            }
            $byType[$const] = $sub;
        }

        return $byType;
    }

    /**
     * @return \Closure(string): bool|null
     */
    private static function stringRules(CompiledSchema $c): ?Closure
    {
        if ($c->minLength === null && $c->pattern === null) {
            return null;
        }
        $minLength = $c->minLength;
        $regex = $c->pattern === null ? null : $c->regex;

        return static fn (string $value): bool => ($minLength === null || mb_strlen($value) >= $minLength)
            && ($regex === null || preg_match($regex, $value));
    }

    /**
     * @return \Closure(int): bool|null
     */
    private static function numberRules(CompiledSchema $c): ?Closure
    {
        if ($c->minimum === null && $c->maximum === null && $c->exclusiveMinimum === null) {
            return null;
        }
        $minimum = $c->minimum;
        $maximum = $c->maximum;
        $exclusive = $c->exclusiveMinimum;

        return static fn (int $value): bool => ($minimum === null || $value >= $minimum)
            && ($maximum === null || $value <= $maximum)
            && ($exclusive === null || $value > $exclusive);
    }

    /**
     * The members of an object value, or null for any other value.
     *
     * @return array<mixed>|null
     */
    private function propsOf(mixed $value): ?array
    {
        if ($value instanceof Node) {
            return $this->fieldsOf($value);
        }

        return $value instanceof stdClass ? get_object_vars($value) : null;
    }

    /**
     * `SchemaValidator::objectRules()` and `member()`; `$typed` folds in the `object` type check.
     *
     * @return ($typed is true ? \Closure(mixed, self): bool : \Closure(\MarkupCarve\Carve\Node|\stdClass, self): bool)
     */
    private static function objectRules(CompiledSchema $c, bool $typed = false): Closure
    {
        $properties = [];
        foreach ($c->propertyChecks as $key => $sub) {
            $properties[$key] = is_bool($sub) ? static fn (mixed $value, self $check): bool => $sub : self::build($sub);
        }
        $additional = $c->additional === null ? null : self::compile($c->additional);
        if ($c->propertyNames === null && $c->patternProperties === [] && $c->dependentRequired === [] && $c->maxProperties === null) {
            return self::plainObject($c, $properties, $additional, $typed);
        }
        $full = self::fullObject($c, $properties, $additional);

        return $typed ? static fn (mixed $value, self $check): bool => ($value instanceof Node || $value instanceof stdClass)
            && $full($value, $check) : $full;
    }

    /**
     * Object rules with `required`, `minProperties`, `properties` and `additionalProperties` only. A one-keyword
     * property schema is checked in place.
     *
     * @param \MarkupCarve\Carve\Json\CompiledSchema $c
     * @param array<string, \Closure(mixed, self): bool> $properties
     * @param (\Closure(mixed, self): bool)|null $additional
     * @param bool $typed
     *
     * @return \Closure(mixed, self): bool
     */
    private static function plainObject(CompiledSchema $c, array $properties, ?Closure $additional, bool $typed): Closure
    {
        $required = $c->required;
        $minProperties = $c->minProperties;
        $simple = [];
        foreach ($c->propertyChecks as $key => $sub) {
            $one = $sub instanceof CompiledSchema ? self::inPlace($sub) : null;
            if ($one !== null) {
                $simple[$key] = $one;
            }
        }

        return static function (
            mixed $value,
            self $check,
        ) use (
            $required,
            $minProperties,
            $properties,
            $simple,
            $additional,
            $typed,
        ): bool {
            $props = $check->propsOf($value);
            if ($props === null) {
                return !$typed;
            }
            foreach ($required as $key) {
                if (!array_key_exists($key, $props)) {
                    return false;
                }
            }
            if ($minProperties !== null && count($props) < $minProperties) {
                return false;
            }
            foreach ($props as $key => $item) {
                $key = (string)$key;
                $one = $simple[$key] ?? null;
                if ($one !== null) {
                    if (!self::inPlaceOk($one, $item)) {
                        return false;
                    }

                    continue;
                }
                $sub = $properties[$key] ?? null;
                if ($sub !== null ? !$sub($item, $check) : $additional !== null && !$additional($item, $check)) {
                    return false;
                }
            }

            return true;
        };
    }

    /**
     * `[1, const]` for a scalar `const` schema, `[2, type]` for a string, boolean or array `type` schema.
     *
     * @return array{int, mixed}|null
     */
    private static function inPlace(CompiledSchema $sub): ?array
    {
        $const = $sub->const;
        if ($sub->kind === CompiledSchema::CONST_ONLY && (is_string($const) || is_int($const) || is_bool($const) || $const === null)) {
            return [1, $const];
        }
        if ($sub->kind === CompiledSchema::TYPE_ONLY && in_array($sub->type, ['string', 'boolean', 'array'], true)) {
            return [2, $sub->type];
        }

        return null;
    }

    /**
     * @param array{int, mixed} $one
     * @param mixed $item
     *
     * @return bool
     */
    private static function inPlaceOk(array $one, mixed $item): bool
    {
        if ($one[0] === 1) {
            return self::same($item, $one[1]);
        }

        return match ($one[1]) {
            'string' => is_string($item),
            'boolean' => is_bool($item),
            default => is_array($item),
        };
    }

    /**
     * @param \MarkupCarve\Carve\Json\CompiledSchema $c
     * @param array<string, \Closure(mixed, self): bool> $properties
     * @param (\Closure(mixed, self): bool)|null $additional
     *
     * @return \Closure(\MarkupCarve\Carve\Node|\stdClass, self): bool
     */
    private static function fullObject(CompiledSchema $c, array $properties, ?Closure $additional): Closure
    {
        $required = $c->required;
        $minProperties = $c->minProperties;
        $maxProperties = $c->maxProperties;
        $dependent = $c->dependentRequired;
        $names = $c->propertyNames === null ? null : self::compile($c->propertyNames);
        $patterns = [];
        foreach ($c->patternProperties as [$regex, $sub]) {
            $patterns[] = [$regex, self::compile($sub)];
        }

        return static function (
            Node|stdClass $value,
            self $check,
        ) use (
            $required,
            $minProperties,
            $maxProperties,
            $dependent,
            $names,
            $properties,
            $patterns,
            $additional,
        ): bool {
            $props = $check->objectFields($value);
            $n = count($props);
            if (($minProperties !== null && $n < $minProperties) || ($maxProperties !== null && $n > $maxProperties)) {
                return false;
            }
            foreach ($required as $key) {
                if (!array_key_exists($key, $props)) {
                    return false;
                }
            }
            foreach ($dependent as $key => $needs) {
                foreach (array_key_exists($key, $props) ? $needs : [] as $need) {
                    if (!array_key_exists($need, $props)) {
                        return false;
                    }
                }
            }
            foreach ($props as $key => $item) {
                if (!self::member((string)$key, $item, $check, $names, $properties, $patterns, $additional)) {
                    return false;
                }
            }

            return true;
        };
    }

    /**
     * `SchemaValidator::member()`.
     *
     * @param string $key
     * @param mixed $item
     * @param self $check
     * @param (\Closure(mixed, self): bool)|null $names
     * @param array<string, \Closure(mixed, self): bool> $properties
     * @param array<array{string, \Closure(mixed, self): bool}> $patterns
     * @param (\Closure(mixed, self): bool)|null $additional
     *
     * @return bool
     */
    private static function member(
        string $key,
        mixed $item,
        self $check,
        ?Closure $names,
        array $properties,
        array $patterns,
        ?Closure $additional,
    ): bool {
        if ($names !== null && !$names($key, $check)) {
            return false;
        }
        $seen = false;
        $sub = $properties[$key] ?? null;
        if ($sub !== null) {
            $seen = true;
            if (!$sub($item, $check)) {
                return false;
            }
        }
        foreach ($patterns as [$regex, $pattern]) {
            if (preg_match($regex, $key)) {
                $seen = true;
                if (!$pattern($item, $check)) {
                    return false;
                }
            }
        }

        return $seen || $additional === null || $additional($item, $check);
    }

    /**
     * `SchemaValidator::arrayRules()`.
     *
     * @return \Closure(array<mixed>, self): bool
     */
    private static function arrayRules(CompiledSchema $c): Closure
    {
        $minItems = $c->minItems;
        $maxItems = $c->maxItems;
        $prefix = [];
        foreach ($c->prefixChecks as $i => $sub) {
            $prefix[$i] = is_bool($sub) ? static fn (mixed $value, self $check): bool => $sub : self::build($sub);
        }
        $items = $c->itemsCheck;
        $targets = $items instanceof CompiledSchema ? self::itemTargets($items) : null;
        if ($targets !== null && $prefix === [] && !$c->uniqueItems) {
            return self::dispatchedItems($minItems, $maxItems, $targets);
        }
        $itemsCheck = is_bool($items) ? static fn (mixed $value, self $check): bool => $items : self::build($items);
        $unique = $c->uniqueItems;

        return static function (array $value, self $check) use ($minItems, $maxItems, $prefix, $itemsCheck, $unique): bool {
            $n = count($value);
            if (($minItems !== null && $n < $minItems) || ($maxItems !== null && $n > $maxItems)) {
                return false;
            }
            foreach ($value as $i => $item) {
                if (!($prefix[$i] ?? $itemsCheck)($item, $check)) {
                    return false;
                }
            }

            return !$unique || self::uniqueScalars($value);
        };
    }

    /**
     * Items whose schema is a `oneOf` or `anyOf` dispatched on the node type.
     *
     * @param float|int|null $minItems
     * @param float|int|null $maxItems
     * @param array<string, \Closure(mixed, self): bool> $targets
     *
     * @return \Closure(array<mixed>, self): bool
     */
    private static function dispatchedItems(float|int|null $minItems, float|int|null $maxItems, array $targets): Closure
    {
        return static function (array $value, self $check) use ($minItems, $maxItems, $targets): bool {
            $n = count($value);
            if (($minItems !== null && $n < $minItems) || ($maxItems !== null && $n > $maxItems)) {
                return false;
            }
            foreach ($value as $item) {
                $type = $item instanceof Node ? $item->type
                    : ($item instanceof stdClass && isset($item->type) && is_string($item->type) ? $item->type : null);
                $target = $type === null ? null : ($targets[$type] ?? null);
                if ($target === null || !$target($item, $check)) {
                    return false;
                }
            }

            return true;
        };
    }

    /**
     * `uniqueItems` as `SchemaValidator` compares them, for a list of scalars.
     *
     * @param array<mixed> $value
     *
     * @throws \MarkupCarve\Carve\Json\Unmirrored
     *
     * @return bool
     */
    private static function uniqueScalars(array $value): bool
    {
        foreach ($value as $item) {
            if (!is_string($item) && !is_int($item) && !is_bool($item) && $item !== null) {
                throw new Unmirrored();
            }
        }

        return count(array_unique(array_map('serialize', $value))) === count($value);
    }

    /**
     * `SchemaValidator::same()` for the values a mirrored tree holds.
     *
     * @throws \MarkupCarve\Carve\Json\Unmirrored
     */
    private static function same(mixed $value, mixed $const): bool
    {
        if (
            (is_string($value) || is_int($value) || is_bool($value) || $value === null)
            && (is_string($const) || is_int($const) || is_bool($const) || $const === null)
        ) {
            return $value === $const;
        }

        throw new Unmirrored();
    }

    /**
     * @return \Closure(mixed, self): bool
     */
    private static function typeCheck(mixed $type): Closure
    {
        return match ($type) {
            'object' => static fn (mixed $value, self $check): bool => $value instanceof Node || $value instanceof stdClass,
            'array' => static fn (mixed $value, self $check): bool => is_array($value),
            'string' => static fn (mixed $value, self $check): bool => is_string($value),
            'integer', 'number' => static fn (mixed $value, self $check): bool => is_int($value),
            'boolean' => static fn (mixed $value, self $check): bool => is_bool($value),
            default => static fn (mixed $value, self $check): bool => self::typeOk($value, $type),
        };
    }

    private static function typeOk(mixed $value, mixed $type): bool
    {
        foreach ((array)$type as $t) {
            $ok = match ($t) {
                'object' => $value instanceof Node || $value instanceof stdClass,
                'array' => is_array($value),
                'string' => is_string($value),
                'integer', 'number' => is_int($value),
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
     * `SchemaValidator::resolve()` against the AST schema.
     */
    private static function resolve(string $ref): stdClass|bool
    {
        if (isset(self::$refs[$ref])) {
            return self::$refs[$ref];
        }
        $node = self::$root ??= TreeReader::schema();
        foreach (explode('/', substr($ref, 2)) as $part) {
            $node = $node->{str_replace(['~1', '~0'], ['/', '~'], $part)};
        }

        return self::$refs[$ref] = $node;
    }
}
