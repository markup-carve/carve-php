<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Lint;

use Closure;
use Generator;
use MarkupCarve\Carve\Attrs;
use MarkupCarve\Carve\Block\LineShapes;
use MarkupCarve\Carve\Inline\InlineContext;
use MarkupCarve\Carve\Inline\InlineRunner;
use MarkupCarve\Carve\Resolve\PlainText;
use MarkupCarve\Carve\Resolve\Resolver;
use Normalizer;
use stdClass;

/**
 * @phpstan-type Rule array{
 *     id: string, over: string, verbatim: array<mixed>, types: array<mixed>|null, when: mixed,
 *     pre: array<mixed>, early: list<array{0: string, 1: string, 2: mixed}>, key: string,
 *     let?: mixed, each?: mixed,
 * } & array<string, mixed>
 */
final class DataRules
{
    /**
     * @var string The result of `valueMore` for an operator it does not take.
     */
    private const NONE = "\0none";

    /**
     * @var array<string, true>
     */
    private const BINARY_OPS = [
        'eq' => true,
        'ne' => true,
        'lt' => true,
        'le' => true,
        'gt' => true,
        'ge' => true,
        'add' => true,
        'sub' => true,
        'in' => true,
        'contains' => true,
        'starts' => true,
        'matches' => true,
        'concat' => true,
        'slice' => true,
        'extend_left' => true,
        'skip_right' => true,
    ];

    /**
     * @var array<string, mixed>|null
     */
    private static ?array $data = null;

    /**
     * @var array<string, true>|null
     */
    private static ?array $ids = null;

    /**
     * @var list<Rule>|null
     */
    private static ?array $rulesCache = null;

    /**
     * @var list<Rule>|null The rules over attribute records (C2-W-148).
     */
    private static ?array $attributeRulesCache = null;

    /**
     * @var array{node: array<string, true>, item: array<string, true>}|null
     */
    private static ?array $nodeFields = null;

    /**
     * @var array<string, array{node: array<string, true>, item: array<string, true>}>
     */
    private static array $nodeFieldsByType = [];

    /**
     * @var array<string, list<array{0: bool, 1: list<string>}>> `nodeData` plans: whether a path is the children, and its parts.
     */
    private static array $nodePlans = [];

    /**
     * @var array<string, list<list<string>>> The parts of each item path, by node type.
     */
    private static array $itemPlans = [];

    private static ?string $nodeTypesKey = null;

    /**
     * @var array<string, true>
     */
    private static array $nodeTypes = [];

    /**
     * @var array<string, array<int, mixed>> The plan of `run` for each set of active rules (`activeKey`).
     */
    private static array $plans = [];

    /**
     * One interpreter for every run, so the compiled `when` closures, which hold it, are made once per process.
     */
    private static ?self $interpreter = null;

    private static ?bool $markerOnlyWildcardInlineLookup = null;

    private static function markerOnlyWildcardInlineLookup(): bool
    {
        if (self::$markerOnlyWildcardInlineLookup !== null) {
            return self::$markerOnlyWildcardInlineLookup;
        }
        foreach (self::rules() as $rule) {
            $blocks = [];
            $inline = [];
            self::lookupTypes($rule, $blocks, $inline);
            if (isset($inline['*']) && $rule['id'] !== 'block-marker-as-text') {
                return self::$markerOnlyWildcardInlineLookup = false;
            }
        }

        return self::$markerOnlyWildcardInlineLookup = true;
    }

    /**
     * @var array<string, mixed>
     */
    private array $vars = [];

    /**
     * @var array<string, mixed> The value of each let the current rule has read, computed once per record as in carve-js.
     */
    private array $letValues = [];

    /**
     * @var array<string, array{0: int, 1: int}>
     */
    private array $groups = [];

    private int $base = 0;

    /**
     * @var array{block: list<\stdClass>, inline: list<\stdClass>, id: list<\stdClass>, line: list<\stdClass>}
     */
    private array $lookupRecords = ['block' => [], 'inline' => [], 'id' => [], 'line' => []];

    /**
     * @var array<string, array<string, list<\stdClass>>>
     */
    private array $lookupIndexes = [];

    /**
     * @var array<string, array<string, array<int>|false>> The starts of each index list, or false when the list is not
     * in start order, so a before or after lookup can bisect instead of scanning from the first record.
     */
    private array $lookupStarts = [];

    /**
     * @var array<string, \Closure(\stdClass): mixed> The compiled `when` of each rule, by rule kind and id.
     */
    private array $compiledWhen = [];

    /**
     * @var array<string, array<int|string, \Closure(\stdClass): mixed>> The compiled `when` of each report entry, by rule.
     */
    private array $compiledReport = [];

    /**
     * @var array<string, \Closure(\stdClass): mixed> The compiled key of each lookup, by site.
     */
    private array $compiledKeys = [];

    private function __construct(private Context $context)
    {
    }

    public static function enabled(): bool
    {
        return (self::data()['rules'] ?? []) !== [];
    }

    /**
     * @return array<string, true>
     */
    public static function ids(): array
    {
        if (self::$ids === null) {
            self::$ids = [];
            foreach ([...self::rules(), ...self::attributeRules()] as $rule) {
                self::$ids[$rule['id']] = true;
            }
        }

        return self::$ids;
    }

    /**
     * The ids of the data rules over line records.
     *
     * @return array<string, true>
     */
    public static function lineIds(): array
    {
        $ids = [];
        foreach (self::rules() as $rule) {
            if ($rule['over'] === 'line') {
                $ids[$rule['id']] = true;
            }
        }

        return $ids;
    }

    /**
     * @return array<string, mixed>
     */
    private static function data(): array
    {
        if (self::$data === null) {
            $file = dirname(__DIR__, 2) . '/spec/data/lint-model.json';
            $decoded = is_file($file) ? json_decode((string)file_get_contents($file), true, 512, JSON_THROW_ON_ERROR) : [];
            self::$data = is_array($decoded) ? $decoded : [];
        }

        return self::$data;
    }

    /**
     * @return list<Rule>
     */
    private static function rules(): array
    {
        if (self::$rulesCache !== null) {
            return self::$rulesCache;
        }
        $rules = [];
        foreach (self::rawRules() as $rule) {
            if (
                is_array($rule) && is_string($rule['id'] ?? null)
                && in_array($rule['over'] ?? null, ['line', 'block', 'inline'], true)
            ) {
                $rule['verbatim'] = is_array($rule['verbatim'] ?? null) ? $rule['verbatim'] : [];
                $rule['types'] = is_array($rule['types'] ?? null) ? $rule['types'] : null;
                $rule['when'] = $rule['when'] ?? null;
                $over = (string)$rule['over'];
                $rule['pre'] = self::prefilter($rule['when'], $over);
                $rule['early'] = self::earlyTerms($rule['when'], $over);
                $rule['key'] = $over . ' ' . $rule['id'];
                if ($rule['id'] === 'list-marker-below-content-column') {
                    $rule['let'] = self::portOpenListLet($rule['let'] ?? null);
                    $rule['report'] = self::portOpenListReport($rule['report'] ?? null);
                }
                $rules[] = $rule;
            }
        }

        return self::$rulesCache = $rules;
    }

    /**
     * @return list<Rule>
     */
    private static function attributeRules(): array
    {
        if (self::$attributeRulesCache !== null) {
            return self::$attributeRulesCache;
        }
        $rules = [];
        foreach (self::rawRules() as $rule) {
            if (is_array($rule) && is_string($rule['id'] ?? null) && ($rule['over'] ?? null) === 'attribute') {
                $rule['verbatim'] = [];
                $rule['types'] = is_array($rule['types'] ?? null) ? $rule['types'] : null;
                $rule['when'] = $rule['when'] ?? null;
                $rule['pre'] = [];
                $rule['early'] = [];
                $rule['key'] = 'attribute ' . $rule['id'];
                $rules[] = $rule;
            }
        }

        return self::$attributeRulesCache = $rules;
    }

    /**
     * Whether a rule over attribute records is active, so the walk keeps the nodes that carry attributes.
     */
    public static function attributesActive(Context $context): bool
    {
        foreach (self::attributeRules() as $rule) {
            if ($context->activeRule($rule['id'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * C2-W-148: the attribute rules over each attribute record whose owner has a type they name.
     */
    public static function runAttributes(Context $context): void
    {
        if ($context->attributed === [] || !str_contains($context->source, '{')) {
            return;
        }
        $rules = [];
        foreach (self::attributeRules() as $rule) {
            if ($context->activeRule($rule['id'])) {
                $rules[] = $rule;
            }
        }
        $self = self::$interpreter ??= new self($context);
        $self->context = $context;
        foreach (DataAttributes::records($context) as $record) {
            foreach ($rules as $rule) {
                if ($rule['types'] === null || in_array($record->owner->type, $rule['types'], true)) {
                    $self->apply($rule, $record);
                }
            }
        }
        unset($self->context);
    }

    /**
     * @return array<mixed>
     */
    private static function rawRules(): array
    {
        $raw = self::data()['rules'] ?? [];

        return is_array($raw) ? $raw : [];
    }

    /**
     * C2-B-055 in the merged 0.2 rule: an intervening paragraph leaves the deeper list open.
     * Keep the port idempotent so a later spec pin can contain the same rule directly.
     *
     * @param mixed $let
     *
     * @return mixed
     */
    private static function portOpenListLet(mixed $let): mixed
    {
        if (is_array($let) && is_array($let['previous'] ?? null)) {
            $previous = $let['previous'];
            if (is_array($previous['lookup'] ?? null)) {
                $lookup = $previous['lookup'];
                unset($lookup['before']);
                $previous['lookup'] = $lookup;
                $let['previous'] = $previous;
            }
        }

        return $let;
    }

    /**
     * @param mixed $reports
     *
     * @return mixed
     */
    private static function portOpenListReport(mixed $reports): mixed
    {
        if (!is_array($reports) || !is_array($reports[1] ?? null)) {
            return $reports;
        }
        $second = $reports[1];
        $when = $second['when'] ?? null;
        if (!is_array($when) || !is_array($when['all'] ?? null)) {
            return $reports;
        }
        $terms = $when['all'];
        foreach ($terms as $index => $term) {
            if (!is_array($term) || !is_array($term['eq'] ?? null)) {
                continue;
            }
            $equals = $term['eq'];
            if (is_array($equals[0] ?? null) && ($equals[0]['v'] ?? null) === 'list_prev' && ($equals[1] ?? null) === 'list') {
                $terms[$index] = ['in' => [['v' => 'list_prev'], ['list', 'paragraph']]];
            }
        }
        $when['all'] = $terms;
        $second['when'] = $when;
        $reports[1] = $second;

        return $reports;
    }

    /**
     * The terms of a `when` that compare a plain record field with a literal: they read nothing lazily and set no match
     * groups, so a record that fails one fails the rule.
     *
     * @param mixed $when
     * @param string $over
     *
     * @return list<array{0: string, 1: string, 2: mixed}> Operator, field, literal.
     */
    private static function prefilter(mixed $when, string $over): array
    {
        if (!is_array($when) || array_is_list($when)) {
            return [];
        }
        $op = array_key_first($when);
        if ($op === 'all' && is_array($when['all'])) {
            $out = [];
            foreach ($when['all'] as $term) {
                array_push($out, ...self::prefilter($term, $over));
            }

            return $out;
        }
        $args = $when[$op];
        if (!in_array($op, ['eq', 'in', 'starts', 'contains'], true) || !is_array($args) || !array_key_exists(1, $args)) {
            return [];
        }
        $left = $args[0];
        $field = is_array($left) && !array_is_list($left) && array_key_first($left) === 'f' ? $left['f'] : null;
        $literal = $args[1];
        if (
            !is_string($field) || str_contains($field, '.')
            || in_array($field, ['ancestors', 'ancestor_ids', 'node', 'layout', 'raw_ref', 'key_text'], true)
            || ($field === 'text' && $over === 'block')
            || (is_array($literal) && !array_is_list($literal))
        ) {
            return [];
        }
        if ($op === 'in') {
            return is_array($literal) || is_string($literal) ? [['in', $field, self::set($literal)]] : [];
        }
        if ($op !== 'eq' && !is_string($literal)) {
            return [];
        }

        return is_array($literal) ? [] : [[$op, $field, $literal]];
    }

    // phpcs:disable Generic.Metrics.CyclomaticComplexity.MaxExceeded

    /**
     * Unconditional terms that can reject a block before its full data record is built.
     * Terms under `any` and `not` cannot reject it on their own.
     *
     * @return list<array{0: string, 1: string, 2: mixed}>
     */
    private static function earlyTerms(mixed $when, string $over): array
    {
        if (!is_array($when) || array_is_list($when)) {
            return [];
        }
        if (is_array($when['all'] ?? null)) {
            $terms = [];
            foreach ($when['all'] as $part) {
                array_push($terms, ...self::earlyTerms($part, $over));
            }

            return $terms;
        }
        if (is_string($when['has'] ?? null) && str_starts_with($when['has'], 'node.')) {
            return [['has', substr($when['has'], 5), null]];
        }
        foreach (['eq', 'in', 'starts', 'contains', 'matches'] as $op) {
            $args = $when[$op] ?? null;
            if (!is_array($args) || !is_array($args[0] ?? null) || !is_string($args[0]['f'] ?? null)) {
                continue;
            }
            $field = $args[0]['f'];
            $literal = $args[1] ?? null;
            if (
                ($field === 'text' || ($over === 'line' && $field === 'body'))
                && is_string($literal) && in_array($op, ['eq', 'starts', 'contains', 'matches'], true)
            ) {
                return [[$op, $field, $op === 'matches' ? self::pattern($literal, is_string($args[2] ?? null) ? $args[2] : '') : $literal]];
            }
            if (
                str_starts_with($field, 'node.') && in_array($op, ['eq', 'in'], true)
                && ($op === 'in' ? (is_string($literal) || (is_array($literal) && array_is_list($literal))) : !is_array($literal))
            ) {
                return [[$op, substr($field, 5), $op === 'in' ? self::set($literal) : $literal]];
            }
        }

        return [];
    }

    /**
     * @param list<array{0: string, 1: string, 2: mixed}> $terms
     * @param array<mixed>|null $node
     * @param string|null $lineBody
     * @param string|null $lineText
     * @param int $end
     * @param int $start
     * @param string $source
     */
    private static function earlyPasses(
        array $terms,
        ?array $node,
        string $source,
        int $start,
        int $end,
        ?string $lineText = null,
        ?string $lineBody = null,
    ): bool {
        $text = null;
        foreach ($terms as [$op, $path, $literal]) {
            if ($path === 'text') {
                $value = $lineText ?? ($text ??= substr($source, $start, $end - $start));
            } elseif ($path === 'body') {
                $value = $lineBody;
            } else {
                if ($node === null) {
                    continue;
                }
                $value = $node;
                foreach (explode('.', $path) as $part) {
                    if (!is_array($value)) {
                        $value = null;

                        break;
                    }
                    $index = ctype_digit($part) || (str_starts_with($part, '-') && ctype_digit(substr($part, 1)))
                        ? (int)$part : $part;
                    if (is_int($index) && $index < 0 && array_is_list($value)) {
                        $index += count($value);
                    }
                    $value = $value[$index] ?? null;
                }
            }
            $pass = match ($op) {
                'has' => $value !== null,
                'eq' => $value === $literal,
                'in' => is_array($literal) && in_array($value, $literal, true),
                'starts' => is_string($value) && is_string($literal) && str_starts_with($value, $literal),
                'contains' => is_string($value) && is_string($literal) && str_contains($value, $literal),
                default => is_string($value) && is_string($literal) && preg_match($literal, $value) !== 0,
            };
            if (!$pass) {
                return false;
            }
        }

        return true;
    }

    // phpcs:enable Generic.Metrics.CyclomaticComplexity.MaxExceeded

    /**
     * @param array<mixed> $terms The terms of `prefilter()`.
     * @param \stdClass $record
     */
    private static function passes(array $terms, stdClass $record): bool
    {
        foreach ($terms as $term) {
            if (!is_array($term) || !is_string($term[1] ?? null)) {
                continue;
            }
            [$op, $field, $literal] = $term;
            $value = $record->$field ?? null;
            $ok = match ($op) {
                'eq' => $value === $literal,
                'in' => is_array($literal) && in_array($value, $literal, true),
                'starts' => is_string($value) && is_string($literal) && str_starts_with($value, $literal),
                default => is_array($value) ? in_array($literal, $value, true)
                    : (is_string($value) && is_string($literal) && str_contains($value, $literal)),
            };
            if (!$ok) {
                return false;
            }
        }

        return true;
    }

    private static function readsNode(mixed $expression): bool
    {
        if (!is_array($expression)) {
            return false;
        }
        foreach ($expression as $key => $value) {
            if (
                ($key === 'f' || $key === 'has' || $key === 'in')
                && is_string($value) && str_starts_with($value, 'node.')
            ) {
                return true;
            }
            if (self::readsNode($value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param mixed $expression
     * @param array<string, true> $blocks
     * @param array<string, true> $inline
     */
    private static function lookupTypes(mixed $expression, array &$blocks, array &$inline): void
    {
        if (!is_array($expression)) {
            return;
        }
        $lookup = $expression['lookup'] ?? null;
        $over = is_array($lookup) ? ($lookup['over'] ?? null) : null;
        if ($over === 'block' || $over === 'inline') {
            $target = $over;
            // C2-W-150: a lookup that names no types reads every block or every inline node.
            foreach (is_array($lookup['types'] ?? null) ? $lookup['types'] : ['*'] as $type) {
                if (is_string($type)) {
                    if ($target === 'block') {
                        $blocks[$type] = true;
                    } else {
                        $inline[$type] = true;
                    }
                }
            }
        }
        foreach ($expression as $value) {
            self::lookupTypes($value, $blocks, $inline);
        }
    }

    private static function readsLookup(mixed $expression): bool
    {
        if (!is_array($expression)) {
            return false;
        }
        if (isset($expression['lookup'])) {
            return true;
        }
        foreach ($expression as $value) {
            if (self::readsLookup($value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, array{node: array<string, true>, item: array<string, true>}>
     */
    private static function fieldsByNodeType(Context $context): array
    {
        $byType = [];
        foreach (self::rules() as $rule) {
            if (!$context->activeRule($rule['id']) || !in_array($rule['over'], ['block', 'inline'], true)) {
                continue;
            }
            $local = ['node' => [], 'item' => []];
            self::gatherFields($rule, $local);
            foreach ($rule['types'] ?? [] as $nodeType) {
                if (is_string($nodeType)) {
                    $byType[$nodeType]['node'] = ($byType[$nodeType]['node'] ?? []) + $local['node'];
                    $byType[$nodeType]['item'] = ($byType[$nodeType]['item'] ?? []) + $local['item'];
                }
            }
        }

        return $byType;
    }

    /**
     * @return array<string, true>
     */
    public static function nodeTypes(Context $context): array
    {
        $key = self::activeKey($context);
        if ($key !== self::$nodeTypesKey) {
            self::$nodeTypesKey = $key;
            self::$nodeTypes = self::collectNodeTypes($context);
        }

        return self::$nodeTypes;
    }

    /**
     * The ids of the active data rules, which decide every plan derived from the rule data.
     */
    private static function activeKey(Context $context): string
    {
        $key = '';
        foreach (self::rules() as $rule) {
            $key .= $context->activeRule($rule['id']) ? '1' : '0';
        }

        return $key;
    }

    /**
     * @return array<string, true>
     */
    private static function collectNodeTypes(Context $context): array
    {
        $types = [];
        $fields = ['node' => [], 'item' => []];
        $byType = self::fieldsByNodeType($context);
        foreach (self::rules() as $rule) {
            if ($context->activeRule($rule['id'])) {
                self::gatherFields($rule, $fields);
            }
            if (
                !in_array($rule['over'], ['block', 'inline'], true)
                || !$context->activeRule($rule['id']) || !self::readsNode($rule)
            ) {
                continue;
            }
            if ($rule['types'] === null) {
                $types['*'] = true;
            } else {
                foreach ($rule['types'] as $nodeType) {
                    if (is_string($nodeType)) {
                        $types[$nodeType] = true;
                    }
                }
            }
        }

        $lookupTypes = [];
        foreach (self::rules() as $rule) {
            if (!$context->activeRule($rule['id'])) {
                continue;
            }
            $blocks = [];
            $inline = [];
            self::lookupTypes($rule, $blocks, $inline);
            foreach (array_keys($blocks + $inline) as $type) {
                $types[$type] = true;
                $lookupTypes[$type] = true;
            }
        }
        self::$nodeFields = $fields;
        self::$nodeFieldsByType = $byType;
        self::$nodePlans = [];
        self::$itemPlans = [];
        foreach ($lookupTypes as $type => $_) {
            self::$nodeFieldsByType[$type] = $fields;
        }

        return $types;
    }

    /**
     * @param array<mixed> $expression
     * @param array{node: array<string, true>, item: array<string, true>} $fields
     */
    private static function gatherFields(array $expression, array &$fields): void
    {
        foreach ($expression as $key => $value) {
            if (($key === 'f' || $key === 'has' || $key === 'in' || $key === 'field') && is_string($value)) {
                foreach (['node', 'item'] as $owner) {
                    if (str_starts_with($value, $owner . '.')) {
                        $fields[$owner][substr($value, strlen($owner) + 1)] = true;
                    }
                }
            }
            if (is_array($value)) {
                self::gatherFields($value, $fields);
            }
        }
    }

    /**
     * @param array<mixed> $source
     * @param list<string> $parts
     * @param array<string, mixed> $target
     */
    private static function copyField(array $source, array $parts, array &$target): void
    {
        $last = count($parts) - 1;
        $into = &$target;
        foreach ($parts as $depth => $key) {
            // A negative index counts from the end of a list, as `field` reads it (`children.-1`). The copy keeps the
            // real index, so the copied list keeps its length.
            $at = str_starts_with($key, '-') && ctype_digit(substr($key, 1)) && array_is_list($source)
                ? count($source) + (int)$key : $key;
            if (!array_key_exists($at, $source)) {
                return;
            }
            if ($depth === $last) {
                $into[$at] = $source[$at];

                return;
            }
            if (!is_array($source[$at])) {
                return;
            }
            if (!is_array($into[$at] ?? null)) {
                $into[$at] = [];
            }
            $source = $source[$at];
            $into = &$into[$at];
        }
    }

    /**
     * @param array<string, mixed> $node
     *
     * @return array<string, mixed>
     */
    public static function nodeData(array $node): array
    {
        $type = is_string($node['type'] ?? null) ? $node['type'] : '';
        $plan = self::$nodePlans[$type] ?? self::nodePlan($type);
        $out = [];
        foreach ($plan as [$children, $parts]) {
            if ($children) {
                $out['children'] = [];
                foreach (is_array($node['children'] ?? null) ? $node['children'] : [] as $child) {
                    $item = [];
                    if (is_array($child)) {
                        foreach (self::$itemPlans[$type] as $itemParts) {
                            self::copyField($child, $itemParts, $item);
                        }
                    }
                    $out['children'][] = $item;
                }

                continue;
            }
            self::copyField($node, $parts, $out);
        }

        return $out;
    }

    /**
     * The node and item paths of a node type, split into their parts.
     *
     * @param string $type
     *
     * @return list<array{0: bool, 1: list<string>}>
     */
    private static function nodePlan(string $type): array
    {
        $fields = self::$nodeFieldsByType[$type] ?? self::$nodeFields;
        if ($fields === null) {
            $fields = ['node' => [], 'item' => []];
            foreach (self::rules() as $rule) {
                self::gatherFields($rule, $fields);
            }
            self::$nodeFields = $fields;
        }
        // The children list is built first; a deeper copy such as `children.-1.attrs` then adds to its items.
        $plan = [];
        $children = false;
        foreach ($fields['node'] as $path => $_) {
            $path = (string)$path;
            if ($path === 'children' || str_starts_with($path, 'children.')) {
                $children = true;
            }
            if ($path !== 'children') {
                $plan[] = [false, explode('.', $path)];
            }
        }
        if ($children) {
            array_unshift($plan, [true, ['children']]);
        }
        self::$nodePlans[$type] = $plan;
        $items = [];
        foreach ($fields['item'] as $itemPath => $_) {
            $items[] = explode('.', (string)$itemPath);
        }
        self::$itemPlans[$type] = $items;

        return $plan;
    }

    // Read only unconditional literal predicates for safe prefiltering.
    // phpcs:disable Generic.Metrics.CyclomaticComplexity.MaxExceeded

    /**
     * @return array<string, true>|null
     */
    private static function requiredValues(mixed $when, string $field): ?array
    {
        if (!is_array($when)) {
            return null;
        }
        $terms = is_array($when['all'] ?? null) ? $when['all'] : [$when];
        foreach ($terms as $term) {
            if (!is_array($term)) {
                continue;
            }
            if (is_array($term['all'] ?? null)) {
                $nested = self::requiredValues($term, $field);
                if ($nested !== null) {
                    return $nested;
                }
            }
            foreach (['eq', 'in'] as $operator) {
                $args = $term[$operator] ?? null;
                if (!is_array($args) || !is_array($args[0] ?? null) || ($args[0]['f'] ?? null) !== $field) {
                    continue;
                }
                $values = $operator === 'eq' ? [$args[1] ?? null] : ($args[1] ?? null);
                if (!is_array($values)) {
                    continue;
                }
                $out = [];
                foreach ($values as $value) {
                    if (is_string($value)) {
                        $out[$value] = true;
                    }
                }
                if ($out !== []) {
                    return $out;
                }
            }
        }

        return null;
    }

    /**
     * The rules of each kind and the prefilters derived from them, for the active rules of a run.
     *
     * @return array<int, mixed>
     */
    private static function plan(Context $context): array
    {
        $data = self::data();
        $byKind = ['line' => [], 'block' => [], 'inline' => []];
        $lineFilters = [];
        foreach (self::rules() as $rule) {
            if ($context->activeRule($rule['id'])) {
                $byKind[$rule['over']][] = $rule;
                if ($rule['over'] === 'line') {
                    $lineFilters[$rule['id']] = [
                        'leafs' => self::requiredValues($rule['when'], 'leaf'),
                        'shapes' => self::requiredValues($rule['when'], 'shape'),
                        'texts' => self::requiredTexts($rule['when']),
                    ];
                }
            }
        }
        $lookupRules = [];
        $lookupDemand = [];
        foreach (array_merge($byKind['line'], $byKind['block'], $byKind['inline']) as $rule) {
            $blocks = [];
            $inline = [];
            self::lookupTypes($rule, $blocks, $inline);
            if ($blocks !== [] || $inline !== []) {
                $lookupDemand[] = [$rule['id'], $rule['over'] === 'line' ? null : $rule['types'], $blocks, $inline];
            }
            $lookupRules[$rule['id']] = self::readsLookup($rule);
        }
        $typedRules = ['inline' => [], 'block' => []];
        $untypedRules = ['inline' => [], 'block' => []];
        foreach (['inline', 'block'] as $kind) {
            foreach ($byKind[$kind] as $rule) {
                if ($rule['types'] === null) {
                    $untypedRules[$kind][] = $rule;

                    continue;
                }
                foreach ($rule['types'] as $type) {
                    if (is_string($type)) {
                        $typedRules[$kind][$type][] = $rule;
                    }
                }
            }
        }
        $typedBlocks = [];
        $typedBlockParents = [];
        $genericBlocks = [];
        foreach ($byKind['block'] as $rule) {
            if ($rule['types'] !== null) {
                $typedBlockParents[$rule['id']] = self::requiredValues($rule['when'], 'parent.type');
                foreach ($rule['types'] as $type) {
                    if (is_string($type)) {
                        $typedBlocks[$type] = true;
                    }
                }

                continue;
            }
            $genericBlocks[] = [
                'id' => $rule['id'],
                'parents' => self::requiredValues($rule['when'], 'parent.type'),
                'itemOwners' => self::requiredValues($rule['when'], 'item_owner.type'),
            ];
        }
        $verbatimLeaves = [];
        foreach (is_array($data['verbatim_leaves'] ?? null) ? $data['verbatim_leaves'] : [] as $leaf) {
            if (is_string($leaf) || is_int($leaf)) {
                $verbatimLeaves[$leaf] = true;
            }
        }

        return [
            $byKind, $lineFilters, $lookupDemand, $lookupRules,
            $typedRules, $untypedRules, $typedBlocks, $genericBlocks, $verbatimLeaves, $typedBlockParents,
        ];
    }

    /**
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private static function requiredTexts(mixed $when): array
    {
        if (!is_array($when)) {
            return [];
        }
        $terms = is_array($when['all'] ?? null) ? $when['all'] : [$when];
        $out = [];
        foreach ($terms as $term) {
            if (!is_array($term)) {
                continue;
            }
            if (is_array($term['all'] ?? null)) {
                array_push($out, ...self::requiredTexts($term));
            }
            foreach (['eq', 'starts', 'contains'] as $operator) {
                $args = $term[$operator] ?? null;
                if (!is_array($args)) {
                    continue;
                }
                $field = is_array($args[0] ?? null) ? ($args[0]['f'] ?? null) : null;
                $literal = $args[1] ?? null;
                if (in_array($field, ['body', 'text'], true) && is_string($literal) && $literal !== '') {
                    $out[] = [$field, $operator, $literal];
                }
            }
        }

        return $out;
    }

    private static function mightBeDescription(string $text): bool
    {
        $at = strspn($text, " \t");
        while (substr($text, $at, 2) === '> ') {
            $at += 2;
            $at += strspn($text, " \t", $at);
        }

        return ($text[$at] ?? '') === ':';
    }

    private function rejectsUnevenListIndent(stdClass $record): bool
    {
        $index = $record->index ?? null;
        if ($index === 0) {
            return true;
        }
        if (!is_int($index) || !is_string($record->path ?? null) || $index < 0) {
            return false;
        }
        $cut = strrpos($record->path, '/');
        if ($cut === false) {
            return false;
        }
        $previousPath = substr($record->path, 0, $cut + 1) . ($index - 1);
        if (($this->context->nodeTypes[$previousPath] ?? null) !== 'list_item') {
            return false;
        }
        $current = $this->context->layoutEntries[$record->path]['markerColumn'] ?? null;
        $previous = $this->context->layoutEntries[$previousPath]['markerColumn'] ?? null;

        return is_int($current) && is_int($previous) && $current >= $previous;
    }

    private function rejectsListMarkerBelowColumn(stdClass $record): bool
    {
        $path = $record->path ?? null;
        if (!is_string($path)) {
            return false;
        }
        $at = strrpos($path, '/children/');
        if ($at === false) {
            return false;
        }
        $parent = substr($path, 0, $at);
        $index = $record->index ?? null;
        if ($index === 0 && !$this->hasOpenPreviousList($parent)) {
            return true;
        }
        $at = strrpos($parent, '/children/');
        if ($at === false) {
            return true;
        }
        $outer = substr($parent, 0, $at);
        if (($this->context->nodeTypes[$outer] ?? null) !== 'list_item') {
            return true;
        }
        $outerColumn = $this->context->layoutEntries[$outer]['markerColumn'] ?? null;
        $column = $this->context->layoutEntries[$path]['markerColumn'] ?? null;

        return is_int($outerColumn) && is_int($column) && $column <= $outerColumn;
    }

    private function hasOpenPreviousList(string $listPath): bool
    {
        $at = strrpos($listPath, '/children/');
        if ($at === false) {
            return false;
        }
        $index = substr($listPath, $at + 10);
        if (!ctype_digit($index)) {
            return false;
        }
        $siblings = substr($listPath, 0, $at + 10);
        for ($i = (int)$index - 1; $i >= 0; --$i) {
            $type = $this->context->nodeTypes[$siblings . $i] ?? null;
            if ($type === 'paragraph') {
                continue;
            }

            return $type === 'list';
        }

        return false;
    }

    public static function run(Context $context): void
    {
        if (self::rules() === []) {
            return;
        }
        /**
         * @var array{line: list<Rule>, block: list<Rule>, inline: list<Rule>} $byKind
         * @var array<string, array{
         *     leafs: array<string, true>|null, shapes: array<string, true>|null, texts: list<array{0: string, 1: string, 2: string}>,
         * }> $lineFilters
         * @var list<array{0: string, 1: list<mixed>|null, 2: array<string, true>, 3: array<string, true>}> $lookupDemand
         * @var array<string, bool> $lookupRules
         * @var array{inline: array<string, list<Rule>>, block: array<string, list<Rule>>} $typedRules
         * @var array{inline: list<Rule>, block: list<Rule>} $untypedRules
         * @var array<string, true> $typedBlocks
         * @var list<array{id: string, parents: array<string, true>|null, itemOwners: array<string, true>|null}> $genericBlocks
         * @var array<int|string, true> $verbatimLeaves
         * @var array<string, array<string, true>|null> $typedBlockParents
         */
        [
            $byKind, $lineFilters, $lookupDemand, $lookupRules,
            $typedRules, $untypedRules, $typedBlocks, $genericBlocks, $verbatimLeaves, $typedBlockParents,
        ] = self::$plans[self::activeKey($context)] ??= self::plan($context);
        $footnoteKeys = [];
        $duplicateFootnoteKey = false;
        foreach ($context->footnoteDefinitions as [$definition]) {
            $key = $definition['key'] ?? null;
            if (!is_string($key)) {
                $duplicateFootnoteKey = true;

                break;
            }
            if (isset($footnoteKeys[$key])) {
                $duplicateFootnoteKey = true;
            }
            $footnoteKeys[$key] = true;
        }
        // These facts are necessary for the corresponding rules to report a finding.
        $absentRule = [
            'footnote-labels-differ-only-in-whitespace' => !$duplicateFootnoteKey,
            'unused-footnote-definition' => false,
            'heading-marker-mixed' => !str_contains($context->source, '='),
            'comment-splits-table-row' => !str_contains($context->source, '+'),
            'description-separator-below-content-column' => preg_match('/^[ \t]*(?:> [ \t]*)*:/m', $context->source) !== 1,
            'quote-fence-ends-the-quote-above' => !str_contains($context->source, '::: >'),
            'github-alert-marker' => !str_contains($context->source, '[!'),
            'setext-heading-underline' => !str_contains($context->source, '===') && !str_contains($context->source, '---'),
            'thematic-break-spelling' => !str_contains($context->source, '---') && !str_contains($context->source, '___'),
            'blockquote-marker-without-space' => !str_contains($context->source, '>'),
            'html-comment-renders-as-text' => !str_contains($context->source, '<!--'),
            'region-marker-renders-as-text' => stripos($context->source, 'region') === false,
            'definition-in-container' => !str_contains($context->source, '['),
            'caption-looking-paragraph' => !str_contains($context->source, ':'),
            'block-marker-as-text' => !str_contains($context->source, '~~~')
                && !str_contains($context->source, ':::') && !str_contains($context->source, '{'),
        ];
        // A rule looks records up only when it runs: not when a fact it needs is absent, and for a typed rule only on a record of
        // its own types. A document where it cannot run needs no candidates for it.
        $present = array_flip($context->nodeTypes) + ['comment_block' => true, 'text_run' => true];
        $needBlocks = [];
        $needInline = [];
        foreach ($lookupDemand as [$id, $types, $blocks, $inline]) {
            if (
                ($absentRule[$id] ?? false)
                || $types !== null && array_intersect_key($present, array_flip(array_filter($types, 'is_string'))) === []
            ) {
                continue;
            }
            $needBlocks += $blocks;
            $needInline += $inline;
        }
        $plainTableCellGate = !isset($needBlocks['*']) && !isset($needBlocks['table_cell']);
        foreach ($genericBlocks as $filter) {
            if ($filter['parents'] === null || isset($filter['parents']['table_row'])) {
                $plainTableCellGate = false;
            }
        }
        $knownCellRules = [
            'deprecated-cell-alignment' => true,
            'table-marker-run-padding' => true,
            'table-span-marker-attribute' => true,
            'table-span-marker-blocked' => true,
        ];
        foreach ($typedRules['block']['table_cell'] ?? [] as $rule) {
            if (!isset($knownCellRules[$rule['id']])) {
                $plainTableCellGate = false;
            }
        }
        if (isset($needInline['*'])) {
            $wildcardNeeded = false;
            foreach (array_merge($byKind['line'], $byKind['block'], $byKind['inline']) as $rule) {
                $blocks = [];
                $inline = [];
                self::lookupTypes($rule, $blocks, $inline);
                if (!isset($inline['*'])) {
                    continue;
                }
                $leafs = $rule['over'] === 'line' ? self::requiredValues($rule['when'], 'leaf') : null;
                if ($leafs === null) {
                    $wildcardNeeded = true;

                    break;
                }
                foreach ($context->lines as $line) {
                    $type = $context->nodeTypes[$line->path] ?? null;
                    if ($type === null || !DataModel::isLineLeaf($type) || isset($leafs[$type])) {
                        $wildcardNeeded = true;

                        break 2;
                    }
                }
            }
            if (!$wildcardNeeded) {
                unset($needInline['*']);
            }
        }
        $textRunNeedles = '';
        // Wildcard inline lookups index nodes only; the block-marker rule cannot consume a text run.
        $wildcardTextRunLookup = isset($needInline['*']) && !self::markerOnlyWildcardInlineLookup();
        if (!$wildcardTextRunLookup && !isset($needInline['text_run']) && $untypedRules['inline'] === []) {
            foreach ($typedRules['inline']['text_run'] ?? [] as $rule) {
                $literal = null;
                foreach ($rule['early'] as [$op, $field, $value]) {
                    if ($field === 'text' && in_array($op, ['contains', 'starts', 'eq'], true) && is_string($value) && $value !== '') {
                        $literal = $value;

                        break;
                    }
                }
                if ($literal === null) {
                    $textRunNeedles = '';

                    break;
                }
                $textRunNeedles .= $literal[0];
            }
        }
        $self = self::$interpreter ??= new self($context);
        $self->context = $context;
        $deferredBlocks = [];
        $deferredInline = [];
        // The node types whose records a rule that looks records up reads, by kind; `*` for an untyped rule.
        $deferred = ['inline' => [], 'block' => []];
        foreach (['inline', 'block'] as $kind) {
            foreach ($untypedRules[$kind] as $rule) {
                if ($lookupRules[$rule['id']]) {
                    $deferred[$kind]['*'] = true;
                }
            }
            foreach ($typedRules[$kind] as $type => $rules) {
                foreach ($rules as $rule) {
                    if ($lookupRules[$rule['id']]) {
                        $deferred[$kind][$type] = true;
                    }
                }
            }
        }
        $apply = static function (
            stdClass $record,
            string $kind,
            bool $lookups = false,
        ) use (
            $self,
            $typedRules,
            $untypedRules,
            $lookupRules,
            &$absentRule,
        ): void {
            foreach ($typedRules[$kind][$record->type ?? ''] ?? [] as $rule) {
                if ($lookupRules[$rule['id']] === $lookups && !($absentRule[$rule['id']] ?? false)) {
                    if ($rule['id'] === 'uneven-list-indent' && $self->rejectsUnevenListIndent($record)) {
                        continue;
                    }
                    if (
                        $rule['id'] === 'list-marker-below-content-column'
                        && $self->rejectsListMarkerBelowColumn($record)
                    ) {
                        continue;
                    }
                    $self->apply($rule, $record);
                }
            }
            foreach ($untypedRules[$kind] as $rule) {
                if ($lookupRules[$rule['id']] === $lookups && !($absentRule[$rule['id']] ?? false)) {
                    $self->apply($rule, $record);
                }
            }
        };
        $acceptBlock = static function (
            string $type,
            string $parentType,
            string $itemOwnerType,
            array $node,
            int $start,
            int $end,
            int $index,
        ) use (
            $typedRules,
            $typedBlockParents,
            $genericBlocks,
            $needBlocks,
            $context,
            $plainTableCellGate,
        ): bool {
            if (isset($needBlocks[$type]) || isset($needBlocks['*'])) {
                return true;
            }
            if (
                $plainTableCellGate && $type === 'table_cell' && !isset($node['span']) && $end >= $start
                && strcspn($context->source, '<>~?{^', $start, $end - $start) === $end - $start
            ) {
                return false;
            }
            foreach ($typedRules['block'][$type] ?? [] as $rule) {
                $parents = $typedBlockParents[$rule['id']] ?? null;
                if ($parents !== null && !isset($parents[$parentType])) {
                    continue;
                }
                if ($rule['id'] === 'block-opener-overindented' && !isset($node['attrs'])) {
                    $feed = $start > 0 ? strrpos($context->source, "\n", $start - 1 - strlen($context->source)) : false;
                    $lineStart = $feed === false ? 0 : $feed + 1;
                    if (str_ends_with(substr($context->source, $lineStart, $start - $lineStart), '> ')) {
                        continue;
                    }
                }
                if (
                    $rule['id'] === 'html-comment-renders-as-text'
                    && is_array($rule['each'] ?? null)
                    && is_array($rule['each']['matches'] ?? null)
                    && ($rule['each']['matches'][1] ?? null) === '(?<o><!--)'
                    && !str_contains(substr($context->source, $start, $end - $start), '<!--')
                ) {
                    continue;
                }
                if (self::earlyPasses($rule['early'], $node, $context->source, $start, $end)) {
                    return true;
                }
            }
            foreach ($genericBlocks as $filter) {
                if (
                    ($filter['parents'] === null || isset($filter['parents'][$parentType]))
                    && ($filter['itemOwners'] === null || isset($filter['itemOwners'][$itemOwnerType]))
                ) {
                    if ($filter['id'] === 'list-item-body-below-content-column' && $index < 1) {
                        continue;
                    }
                    if (
                        $filter['id'] === 'list-item-block-overindented' && $type === 'paragraph'
                        && !isset($node['attrs']) && !DataModel::imageLine($node)
                    ) {
                        continue;
                    }

                    return true;
                }
            }

            return false;
        };
        $mightUseBlock = static function (
            string $type,
            string $parentType,
        ) use (
            $typedRules,
            $genericBlocks,
            $needBlocks,
        ): bool {
            if (
                isset($typedRules['block'][$type]) || isset($needBlocks[$type]) || isset($needBlocks['*'])
            ) {
                return true;
            }
            foreach ($genericBlocks as $filter) {
                if ($filter['parents'] === null || isset($filter['parents'][$parentType])) {
                    return true;
                }
            }

            return false;
        };
        $mightUseInline = static function (
            string $type,
            ?array $node,
            int $start,
            int $end,
        ) use (
            $typedRules,
            $untypedRules,
            $needInline,
            $context,
        ): bool {
            if (isset($needInline['*']) || isset($needInline[$type])) {
                return true;
            }
            foreach ($typedRules['inline'][$type] ?? [] as $rule) {
                if (self::earlyPasses($rule['early'], $node, $context->source, $start, $end)) {
                    return true;
                }
            }
            foreach ($untypedRules['inline'] as $rule) {
                if (self::earlyPasses($rule['early'], $node, $context->source, $start, $end)) {
                    return true;
                }
            }

            return false;
        };
        $model = new DataModel(
            $context,
            static function (stdClass $record) use ($apply, $self, $needInline, $deferred, &$deferredInline): void {
                if (isset($needInline[$record->type ?? '']) || isset($needInline['*'])) {
                    $self->lookupRecords['inline'][] = $record;
                }
                $apply($record, 'inline');
                if (isset($deferred['inline']['*']) || isset($deferred['inline'][$record->type ?? ''])) {
                    $deferredInline[] = $record;
                }
            },
            static function (stdClass $record) use ($self, $apply, $needBlocks, $deferred, &$deferredBlocks): bool {
                $kept = false;
                if (isset($needBlocks[$record->type ?? '']) || isset($needBlocks['*'])) {
                    $self->lookupRecords['block'][] = $record;
                    $kept = true;
                }
                $apply($record, 'block');
                if (isset($deferred['block']['*']) || isset($deferred['block'][$record->type ?? ''])) {
                    $deferredBlocks[] = $record;
                    $kept = true;
                }

                return $kept;
            },
            $acceptBlock,
            $mightUseBlock,
            $mightUseInline,
            isset($typedRules['inline']['text_run']),
            $textRunNeedles,
            $plainTableCellGate,
        );
        // The rules that look records up run once the walk has made every record they can find.
        $self->lookupRecords['line'] = $model->lines;
        $self->lookupRecords['id'] = $model->ids;
        $inlineFootnoteKeys = [];
        foreach ($self->lookupRecords['inline'] as $inlineRecord) {
            if (($inlineRecord->type ?? null) === 'footnote_ref' && is_string($inlineRecord->nodeSource['key'] ?? null)) {
                $inlineFootnoteKeys[$inlineRecord->nodeSource['key']] = true;
            }
        }
        $absentRule['unused-footnote-definition'] = true;
        foreach ($context->footnoteDefinitions as [$definition]) {
            if (!is_string($definition['key'] ?? null) || !isset($inlineFootnoteKeys[$definition['key']])) {
                $absentRule['unused-footnote-definition'] = false;

                break;
            }
        }
        foreach ($deferredInline as $record) {
            $apply($record, 'inline', true);
        }
        foreach ($deferredBlocks as $record) {
            $apply($record, 'block', true);
        }
        $deferredBlocks = $deferredInline = [];
        $lineCandidates = [];
        foreach ($model->lines as $record) {
            $key = $record->leaf . "\0" . $record->shape;
            if (!isset($lineCandidates[$key])) {
                $lineCandidates[$key] = [];
                foreach ($byKind['line'] as $rule) {
                    if ($absentRule[$rule['id']] ?? false) {
                        continue;
                    }
                    $filter = $lineFilters[$rule['id']];
                    if (
                        ($filter['leafs'] !== null && !isset($filter['leafs'][$record->leaf]))
                        || ($filter['shapes'] !== null && !isset($filter['shapes'][$record->shape]))
                    ) {
                        continue;
                    }
                    $lineCandidates[$key][] = [$rule, $filter['texts']];
                }
            }
            $leadText = ltrim($record->text, " \t")[0] ?? '';
            foreach ($lineCandidates[$key] as [$rule, $texts]) {
                if (
                    in_array($rule['id'], [
                        'block-marker-as-text', 'html-comment-renders-as-text',
                        'region-marker-renders-as-text', 'definition-in-container',
                    ], true)
                ) {
                    if (
                        $rule['id'] === 'block-marker-as-text'
                        && !str_contains($record->text, '~~~') && !str_contains($record->text, '{')
                        && preg_match('/:{3,}\S/', $record->text) !== 1
                    ) {
                        continue;
                    }
                    if ($rule['id'] === 'html-comment-renders-as-text' && !str_contains($record->text, '<!--')) {
                        continue;
                    }
                    if ($rule['id'] === 'region-marker-renders-as-text' && stripos($record->text, 'region') === false) {
                        continue;
                    }
                    if ($rule['id'] === 'definition-in-container' && !str_contains($record->text, '[')) {
                        continue;
                    }
                }
                if (
                    $record->leaf_first
                    && in_array($rule['id'], ['block-opener-without-blank-line', 'markdown-definition-paragraph'], true)
                ) {
                    continue;
                }
                if ($rule['id'] === 'github-alert-marker' && $leadText !== '>') {
                    continue;
                }
                if ($rule['id'] === 'quote-fence-ends-the-quote-above' && $leadText !== ':') {
                    continue;
                }
                if (
                    $rule['id'] === 'setext-heading-underline'
                    && ($leadText === '' || !str_contains('>=-', $leadText))
                ) {
                    continue;
                }
                if ($rule['id'] === 'empty-reference-definition' && ($record->body[0] ?? '') !== '[') {
                    continue;
                }
                if ($rule['id'] === 'blockquote-marker-without-space' && ($record->text[0] ?? '') !== '>') {
                    continue;
                }
                if (
                    $rule['id'] === 'deprecated-spaced-thematic-break'
                    && !str_contains('-*_', $record->body[0] ?? '')
                ) {
                    continue;
                }
                if (
                    $rule['id'] === 'thematic-break-spelling'
                    && !str_contains('-_', $record->body[0] ?? '')
                ) {
                    continue;
                }
                if (
                    $rule['id'] === 'description-separator-below-content-column'
                    && !self::mightBeDescription($record->text)
                ) {
                    continue;
                }
                foreach ($texts as [$field, $operator, $literal]) {
                    $value = $record->$field;
                    $matches = match ($operator) {
                        'eq' => $value === $literal,
                        'starts' => str_starts_with($value, $literal),
                        default => str_contains($value, $literal),
                    };
                    if (!$matches) {
                        continue 2;
                    }
                }
                if (isset($verbatimLeaves[$record->leaf]) && !in_array($record->leaf, $rule['verbatim'], true)) {
                    continue;
                }
                $self->apply($rule, $record);
            }
        }
        // The line records link to their neighbors: drop the links, so the run leaves no cycles behind for the collector. The
        // shared interpreter keeps its compiled closures but not the document.
        unset($self->context);
        $self->lookupRecords = ['block' => [], 'inline' => [], 'id' => [], 'line' => []];
        $self->lookupIndexes = [];
        $self->lookupStarts = [];
        foreach ($model->lines as $line) {
            $line->prev = $line->prev2 = $line->next = $line->next2 = null;
            $line->leaf_head = $line->leaf_next = $line->table_above = $line->table_below = null;
        }
    }

    /**
     * @phpstan-param Rule $rule
     *
     * @param array<string, mixed> $rule
     * @param \stdClass $record
     */
    private function apply(array $rule, stdClass $record): void
    {
        if (
            $rule['early'] !== []
            && !self::earlyPasses(
                $rule['early'],
                is_array($record->nodeSource ?? null) ? $record->nodeSource : null,
                $this->context->source,
                $record->start,
                $record->end,
                isset($record->number) ? $record->text : null,
                isset($record->number) ? $record->body : null,
            )
        ) {
            return;
        }
        $this->vars = is_array($rule['let'] ?? null) ? $rule['let'] : [];
        $this->letValues = [];
        $this->groups = [];
        $this->base = 0;
        if ($rule['pre'] !== [] && !self::passes($rule['pre'], $record)) {
            return;
        }
        if (
            isset($rule['when'])
            && !self::truthy(($this->compiledWhen[$rule['key']]
                ??= $this->compile($rule['when'], $this->vars))($record))
        ) {
            return;
        }
        if (!isset($rule['each'])) {
            $this->report($rule, $record);

            return;
        }
        $each = $rule['each'];
        $pairs = is_array($each) ? ($each['pairs'] ?? null) : null;
        if (is_array($pairs) && is_string($pairs[1] ?? null)) {
            $value = $this->value($pairs[0], $record);
            if (!is_string($value)) {
                return;
            }
            $base = $this->textBase($pairs[0], $record);
            $predicateGroups = $this->groups;
            foreach (self::pairGroups($value, $pairs[1], ($pairs[2] ?? null) === 'strict') as $groups) {
                $absolute = [];
                foreach ($groups as $name => [$start, $end]) {
                    $absolute[$name] = [$base + $start, $base + $end];
                }
                $this->groups = array_replace($predicateGroups, $absolute);
                $this->base = $base;
                $this->report($rule, $record);
            }

            return;
        }
        $matches = is_array($each) ? ($each['matches'] ?? null) : null;
        if (!is_array($matches) || !is_string($matches[1] ?? null)) {
            return;
        }
        [$text, $pattern] = $matches;
        $value = $this->value($text, $record);
        if (!is_string($value)) {
            return;
        }
        $base = $this->textBase($text, $record);
        self::matchAll(self::pattern($pattern), $value, $matches);
        $predicateGroups = $this->groups;
        $predicateBase = $this->base;
        foreach ($matches as $match) {
            $this->groups = $predicateGroups;
            $this->base = $predicateBase;
            $this->saveGroups($match, $base);
            $this->report($rule, $record);
        }
    }

    // Report entries select a range and one of several message variants.
    // phpcs:disable Generic.Metrics.CyclomaticComplexity.MaxExceeded

    /**
     * @param array<string, mixed> $rule
     * @param \stdClass $record
     */
    private function report(array $rule, stdClass $record): void
    {
        $entries = $rule['report'] ?? null;
        if (!is_array($entries) || !is_string($rule['id'] ?? null)) {
            return;
        }
        $range = is_array($rule['range'] ?? null) ? $rule['range'] : [];
        $ruleValues = is_array($rule['values'] ?? null) ? $rule['values'] : [];
        $key = is_string($rule['key'] ?? null) ? $rule['key'] : '';
        foreach ($entries as $i => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $when = isset($entry['when']) ? ($this->compiledReport[$key][$i] ??= $this->compile($entry['when'], $this->vars)) : null;
            if ($when !== null && !self::truthy($when($record))) {
                continue;
            }
            $start = $this->value($entry['start'] ?? $range['start'] ?? ['f' => 'start'], $record);
            $end = $this->value($entry['end'] ?? $range['end'] ?? ['f' => 'end'], $record);
            if (!is_int($start) || !is_int($end)) {
                return;
            }
            $values = [];
            $entryValues = is_array($entry['values'] ?? null) ? $entry['values'] : [];
            foreach (array_merge($ruleValues, $entryValues) as $name => $expr) {
                $value = $this->value($expr, $record);
                $values[$name] = is_int($value) || is_string($value) ? $value : '';
            }
            $message = is_int($entry['message'] ?? null) ? $entry['message'] : 0;
            $path = $record->path ?? '';
            // C2-W-157: a pipe table line names its row; any other line its leaf block or innermost holding block.
            if (($rule['over'] ?? null) === 'line' && isset($record->number)) {
                $path = $record->row_path ?? $path;
            }
            $this->context->report($rule['id'], $start, $end, $path, $values, $message);

            return;
        }
    }

    /**
     * `preg_match` of a rule pattern. A pattern that runs out of JIT stack on a long line runs again without JIT,
     * which keeps its backtracking on the heap.
     *
     * @param-out array<array<int, int|string|null>|string|null> $parts
     *
     * @phpstan-param 0|256|512|768 $flags
     *
     * @param string $pattern
     * @param string $value
     * @param mixed $parts
     * @param int $flags
     *
     * @return int|false
     */
    private static function matchOne(string $pattern, string $value, mixed &$parts, int $flags): int|false
    {
        $found = preg_match($pattern, $value, $parts, $flags);
        if ($found === false && preg_last_error() === PREG_JIT_STACKLIMIT_ERROR) {
            $jit = ini_set('pcre.jit', '0');
            $found = preg_match(self::withoutJit($pattern), $value, $parts, $flags);
            ini_set('pcre.jit', $jit === false ? '1' : $jit);
        }

        return $found;
    }

    /**
     * `preg_match_all` of a rule pattern, with the fallback of `matchOne`.
     *
     * @param-out array<int, array<array<int, int|string|null>>> $matches
     *
     * @param string $pattern
     * @param string $value
     * @param mixed $matches
     *
     * @return void
     */
    private static function matchAll(string $pattern, string $value, mixed &$matches): void
    {
        $flags = PREG_SET_ORDER | PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL;
        if (preg_match_all($pattern, $value, $matches, $flags) === false && preg_last_error() === PREG_JIT_STACKLIMIT_ERROR) {
            $jit = ini_set('pcre.jit', '0');
            preg_match_all(self::withoutJit($pattern), $value, $matches, $flags);
            ini_set('pcre.jit', $jit === false ? '1' : $jit);
        }
    }

    /**
     * The pattern with a comment that gives it its own compiled entry, compiled while JIT is off.
     *
     * @param string $pattern
     *
     * @return string
     */
    private static function withoutJit(string $pattern): string
    {
        $end = (int)strrpos($pattern, '~');

        return substr($pattern, 0, $end) . '(?#no-jit)' . substr($pattern, $end);
    }

    private static function pattern(string $source, string $flags = ''): string
    {
        static $cache = [];
        $key = $flags . "\0" . $source;
        if (isset($cache[$key])) {
            return $cache[$key];
        }
        if (str_contains($source, '\\u{')) {
            $source = str_replace('\\u{', '\\x{', $source);
        }
        if (str_contains($source, '\\p{') || str_contains($source, '\\x{')) {
            $flags .= 'u';
        }

        $escaped = '';
        $slashes = 0;
        for ($i = 0, $length = strlen($source); $i < $length; $i++) {
            $char = $source[$i];
            if ($char === '~' && $slashes % 2 === 0) {
                $escaped .= '\\';
            }
            $escaped .= $char;
            $slashes = $char === '\\' ? $slashes + 1 : 0;
        }

        return $cache[$key] = '~' . $escaped . '~' . $flags;
    }

    /**
     * @return array{0: string, 1: int, 2: int}
     */
    private static function charAt(string $text, int $at): array
    {
        $length = strlen($text);
        if ($at >= $length || $at < 0) {
            return ['', $at, $at];
        }
        $lead = ord($text[$at]);
        $width = $lead < 0x80 ? 1 : ($lead < 0xE0 ? 2 : ($lead < 0xF0 ? 3 : 4));
        $end = min($length, $at + $width);

        return [substr($text, $at, $end - $at), $at, $end];
    }

    /**
     * @return array{0: string, 1: int, 2: int}
     */
    private static function charBefore(string $text, int $at): array
    {
        if ($at <= 0) {
            return ['', 0, 0];
        }
        $start = $at - 1;
        while ($start > 0 && (ord($text[$start]) & 0xC0) === 0x80) {
            $start--;
        }

        return [substr($text, $start, $at - $start), $start, $at];
    }

    private static function escapedAt(string $text, int $at): bool
    {
        $slashes = 0;
        while ($at > $slashes && $text[$at - 1 - $slashes] === '\\') {
            $slashes++;
        }

        return $slashes % 2 === 1;
    }

    private static function joinedAt(string $text, int $at): bool
    {
        return preg_match('/^[\p{M}\x{200D}\x{FE00}-\x{FE0F}\x{E0100}-\x{E01EF}]/u', substr($text, $at, 5)) === 1;
    }

    /**
     * @return \Generator<array<string, array{0: int, 1: int}>>
     */
    private static function pairGroups(string $text, string $mark, bool $strict): Generator
    {
        $width = strlen($mark);
        if ($width === 0) {
            return;
        }
        $length = strlen($text);
        $at = 0;
        while ($at < $length) {
            $start = strpos($text, $mark, $at);
            if ($start === false) {
                return;
            }
            [$before, $beforeStart] = self::charBefore($text, $start);
            [$afterOpen] = self::charAt($text, $start + $width);
            if (
                $strict && (
                    self::joinedAt($text, $start + $width) || self::escapedAt($text, $start)
                    || ($start > 0 && $text[$start - 1] === $mark[0]) || $afterOpen === ''
                    || preg_match('/^[\p{Zs}\t\n\r\f\v]$/u', $afterOpen) === 1
                    || $afterOpen === $mark[0]
                    || ($width === 1 && preg_match('/^[\p{L}\p{N}]$/u', $before) === 1)
                )
            ) {
                $at = $start + $width;

                continue;
            }
            // Like `indexOf`, a search that starts past the end finds nothing.
            $end = $start + $width + 1 <= $length ? strpos($text, $mark, $start + $width + 1) : false;
            while ($end !== false) {
                [$prior] = self::charBefore($text, $end);
                if (
                    preg_match('/^[\p{Zs}\t\n\r]$/u', $prior) !== 1
                    && !self::escapedAt($text, $end)
                    && (!$strict || !self::joinedAt($text, $end + $width))
                ) {
                    break;
                }
                $end = strpos($text, $mark, $end + $width);
            }
            if ($end === false) {
                return;
            }
            $after = $end + $width;
            [, , $afterEnd] = self::charAt($text, $after);
            $backslashStart = $start;
            while ($backslashStart > 0 && $text[$backslashStart - 1] === '\\') {
                --$backslashStart;
            }

            yield [
                'backslashes' => [$backslashStart, $start],
                'open' => [$start, $start + $width],
                'inner' => [$start + $width, $end],
                'close' => [$end, $after],
                'before' => [$beforeStart, $start],
                'after' => [$after, $afterEnd],
            ];

            $at = $after;
        }
    }

    /**
     * @param array<string, mixed> $match
     * @param int $base
     */
    private function saveGroups(array $match, int $base): void
    {
        $groups = [];
        foreach ($match as $key => $part) {
            if (
                !is_array($part) || !is_int($part[1] ?? null)
                || $part[1] < 0 || !is_string($part[0] ?? null)
            ) {
                continue;
            }
            $groups[$key] = [$base + $part[1], $base + $part[1] + strlen($part[0])];
        }
        if ($groups !== []) {
            $this->groups = array_replace($this->groups, $groups);
            $this->base = $base;
        }
    }

    private function textBase(mixed $expr, stdClass $record): int
    {
        if (!is_array($expr)) {
            return 0;
        }
        $field = $expr['f'] ?? null;
        if (is_string($field) && str_contains($field, '.')) {
            [$owner, $nested] = explode('.', $field, 2);
            $value = $record->$owner ?? null;
            if ($value instanceof stdClass) {
                return $this->textBase(['f' => $nested], $value);
            }
        }
        if ($field === 'body' || $field === 'raw_body') {
            return $record->body_start ?? 0;
        }
        if ($field === 'opener_text') {
            return $record->opener_start ?? 0;
        }
        if ($field === 'text') {
            return $record->start ?? 0;
        }
        if (isset($expr['text_from'])) {
            $from = $this->value($expr['text_from'], $record);

            return is_int($from) ? $from : 0;
        }

        return 0;
    }

    /**
     * The part of a node the rules read: its kind, position and attributes, and the node fields the active rules name.
     *
     * @param array<string, mixed> $node
     *
     * @return array<string, mixed>
     */
    private function compactNode(array $node): array
    {
        $type = is_string($node['type'] ?? null) ? $node['type'] : '';
        $compact = [
            'type' => $type,
            'form' => $node['form'] ?? null,
            'pos' => is_int($node['pos'] ?? null) ? $this->context->position($node['pos']) : $node['pos'] ?? null,
            'attrs' => $node['attrs'] ?? null,
            'imageLine' => DataModel::imageLine($node),
        ];
        if (isset($this->context->dataNodeTypes[$type]) || isset($this->context->dataNodeTypes['*'])) {
            foreach (self::nodeData($node) as $field => $value) {
                $compact[$field] = $value;
            }
        }

        return $compact;
    }

    private function field(string $path, stdClass $record): mixed
    {
        if (!str_contains($path, '.')) {
            $this->blockScalar($record, $path);
            if ($path === 'quote_marks' && property_exists($record, 'quote_marks') && $record->quote_marks === null) {
                $record->quote_marks = DataModel::quoteMarks($record->text);
            }
            if ($path === 'lazy' && property_exists($record, 'lazy') && $record->lazy === null) {
                $record->lazy = DataModel::lazy($this->context, $record);
            }
            if ($path === 'node' && isset($record->nodeSource) && $record->node === null) {
                $record->node = $this->compactNode($record->nodeSource);
            }
            if (($path === 'ancestors' || $path === 'ancestor_ids') && !isset($record->$path)) {
                if (property_exists($record, 'ancestor_path') && !isset($record->ancestors)) {
                    $record->ancestors = DataModel::ancestors($this->context, $record->ancestor_path);
                }
                $this->populateAncestors($record);
            }
            if ($path === 'text' && property_exists($record, 'text') && $record->text === null) {
                $record->text = substr($this->context->source, $record->start, $record->end - $record->start);
            }
            if ($path === 'layout' || $path === 'raw_ref' || $path === 'key_text') {
                $this->lateField($record, $path);
            }

            return $record->$path ?? null;
        }
        static $parts = [];
        $segments = $parts[$path] ??= explode('.', $path);
        // A named field can be read from the source tree. Building the whole projected `node` for one scalar field
        // copies every field named by every rule for this type, even when the current rule only needs one path.
        $direct = $segments[0] === 'node' && isset($record->nodeSource);
        $value = $direct ? $record->nodeSource : $record;
        foreach ($direct ? array_slice($segments, 1) : $segments as $part) {
            if (is_object($value)) {
                if ($value instanceof stdClass) {
                    $this->blockScalar($value, $part);
                    if ($part === 'quote_marks' && property_exists($value, 'quote_marks') && $value->quote_marks === null) {
                        $value->quote_marks = DataModel::quoteMarks($value->text);
                    }
                    if ($part === 'lazy' && property_exists($value, 'lazy') && $value->lazy === null) {
                        $value->lazy = DataModel::lazy($this->context, $value);
                    }
                }
                if (
                    $value instanceof stdClass && ($part === 'ancestors' || $part === 'ancestor_ids')
                    && !isset($value->$part)
                ) {
                    if (property_exists($value, 'ancestor_path') && !isset($value->ancestors)) {
                        $value->ancestors = DataModel::ancestors($this->context, $value->ancestor_path);
                    }
                    $this->populateAncestors($value);
                }
                if ($part === 'node' && $value instanceof stdClass && isset($value->nodeSource) && $value->node === null) {
                    $value->node = $this->compactNode($value->nodeSource);
                }
                if (($part === 'layout' || $part === 'raw_ref' || $part === 'key_text') && $value instanceof stdClass) {
                    $this->lateField($value, $part);
                }
                if (
                    $value instanceof stdClass && $part === 'text' && property_exists($value, 'text')
                    && $value->text === null && isset($value->start, $value->end)
                ) {
                    $value->text = substr($this->context->source, $value->start, $value->end - $value->start);
                }
                $value = $value->$part ?? null;
            } elseif (is_array($value)) {
                $index = ctype_digit($part) || (str_starts_with($part, '-') && ctype_digit(substr($part, 1)))
                    ? (int)$part : $part;
                if (is_int($index) && $index < 0 && array_is_list($value)) {
                    $index += count($value);
                }
                $value = $value[$index] ?? null;
                if ($index === 'pos' && is_int($value)) {
                    $value = $this->context->position($value);
                }
            } else {
                return null;
            }
        }

        return $value;
    }

    private function blockScalar(stdClass $record, string $field): void
    {
        if (!isset($record->quote_depth_internal) || !property_exists($record, $field) || $record->$field !== null) {
            return;
        }
        $layout = $this->context->layout;
        $source = $this->context->source;
        $quotes = $record->quote_depth_internal;
        switch ($field) {
            case 'column':
                $record->column = $layout->columnOf($record->start, $quotes);

                return;
            case 'mark_column':
                $record->mark_column = $layout->columnOf($record->opener_start, $quotes);

                return;
            case 'opener_column':
                $record->opener_column = $layout->markerColumn($record->path) ?? $layout->columnOf($record->start, $quotes);

                return;
            case 'opener_text':
                $end = strpos($source, "\n", $record->opener_start);
                $record->opener_text = rtrim(substr(
                    $source,
                    $record->opener_start,
                    ($end === false ? strlen($source) : $end) - $record->opener_start,
                ), " \t");

                return;
            case 'after_quote_mark':
                $opener = $record->opener_start;
                $feed = $opener > 0 ? strrpos($source, "\n", $opener - 1 - strlen($source)) : false;
                $lineStart = $feed === false ? 0 : $feed + 1;
                $record->after_quote_mark = str_ends_with(substr($source, $lineStart, $opener - $lineStart), '> ');

                return;
            case 'prev_type':
                $index = $record->index ?? 0;
                $path = $record->path;
                $previous = $index > 0 && preg_match('~/[0-9]+$~', $path)
                    ? preg_replace('~/[0-9]+$~', '/' . ($index - 1), $path) : null;
                $record->prev_type = $previous === null ? null : ($this->context->nodeTypes[$previous] ?? null);
        }
    }

    private function populateAncestors(stdClass $record): void
    {
        $path = $record->path ?? '';
        $ids = [];
        $types = [];
        while (is_string($path) && $path !== '') {
            $split = strrpos($path, '/children/');
            $path = $split === false ? '' : substr($path, 0, $split);
            $ids[] = $path;
            $types[] = $this->context->nodeTypes[$path] ?? 'document';
        }
        $record->ancestor_ids = $ids;
        if (!isset($record->ancestors)) {
            $record->ancestors = $types;
        }
    }

    /**
     * The fields a record makes on first read: the layout entry of a block (C2-L-009) and the reference of a link or
     * an image (C2-L-022).
     */
    private function lateField(stdClass $record, string $part): void
    {
        if (property_exists($record, $part)) {
            return;
        }
        if ($part === 'layout' && property_exists($record, 'opener_start')) {
            $record->layout = $this->context->layoutEntries[$record->path] ?? null;
        } elseif ($part === 'raw_ref' && property_exists($record, 'leaf') && isset($record->path)) {
            $raw = $this->context->layoutEntries[$record->path]['rawRef'] ?? null;
            $record->raw_ref = is_string($raw) ? $raw : $this->field('text', $record);
        } elseif ($part === 'key_text' && property_exists($record, 'leaf')) {
            // C2-I-084: the stored key of a reference read as inline content, as plain text.
            $key = $this->field('node.key', $record);
            $record->key_text = is_string($key)
                ? PlainText::of(InlineRunner::parse($key, [0, strlen($key)], new InlineContext())) : null;
        }
    }

    /**
     * C2-W-141: an absent value, which a lookup with no match gives as NAN, does not hold.
     *
     * @param mixed $value
     *
     * @return bool
     */
    private static function truthy(mixed $value): bool
    {
        return !(is_float($value) && is_nan($value)) && (bool)$value;
    }

    private static function nfc(string $text): string
    {
        $normalized = Normalizer::normalize($text, Normalizer::FORM_C);

        return is_string($normalized) ? $normalized : $text;
    }

    /**
     * C2-W-150 `plain_list` and `quoted_list`: `X`, `X and Y`, `X, Y, and Z`, in source order.
     *
     * @param array<string> $items
     * @param bool $quoted
     *
     * @return string
     */
    private static function listPhrase(array $items, bool $quoted): string
    {
        if ($quoted) {
            $items = array_map(static fn (string $item): string => '"' . $item . '"', $items);
        }
        if (count($items) < 3) {
            return implode(' and ', $items);
        }
        $last = array_pop($items);

        return implode(', ', $items) . ', and ' . $last;
    }

    private static function integer(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && is_numeric($value) ? (int)$value : 0;
    }

    private static function string(mixed $value): string
    {
        return is_string($value) || is_int($value) || is_float($value) || is_bool($value)
            ? (string)$value : '';
    }

    /**
     * @return array<mixed>
     */
    private static function set(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value)) {
            $fromData = self::data()[$value] ?? null;

            return is_array($fromData) ? $fromData : [];
        }

        return [];
    }

    /**
     * `value()` as a closure for the operators a predicate mostly uses, with the same order of evaluation and the same
     * results; any other expression is left to `value()`.
     *
     * @param mixed $expr
     * @param array<mixed> $let The `let` of the rule the expression belongs to.
     *
     * @return \Closure(\stdClass): mixed
     */
    private function compile(mixed $expr, array $let): Closure
    {
        if (!is_array($expr) || array_is_list($expr)) {
            return static fn (stdClass $record): mixed => $expr;
        }
        $op = array_key_first($expr);
        $arg = $expr[$op];
        if ($op === 'f' && is_string($arg)) {
            $lazyFields = [
                'ancestors', 'ancestor_ids', 'text', 'node', 'layout', 'raw_ref', 'key_text',
                'column', 'mark_column', 'opener_column', 'opener_text', 'after_quote_mark', 'prev_type',
                'lazy', 'quote_marks',
            ];
            if (!str_contains($arg, '.') && !in_array($arg, $lazyFields, true)) {
                return static fn (stdClass $record): mixed => $record->$arg ?? null;
            }
            $parts = explode('.', $arg);
            if (
                count($parts) === 2
                && in_array(
                    $parts[0],
                    ['parent', 'owner', 'item_owner', 'line', 'last_line', 'prev', 'prev2', 'next', 'next2', 'item'],
                    true,
                )
                && !in_array($parts[1], $lazyFields, true)
            ) {
                [$outer, $inner] = $parts;

                return static function (stdClass $record) use ($outer, $inner): mixed {
                    $related = $record->$outer ?? null;

                    if (is_object($related)) {
                        return $related->$inner ?? null;
                    }

                    return is_array($related) ? ($related[$inner] ?? null) : null;
                };
            }

            return fn (stdClass $record): mixed => $this->field($arg, $record);
        }
        if ($op === 'has' && is_string($arg)) {
            return fn (stdClass $record): bool => $this->field($arg, $record) !== null;
        }
        if (($op === 'all' || $op === 'any') && is_array($arg)) {
            $items = array_map(fn (mixed $item): Closure => $this->compile($item, $let), array_values($arg));
            $all = $op === 'all';

            return static function (stdClass $record) use ($items, $all): bool {
                foreach ($items as $item) {
                    if (self::truthy($item($record)) !== $all) {
                        return !$all;
                    }
                }

                return $all;
            };
        }
        if ($op === 'not' && $arg !== null) {
            $inner = $this->compile($arg, $let);

            return static fn (stdClass $record): bool => !self::truthy($inner($record));
        }
        if (
            in_array($op, ['eq', 'ne', 'in', 'matches', 'starts', 'contains'], true)
            && is_array($arg) && array_key_exists(1, $arg)
        ) {
            $left = $this->compile($arg[0], $let);
            $right = $this->compile($arg[1], $let);
            if ($op === 'eq') {
                return static fn (stdClass $record): bool => $left($record) === $right($record);
            }
            if ($op === 'ne') {
                return static fn (stdClass $record): bool => $left($record) !== $right($record);
            }
            if ($op === 'starts') {
                return static function (stdClass $record) use ($left, $right): bool {
                    $a = $left($record);
                    $b = $right($record);

                    return is_string($a) && is_string($b) && str_starts_with($a, $b);
                };
            }
            if ($op === 'contains') {
                return static function (stdClass $record) use ($left, $right): bool {
                    $a = $left($record);
                    $b = $right($record);

                    return is_array($a) ? in_array($b, $a, true) : (is_string($a) && is_string($b) && str_contains($a, $b));
                };
            }
            if ($op === 'in') {
                return static fn (stdClass $record): bool => in_array($left($record), self::set($right($record)), true);
            }
            $flags = is_string($arg[2] ?? null) ? $arg[2] : '';
            $textExpr = $arg[0];
            if (is_string($arg[1])) {
                // A literal pattern needs one match for both its result and captured offsets.
                $pattern = self::pattern($arg[1], $flags);

                return function (stdClass $record) use ($left, $pattern, $textExpr): bool {
                    $value = $left($record);
                    if (
                        !is_string($value)
                        || self::matchOne($pattern, $value, $parts, PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL) !== 1
                    ) {
                        return false;
                    }
                    $this->saveGroups($parts, $this->textBase($textExpr, $record));

                    return true;
                };
            }

            return fn (stdClass $record): bool => $this->matches($left($record), $right($record), $flags, $textExpr, $record);
        }

        return $this->compileMore($op, $arg, $expr, $let);
    }

    /**
     * The rest of `compile()`: variables, lookups, lists, conditions and arithmetic.
     *
     * @param string|int $op
     * @param mixed $arg
     * @param array<mixed> $expr
     * @param array<mixed> $let
     *
     * @return \Closure(\stdClass): mixed
     */
    private function compileMore(string|int $op, mixed $arg, array $expr, array $let): Closure
    {
        if ($op === 'v' && is_string($arg)) {
            $inner = null;

            return function (stdClass $record) use ($arg, $let, &$inner): mixed {
                if (array_key_exists($arg, $this->letValues)) {
                    return $this->letValues[$arg];
                }
                $inner ??= $this->compile($let[$arg] ?? null, $let);
                $groups = $this->groups;
                $base = $this->base;
                $value = $inner($record);
                $this->groups = $groups;
                $this->base = $base;

                return $this->letValues[$arg] = $value;
            };
        }
        if ($op === 'match' && is_string($arg)) {
            $side = ($expr['at'] ?? null) === 'end' ? 1 : 0;

            return fn (stdClass $record): mixed => $this->groups[$arg][$side] ?? null;
        }
        if ($op === 'slice' && is_array($arg) && array_key_exists(1, $arg)) {
            $text = $this->compile($arg[0], $let);
            $from = $this->compile($arg[1], $let);
            $to = array_key_exists(2, $arg) ? $this->compile($arg[2], $let) : null;

            return static function (stdClass $record) use ($text, $from, $to): string {
                $value = $text($record);
                $start = $from($record);

                $end = $to === null ? null : self::integer($to($record));

                return is_string($value) ? self::slice($value, self::integer($start), $end) : '';
            };
        }
        if ($op === 'lookup' && is_array($arg)) {
            $site = self::lookupSite($arg, $let);
            $value = isset($arg['value']) ? $this->compile($arg['value'], $let) : null;

            return fn (stdClass $record): mixed => $this->lookup($arg, $record, $site, $value);
        }
        if ($op === 'if' && is_array($arg) && array_key_exists(2, $arg)) {
            $test = $this->compile($arg[0], $let);
            $then = $this->compile($arg[1], $let);
            $else = $this->compile($arg[2], $let);

            return static fn (stdClass $record): mixed => self::truthy($test($record)) ? $then($record) : $else($record);
        }
        if (($op === 'some' || $op === 'count') && is_array($arg) && is_string($arg['in'] ?? null)) {
            $in = $arg['in'];
            $from = is_int($arg['from'] ?? null) ? $arg['from'] : 0;
            $where = $this->compile($arg['where'] ?? null, $let);
            $some = $op === 'some';

            return function (stdClass $record) use ($in, $from, $where, $some): mixed {
                $list = $this->field($in, $record);
                if (!is_array($list)) {
                    return $some ? false : 0;
                }
                $found = 0;
                foreach (array_slice($list, $from) as $item) {
                    $copy = clone $record;
                    $copy->item = $item;
                    if ($where($copy)) {
                        if ($some) {
                            return true;
                        }
                        $found++;
                    }
                }

                return $some ? false : $found;
            };
        }
        if (
            in_array($op, ['lt', 'le', 'gt', 'ge', 'sub', 'add'], true)
            && is_array($arg) && array_key_exists(1, $arg)
        ) {
            $left = $this->compile($arg[0], $let);
            $right = $this->compile($arg[1], $let);
            if ($op === 'add') {
                $parts = array_map(fn (mixed $part): Closure => $this->compile($part, $let), array_values($arg));

                return static function (stdClass $record) use ($left, $right, $parts): int|float {
                    $a = $left($record);
                    $b = $right($record);
                    if ((is_float($a) && is_nan($a)) || (is_float($b) && is_nan($b))) {
                        return NAN;
                    }
                    $sum = 0;
                    foreach ($parts as $part) {
                        $sum += self::integer($part($record));
                    }

                    return $sum;
                };
            }

            return static function (stdClass $record) use ($op, $left, $right): mixed {
                $a = $left($record);
                $b = $right($record);

                return match ($op) {
                    'lt' => $a !== null && $b !== null && $a < $b,
                    'le' => $a !== null && $b !== null && $a <= $b,
                    'gt' => $a !== null && $b !== null && $a > $b,
                    'ge' => $a !== null && $b !== null && $a >= $b,
                    default => self::integer($a) - self::integer($b),
                };
            };
        }
        if ($op === 'len' && $arg !== null) {
            $inner = $this->compile($arg, $let);

            return static function (stdClass $record) use ($inner): int {
                $value = $inner($record);

                return is_string($value) ? strlen($value) : (is_array($value) ? count($value) : 0);
            };
        }

        return fn (stdClass $record): mixed => $this->value($expr, $record);
    }

    // The JSON expression grammar intentionally has one branch per supported operator.
    // phpcs:disable Generic.Metrics.CyclomaticComplexity.MaxExceeded

    private function value(mixed $expr, stdClass $record): mixed
    {
        if (!is_array($expr) || array_is_list($expr)) {
            return $expr;
        }
        $op = array_key_first($expr);
        if ($op === 'f' && is_string($expr['f'] ?? null)) {
            return $this->field($expr['f'], $record);
        }
        if ($op === 'v' && is_string($expr['v'] ?? null)) {
            $name = $expr['v'];
            if (array_key_exists($name, $this->letValues)) {
                return $this->letValues[$name];
            }
            $original = [$this->groups, $this->base];
            $value = $this->value($this->vars[$name] ?? null, $record);
            [$this->groups, $this->base] = $original;

            return $this->letValues[$name] = $value;
        }
        if ($op === 'match' && is_string($expr['match'] ?? null)) {
            return $this->groups[$expr['match']][($expr['at'] ?? null) === 'end' ? 1 : 0] ?? null;
        }
        if ($op === 'has' && is_string($expr['has'] ?? null)) {
            return $this->field($expr['has'], $record) !== null;
        }
        if ($op === 'lookup' && is_array($expr['lookup'] ?? null)) {
            return $this->lookup($expr['lookup'], $record);
        }
        if ($op === 'index_of' && is_array($expr['index_of'] ?? null)) {
            $parts = $expr['index_of'];
            $haystack = $this->value($parts[0] ?? null, $record);
            $needle = $this->value($parts[1] ?? null, $record);
            // C2-W-141, C2-W-150: the offset of a text in a text, or the index of an item in a list; -1 where there is none.
            if (is_array($haystack)) {
                $at = array_search($needle, $haystack, true);

                return is_int($at) ? $at : -1;
            }
            if (!is_string($haystack) || !is_string($needle)) {
                return -1;
            }
            $at = strpos($haystack, $needle);

            return $at === false ? -1 : $at;
        }
        if ($op === 'json_escape' && isset($expr['json_escape'])) {
            $encoded = json_encode(
                self::string($this->value($expr['json_escape'], $record)),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            );

            return substr($encoded, 1, -1);
        }
        if ($op === 'all' && is_array($expr['all'] ?? null)) {
            foreach ($expr['all'] as $item) {
                if (!self::truthy($this->value($item, $record))) {
                    return false;
                }
            }

            return true;
        }
        if ($op === 'any' && is_array($expr['any'] ?? null)) {
            foreach ($expr['any'] as $item) {
                if (self::truthy($this->value($item, $record))) {
                    return true;
                }
            }

            return false;
        }
        if ($op === 'not' && isset($expr['not'])) {
            return !self::truthy($this->value($expr['not'], $record));
        }
        if ($op === 'if' && is_array($expr['if'] ?? null) && array_key_exists(2, $expr['if'])) {
            return $this->value($expr['if'][self::truthy($this->value($expr['if'][0], $record)) ? 1 : 2], $record);
        }
        if ($op === 'some' && is_array($expr['some'] ?? null) && isset($expr['some']['in'])) {
            $list = $this->listOf($expr['some']['in'], $record);
            if (!is_array($list)) {
                return false;
            }
            $from = is_int($expr['some']['from'] ?? null) ? $expr['some']['from'] : 0;
            foreach (array_slice($list, $from) as $item) {
                $copy = clone $record;
                $copy->item = $item;
                if ($this->value($expr['some']['where'], $copy)) {
                    return true;
                }
            }

            return false;
        }
        if ($op === 'count' && is_array($expr['count'] ?? null) && isset($expr['count']['in'])) {
            $list = $this->listOf($expr['count']['in'], $record);
            if (!is_array($list)) {
                return 0;
            }
            $from = is_int($expr['count']['from'] ?? null) ? $expr['count']['from'] : 0;
            $found = 0;
            foreach (array_slice($list, $from) as $item) {
                $copy = clone $record;
                $copy->item = $item;
                if ($this->value($expr['count']['where'], $copy)) {
                    $found++;
                }
            }

            return $found;
        }
        if (
            is_string($op) && isset(self::BINARY_OPS[$op])
            && is_array($expr[$op]) && array_key_exists(1, $expr[$op])
        ) {
            $parts = $expr[$op];
            $a = $this->value($parts[0], $record);
            $b = $this->value($parts[1], $record);

            return match ($op) {
                'eq' => $a === $b, 'ne' => $a !== $b, 'lt' => $a !== null && $b !== null && $a < $b,
                'le' => $a !== null && $b !== null && $a <= $b,
                'gt' => $a !== null && $b !== null && $a > $b,
                'ge' => $a !== null && $b !== null && $a >= $b,
                'add' => ((is_float($a) && is_nan($a)) || (is_float($b) && is_nan($b))) ? NAN : array_reduce(
                    $parts,
                    fn (int|float $sum, mixed $part): int|float => $sum + self::integer($this->value($part, $record)),
                    0,
                ),
                'sub' => self::integer($a) - self::integer($b),
                'in' => in_array($a, self::set($b), true),
                'contains' => is_array($a) ? in_array($b, $a, true)
                    : (is_string($a) && is_string($b) && str_contains($a, $b)),
                'starts' => is_string($a) && is_string($b) && str_starts_with($a, $b),
                'matches' => $this->matches($a, $b, is_string($parts[2] ?? null) ? $parts[2] : '', $parts[0], $record),
                'concat' => implode('', array_map(
                    static fn (mixed $v): string => self::string($v),
                    array_map(fn (mixed $x): mixed => $this->value($x, $record), $parts),
                )),
                'slice' => is_string($a) ? self::slice($a, self::integer($b), isset($parts[2])
                    ? self::integer($this->value($parts[2], $record)) : null) : '',
                'extend_left' => $this->scan(self::integer($a), self::string($b), -1, $record),
                'skip_right' => $this->scan(self::integer($a), self::string($b), 1, $record),
            };
        }
        if (isset($expr['len'])) {
            $value = $this->value($expr['len'], $record);

            return is_string($value) ? strlen($value) : (is_array($value) ? count($value) : 0);
        }
        if (isset($expr['trim_end'])) {
            return rtrim(self::string($this->value($expr['trim_end'], $record)), " \t");
        }
        if (isset($expr['words'])) {
            return str_replace('_', ' ', self::string($this->value($expr['words'], $record)));
        }
        if (isset($expr['text_from'])) {
            $from = self::integer($this->value($expr['text_from'], $record));
            $line = $record->line->text ?? $record->text ?? '';
            $start = $record->line->start ?? $record->start ?? 0;

            return rtrim(substr($line, $from - $start), " \t");
        }
        $more = $this->valueMore($op, $expr, $record);

        return $more === self::NONE ? null : $more;
    }

    /**
     * The list of `some`, `count` and `find`: a field path, or a value (C2-W-150).
     */
    private function listOf(mixed $in, stdClass $record): mixed
    {
        return is_string($in) ? $this->field($in, $record) : $this->value($in, $record);
    }

    /**
     * The operators of C2-W-150 and C2-B-004 that the main evaluator does not take; NONE for another operator.
     *
     * @param string|int|null $op
     * @param array<mixed> $expr
     * @param \stdClass $record
     */
    private function valueMore(string|int|null $op, array $expr, stdClass $record): mixed
    {
        $arg = $expr[$op] ?? null;
        switch ($op) {
            case 'lower':
                return mb_strtolower(self::string($this->value($arg, $record)), 'UTF-8');
            case 'nfc':
                return self::nfc(self::string($this->value($arg, $record)));
            case 'fold':
                // C2-W-150: NFC, Unicode full case folding, NFC again.
                return self::nfc(mb_convert_case(self::nfc(self::string($this->value($arg, $record))), MB_CASE_FOLD, 'UTF-8'));
            case 'fold_space':
                return Resolver::fold(self::string($this->value($arg, $record)));
            case 'plain_list':
            case 'quoted_list':
                $items = $this->value($arg, $record);

                return is_array($items) ? self::listPhrase(array_map(self::display(...), $items), $op === 'quoted_list') : '';
            case 'percent_decode':
                return self::percentDecode(self::string($this->value($arg, $record)));
            case 'join':
                $list = is_array($arg) ? $this->value($arg[0] ?? null, $record) : null;
                $glue = is_array($arg) ? self::string($this->value($arg[1] ?? null, $record)) : '';

                return is_array($list) ? implode($glue, array_map(self::display(...), $list)) : '';
            case 'nth':
                $list = is_array($arg) ? $this->value($arg[0] ?? null, $record) : null;
                $index = is_array($arg) ? self::integer($this->value($arg[1] ?? null, $record)) : 0;
                if (!is_array($list)) {
                    return null;
                }
                if ($index < 0 && array_is_list($list)) {
                    $index += count($list);
                }

                return $list[$index] ?? null;
            case 'column':
                $offset = is_array($arg) ? self::integer($this->value($arg[0] ?? null, $record)) : 0;
                $quotes = is_array($arg) ? self::integer($this->value($arg[1] ?? null, $record)) : 0;

                return $this->context->layout->columnOf($offset, $quotes);
            case 'find':
                if (!is_array($arg) || !isset($arg['in'])) {
                    return null;
                }
                $list = $this->listOf($arg['in'], $record);
                if (!is_array($list)) {
                    return null;
                }
                foreach ($list as $item) {
                    $copy = clone $record;
                    $copy->item = $item;
                    if ($this->value($arg['where'] ?? null, $copy)) {
                        $field = $arg['field'] ?? null;

                        return is_string($field) ? $this->field($field, (object)$item) : $item;
                    }
                }

                return null;
            case 'run':
                return $arg === 'extensions' ? ($this->context->options['extensions'] ?? []) : null;
            case 'attribute_line':
                $text = self::string($this->value($arg, $record));

                return LineShapes::attributeLine($text, 0, strlen($text), new Attrs());
        }

        return self::NONE;
    }

    /**
     * C2-W-150: `%HH` decoded as UTF-8; a text in which that fails stays as written.
     */
    private static function percentDecode(string $text): string
    {
        if (preg_match('/%(?![0-9A-Fa-f]{2})/', $text) === 1) {
            return $text;
        }
        $decoded = rawurldecode($text);

        return mb_check_encoding($decoded, 'UTF-8') ? $decoded : $text;
    }

    /**
     * An item of a list as `join` writes it.
     */
    private static function display(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) => (string)$value,
            default => '',
        };
    }

    /**
     * @param array<mixed> $expr
     * @param \stdClass $record
     * @param string|null $site
     * @param \Closure|null $valueOf The compiled `value` of the lookup.
     */
    private function lookup(array $expr, stdClass $record, ?string $site = null, ?Closure $valueOf = null): mixed
    {
        $over = $expr['over'] ?? null;
        $types = is_array($expr['types'] ?? null) ? $expr['types'] : null;
        $field = is_string($expr['field'] ?? null) ? $expr['field'] : '';
        if (!in_array($over, ['block', 'inline', 'id', 'line'], true)) {
            return null;
        }
        $site ??= self::lookupSite($expr, $this->vars);
        if (!isset($this->lookupIndexes[$site])) {
            $index = [];
            $records = $over === 'line' ? $this->lineRecords() : $this->lookupRecords[$over];
            $simpleKey = is_array($expr['key'] ?? null) && count($expr['key']) === 1
                && is_string($expr['key']['f'] ?? null) && str_starts_with($expr['key']['f'], 'item.')
                ? substr($expr['key']['f'], 5) : null;
            $keyOf = $simpleKey === null && isset($expr['key'])
                ? ($this->compiledKeys[$site] ??= $this->compile($expr['key'], $this->vars)) : null;
            foreach ($records as $candidate) {
                $type = $candidate->type ?? null;
                // C2-W-150: a lookup with no types reads every block or every inline node; a text run is no node.
                if ($types === null ? $type === 'text_run' : ($over !== 'line' && !in_array($type, $types, true))) {
                    continue;
                }
                if ($simpleKey !== null) {
                    $key = $this->field($simpleKey, $candidate);
                } else {
                    $copy = clone $candidate;
                    $copy->item = $candidate;
                    $oldGroups = $this->groups;
                    $oldBase = $this->base;
                    $key = $keyOf === null ? null : $keyOf($copy);
                    $this->groups = $oldGroups;
                    $this->base = $oldBase;
                }
                $index[serialize($key)][] = $candidate;
            }
            $this->lookupIndexes[$site] = $index;
        }
        $value = $valueOf !== null ? $valueOf($record) : (isset($expr['value']) ? $this->value($expr['value'], $record) : null);
        $key = serialize($value);
        $list = $this->lookupIndexes[$site][$key] ?? [];
        // C2-W-150: with all, every record whose key equals the value, in document order.
        if (($expr['all'] ?? false) === true) {
            return array_map(fn (stdClass $found): mixed => $this->lookupField($field, $found), $list);
        }
        if ($list === []) {
            return NAN;
        }
        $from = isset($expr['from']) ? self::integer($this->value($expr['from'], $record)) : ($record->start ?? 0);
        $before = ($expr['before'] ?? false) === true;
        if ($before || ($expr['after'] ?? false) === true) {
            $starts = $this->lookupStarts[$site][$key] ??= self::startsInOrder($list);
            if ($starts !== false) {
                // The first record at or past `from` (before) or past it (after).
                $low = 0;
                $high = count($starts);
                while ($low < $high) {
                    $mid = ($low + $high) >> 1;
                    if ($before ? $starts[$mid] < $from : $starts[$mid] <= $from) {
                        $low = $mid + 1;
                    } else {
                        $high = $mid;
                    }
                }
                $chosen = $list[$before ? $low - 1 : $low] ?? null;
            } elseif ($before) {
                $chosen = null;
                foreach ($list as $candidate) {
                    if ($candidate->start >= $from) {
                        break;
                    }
                    $chosen = $candidate;
                }
            } else {
                $chosen = null;
                foreach ($list as $candidate) {
                    if ($candidate->start > $from) {
                        $chosen = $candidate;

                        break;
                    }
                }
            }
        } else {
            $chosen = $list[0];
        }
        if ($chosen === null) {
            return NAN;
        }

        return $this->lookupField($field, $chosen);
    }

    /**
     * @param list<\stdClass> $list
     *
     * @return array<int>|false
     */
    private static function startsInOrder(array $list): array|false
    {
        $starts = [];
        $last = PHP_INT_MIN;
        foreach ($list as $candidate) {
            $start = $candidate->start;
            if (!is_int($start) || $start < $last) {
                return false;
            }
            $starts[] = $last = $start;
        }

        return $starts;
    }

    /**
     * Lookups with the same source, type filter, key, and rule bindings use the same record index even if their returned
     * fields or selection positions differ.
     *
     * @param array<mixed> $expr
     * @param array<mixed> $vars
     */
    private static function lookupSite(array $expr, array $vars): string
    {
        return json_encode([
            $expr['over'] ?? null,
            $expr['types'] ?? null,
            $expr['key'] ?? null,
            $vars,
        ], JSON_THROW_ON_ERROR);
    }

    private function lookupField(string $field, stdClass $found): mixed
    {
        if ($field === '') {
            return $found;
        }
        if ($field === 'text' && property_exists($found, 'text') && $found->text === null) {
            $found->text = substr($this->context->source, $found->start, $found->end - $found->start);
        }

        return $this->field($field, $found);
    }

    /**
     * @return list<\stdClass>
     */
    private function lineRecords(): array
    {
        return $this->lookupRecords['line'];
    }

    private function matches(mixed $value, mixed $source, string $flags, mixed $textExpr, stdClass $record): bool
    {
        if (!is_string($value) || !is_string($source)) {
            return false;
        }
        $found = self::matchOne(self::pattern($source, $flags), $value, $parts, PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL);
        if ($found !== 1) {
            return false;
        }
        $this->saveGroups($parts, $this->textBase($textExpr, $record));

        return true;
    }

    private static function slice(string $text, int $from, ?int $to): string
    {
        $length = strlen($text);
        $from = max(0, min($length, $from < 0 ? $length + $from : $from));
        $to = $to === null ? $length : max(0, min($length, $to < 0 ? $length + $to : $to));

        return substr($text, $from, max(0, $to - $from));
    }

    private function scan(int $from, string $characters, int $direction, stdClass $record): int
    {
        $line = $record->line->text ?? $record->text ?? '';
        $length = strlen($line);
        $start = $record->line->start ?? $record->start ?? 0;
        $at = $from - $start;
        if ($direction < 0) {
            while ($at > 0 && str_contains($characters, $line[$at - 1])) {
                $at--;
            }
        } else {
            while ($at < $length && str_contains($characters, $line[$at])) {
                $at++;
            }
        }

        return $start + $at;
    }
}
