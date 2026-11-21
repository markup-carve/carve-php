<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Lint;

use Closure;
use Generator;
use stdClass;

/**
 * @phpstan-type Rule array{
 *     id: string, over: string, verbatim: array<mixed>, types: array<mixed>|null, when: mixed, pre: array<mixed>, key: string,
 * } & array<string, mixed>
 */
final class DataRules
{
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

    /**
     * @var array<string, mixed>
     */
    private array $vars = [];

    /**
     * @var array<string, array{0: int, 1: int}>
     */
    private array $groups = [];

    private int $base = 0;

    /**
     * @var array{block: list<\stdClass>, inline: list<\stdClass>}
     */
    private array $lookupRecords = ['block' => [], 'inline' => []];

    /**
     * @var array<string, array<string, list<\stdClass>>>
     */
    private array $lookupIndexes = [];

    /**
     * @var array<string, \Closure(\stdClass): mixed> The compiled `when` of each rule, by rule kind and id.
     */
    private array $compiledWhen = [];

    private function __construct(private Context $context)
    {
    }

    public static function enabled(): bool
    {
        return getenv('CARVE_LINT_RULES') === 'data' && (self::data()['rules'] ?? []) !== [];
    }

    /**
     * @return array<string, true>
     */
    public static function ids(): array
    {
        if (self::$ids === null) {
            self::$ids = [];
            foreach (self::rules() as $rule) {
                self::$ids[$rule['id']] = true;
            }
        }

        return self::$ids;
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
        $raw = self::data()['rules'] ?? [];
        if (!is_array($raw)) {
            return [];
        }
        $rules = [];
        foreach ($raw as $rule) {
            if (
                is_array($rule) && is_string($rule['id'] ?? null)
                && in_array($rule['over'] ?? null, ['line', 'block', 'inline'], true)
            ) {
                $rule['verbatim'] = is_array($rule['verbatim'] ?? null) ? $rule['verbatim'] : [];
                $rule['types'] = is_array($rule['types'] ?? null) ? $rule['types'] : null;
                $rule['when'] = $rule['when'] ?? null;
                $over = (string)$rule['over'];
                $rule['pre'] = self::prefilter($rule['when'], $over);
                $rule['key'] = $over . ' ' . $rule['id'];
                $rules[] = $rule;
            }
        }

        return self::$rulesCache = $rules;
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
            !is_string($field) || str_contains($field, '.') || in_array($field, ['ancestors', 'ancestor_ids', 'node'], true)
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
        if (is_array($lookup) && is_array($lookup['types'] ?? null)) {
            $target = ($lookup['over'] ?? null) === 'block' ? 'block' : 'inline';
            foreach ($lookup['types'] as $type) {
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
            if (($key === 'f' || $key === 'has' || $key === 'in') && is_string($value)) {
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
            if (!array_key_exists($key, $source)) {
                return;
            }
            if ($depth === $last) {
                $into[$key] = $source[$key];

                return;
            }
            if (!is_array($source[$key])) {
                return;
            }
            if (!is_array($into[$key] ?? null)) {
                $into[$key] = [];
            }
            $source = $source[$key];
            $into = &$into[$key];
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
        $plan = [];
        foreach ($fields['node'] as $path => $_) {
            $plan[] = [$path === 'children', explode('.', (string)$path)];
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

    public static function run(Context $context): void
    {
        $data = self::data();
        if (self::rules() === []) {
            return;
        }
        $self = new self($context);
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
        $needBlocks = [];
        $needInline = [];
        $lookupRules = [];
        foreach (array_merge($byKind['line'], $byKind['block'], $byKind['inline']) as $rule) {
            self::lookupTypes($rule, $needBlocks, $needInline);
            $lookupRules[$rule['id']] = self::readsLookup($rule);
        }
        $deferredBlocks = [];
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
        $apply = static function (
            stdClass $record,
            string $kind,
            bool $lookups = false,
        ) use (
            $self,
            $typedRules,
            $untypedRules,
            $lookupRules,
        ): void {
            foreach ($typedRules[$kind][$record->type ?? ''] ?? [] as $rule) {
                if ($lookupRules[$rule['id']] === $lookups) {
                    $self->apply($rule, $record);
                }
            }
            foreach ($untypedRules[$kind] as $rule) {
                if ($lookupRules[$rule['id']] === $lookups) {
                    $self->apply($rule, $record);
                }
            }
        };
        $typedBlocks = [];
        $genericBlocks = [];
        foreach ($byKind['block'] as $rule) {
            if ($rule['types'] !== null) {
                foreach ($rule['types'] as $type) {
                    if (is_string($type)) {
                        $typedBlocks[$type] = true;
                    }
                }

                continue;
            }
            $genericBlocks[] = [
                'parents' => self::requiredValues($rule['when'], 'parent.type'),
                'itemOwners' => self::requiredValues($rule['when'], 'item_owner.type'),
            ];
        }
        $acceptBlock = static function (
            string $type,
            string $parentType,
            string $itemOwnerType,
        ) use (
            $typedBlocks,
            $genericBlocks,
            $needBlocks,
        ): bool {
            if (isset($typedBlocks[$type]) || isset($needBlocks[$type])) {
                return true;
            }
            foreach ($genericBlocks as $filter) {
                if (
                    ($filter['parents'] === null || isset($filter['parents'][$parentType]))
                    && ($filter['itemOwners'] === null || isset($filter['itemOwners'][$itemOwnerType]))
                ) {
                    return true;
                }
            }

            return false;
        };
        $model = new DataModel(
            $context,
            static function (stdClass $record) use ($apply, $self, $needInline): void {
                if (isset($needInline[$record->type ?? ''])) {
                    $self->lookupRecords['inline'][] = $record;
                }
                $apply($record, 'inline');
            },
            static function (stdClass $record) use ($self, $apply, $needBlocks, $typedRules, $lookupRules, &$deferredBlocks): void {
                if (isset($needBlocks[$record->type ?? ''])) {
                    $self->lookupRecords['block'][] = $record;
                }
                $apply($record, 'block');
                foreach ($typedRules['block'][$record->type ?? ''] ?? [] as $rule) {
                    if ($lookupRules[$rule['id']]) {
                        $deferredBlocks[] = $record;

                        break;
                    }
                }
            },
            $acceptBlock,
            isset($typedRules['inline']['text_run']),
        );
        foreach ($deferredBlocks as $record) {
            $apply($record, 'block', true);
        }
        $deferredBlocks = [];
        $verbatimLeaves = [];
        foreach (is_array($data['verbatim_leaves'] ?? null) ? $data['verbatim_leaves'] : [] as $leaf) {
            if (is_string($leaf) || is_int($leaf)) {
                $verbatimLeaves[$leaf] = true;
            }
        }
        foreach ($byKind['line'] as $rule) {
            $filter = $lineFilters[$rule['id']];
            foreach ($model->lines as $record) {
                if (
                    ($filter['leafs'] !== null && !isset($filter['leafs'][$record->leaf]))
                    || ($filter['shapes'] !== null && !isset($filter['shapes'][$record->shape]))
                ) {
                    continue;
                }
                foreach ($filter['texts'] as [$field, $operator, $literal]) {
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
    }

    /**
     * @param array<string, mixed> $rule
     * @param \stdClass $record
     */
    private function apply(array $rule, stdClass $record): void
    {
        $this->vars = is_array($rule['let'] ?? null) ? $rule['let'] : [];
        $this->groups = [];
        $this->base = 0;
        if (is_array($rule['pre'] ?? null) && $rule['pre'] !== [] && !self::passes($rule['pre'], $record)) {
            return;
        }
        if (
            isset($rule['when'])
            && !($this->compiledWhen[is_string($rule['key'] ?? null) ? $rule['key'] : '']
                ??= $this->compile($rule['when'], $this->vars))($record)
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
        preg_match_all(self::pattern($pattern), $value, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL);
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
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            if (isset($entry['when']) && !$this->value($entry['when'], $record)) {
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
            $this->context->report($rule['id'], $start, $end, $record->path ?? '', $values, $message);

            return;
        }
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
            $end = strpos($text, $mark, $start + $width + 1);
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

            yield [
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
            'pos' => $node['pos'] ?? null,
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
            if ($path === 'node' && isset($record->nodeSource) && $record->node === null) {
                $record->node = $this->compactNode($record->nodeSource);
            }
            if (($path === 'ancestors' || $path === 'ancestor_ids') && !property_exists($record, $path)) {
                $this->populateAncestors($record);
            }
            if ($path === 'text' && property_exists($record, 'text') && $record->text === null) {
                $record->text = substr($this->context->source, $record->start, $record->end - $record->start);
            }

            return $record->$path ?? null;
        }
        static $parts = [];
        $value = $record;
        foreach ($parts[$path] ??= explode('.', $path) as $part) {
            if (is_object($value)) {
                if (
                    $value instanceof stdClass && ($part === 'ancestors' || $part === 'ancestor_ids')
                    && !property_exists($value, $part)
                ) {
                    $this->populateAncestors($value);
                }
                if ($part === 'node' && $value instanceof stdClass && isset($value->nodeSource) && $value->node === null) {
                    $value->node = $this->compactNode($value->nodeSource);
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
            } else {
                return null;
            }
        }

        return $value;
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
        $record->ancestors = $types;
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
            if (!str_contains($arg, '.') && !in_array($arg, ['ancestors', 'ancestor_ids', 'text', 'node'], true)) {
                return static fn (stdClass $record): mixed => $record->$arg ?? null;
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
                    if (!$item($record) === $all) {
                        return !$all;
                    }
                }

                return $all;
            };
        }
        if ($op === 'not' && $arg !== null) {
            $inner = $this->compile($arg, $let);

            return static fn (stdClass $record): bool => !$inner($record);
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
                // A literal pattern: a test without captures settles the common miss.
                $pattern = self::pattern($arg[1], $flags);

                return function (stdClass $record) use ($left, $pattern, $textExpr): bool {
                    $value = $left($record);
                    if (!is_string($value) || preg_match($pattern, $value) !== 1) {
                        return false;
                    }
                    preg_match($pattern, $value, $parts, PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL);
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
                $inner ??= $this->compile($let[$arg] ?? null, $let);
                $groups = $this->groups;
                $base = $this->base;
                $value = $inner($record);
                $this->groups = $groups;
                $this->base = $base;

                return $value;
            };
        }
        if ($op === 'lookup' && is_array($arg)) {
            $site = json_encode($arg, JSON_THROW_ON_ERROR);

            return fn (stdClass $record): mixed => $this->lookup($arg, $record, $site);
        }
        if ($op === 'if' && is_array($arg) && array_key_exists(2, $arg)) {
            $test = $this->compile($arg[0], $let);
            $then = $this->compile($arg[1], $let);
            $else = $this->compile($arg[2], $let);

            return static fn (stdClass $record): mixed => $test($record) ? $then($record) : $else($record);
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
            $original = [$this->groups, $this->base];
            $value = $this->value($this->vars[$name] ?? null, $record);
            [$this->groups, $this->base] = $original;

            return $value;
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

            return is_string($haystack) && is_string($needle) ? strpos($haystack, $needle) : -1;
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
                if (!$this->value($item, $record)) {
                    return false;
                }
            }

            return true;
        }
        if ($op === 'any' && is_array($expr['any'] ?? null)) {
            foreach ($expr['any'] as $item) {
                if ($this->value($item, $record)) {
                    return true;
                }
            }

            return false;
        }
        if ($op === 'not' && isset($expr['not'])) {
            return !$this->value($expr['not'], $record);
        }
        if ($op === 'if' && is_array($expr['if'] ?? null) && array_key_exists(2, $expr['if'])) {
            return $this->value($expr['if'][$this->value($expr['if'][0], $record) ? 1 : 2], $record);
        }
        if ($op === 'some' && is_array($expr['some'] ?? null) && is_string($expr['some']['in'] ?? null)) {
            $list = $this->field($expr['some']['in'], $record);
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
        if ($op === 'count' && is_array($expr['count'] ?? null) && is_string($expr['count']['in'] ?? null)) {
            $list = $this->field($expr['count']['in'], $record);
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

        return null;
    }

    /**
     * @param array<mixed> $expr
     * @param \stdClass $record
     * @param string|null $site
     */
    private function lookup(array $expr, stdClass $record, ?string $site = null): mixed
    {
        $over = $expr['over'] ?? null;
        $types = $expr['types'] ?? null;
        $field = $expr['field'] ?? null;
        if (!in_array($over, ['block', 'inline'], true) || !is_array($types) || !is_string($field)) {
            return null;
        }
        $site ??= json_encode($expr, JSON_THROW_ON_ERROR);
        if (!isset($this->lookupIndexes[$site])) {
            $index = [];
            foreach ($this->lookupRecords[$over] as $candidate) {
                if (!in_array($candidate->type ?? null, $types, true)) {
                    continue;
                }
                $copy = clone $candidate;
                $copy->item = $candidate;
                $oldGroups = $this->groups;
                $oldBase = $this->base;
                $key = isset($expr['key']) ? $this->value($expr['key'], $copy) : null;
                $this->groups = $oldGroups;
                $this->base = $oldBase;
                $index[serialize($key)][] = $candidate;
            }
            $this->lookupIndexes[$site] = $index;
        }
        $value = isset($expr['value']) ? $this->value($expr['value'], $record) : null;
        $list = $this->lookupIndexes[$site][serialize($value)] ?? [];
        if ($list === []) {
            return NAN;
        }
        if (($expr['before'] ?? false) === true) {
            $chosen = null;
            foreach ($list as $candidate) {
                if ($candidate->start >= ($record->start ?? 0)) {
                    break;
                }
                $chosen = $candidate;
            }
        } elseif (($expr['after'] ?? false) === true) {
            $chosen = null;
            foreach ($list as $candidate) {
                if ($candidate->start > ($record->start ?? 0)) {
                    $chosen = $candidate;

                    break;
                }
            }
        } else {
            $chosen = $list[0];
        }
        if ($chosen === null) {
            return NAN;
        }
        if ($field === 'text' && property_exists($chosen, 'text') && $chosen->text === null) {
            $chosen->text = substr($this->context->source, $chosen->start, $chosen->end - $chosen->start);
        }

        return $this->field($field, $chosen);
    }

    private function matches(mixed $value, mixed $source, string $flags, mixed $textExpr, stdClass $record): bool
    {
        if (!is_string($value) || !is_string($source)) {
            return false;
        }
        $found = preg_match(self::pattern($source, $flags), $value, $parts, PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL);
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
