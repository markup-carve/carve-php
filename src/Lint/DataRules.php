<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Lint;

use Generator;
use stdClass;

/**
 * @phpstan-type Rule array{id: string, over: string, verbatim: array<mixed>, types: array<mixed>|null, when: mixed} & array<string, mixed>
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
                $rules[] = $rule;
            }
        }

        return self::$rulesCache = $rules;
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
     * @return array<string, true>
     */
    public static function nodeTypes(Context $context): array
    {
        $types = [];
        foreach (self::rules() as $rule) {
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

        foreach (self::rules() as $rule) {
            if (!$context->activeRule($rule['id'])) {
                continue;
            }
            $blocks = [];
            $inline = [];
            self::lookupTypes($rule, $blocks, $inline);
            foreach (array_keys($blocks + $inline) as $type) {
                $types[$type] = true;
            }
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
        $key = array_shift($parts);
        if ($key === null || !array_key_exists($key, $source)) {
            return;
        }
        if ($parts === []) {
            $target[$key] = $source[$key];

            return;
        }
        if (is_array($source[$key])) {
            if (!is_array($target[$key] ?? null)) {
                $target[$key] = [];
            }
            self::copyField($source[$key], $parts, $target[$key]);
        }
    }

    /**
     * @param array<string, mixed> $node
     *
     * @return array<string, mixed>
     */
    public static function nodeData(array $node): array
    {
        $fields = self::$nodeFields;
        if ($fields === null) {
            $fields = ['node' => [], 'item' => []];
            foreach (self::rules() as $rule) {
                self::gatherFields($rule, $fields);
            }
            self::$nodeFields = $fields;
        }
        $out = [];
        foreach ($fields['node'] as $path => $_) {
            if ($path === 'children') {
                $out['children'] = [];
                foreach (is_array($node['children'] ?? null) ? $node['children'] : [] as $child) {
                    $item = [];
                    if (is_array($child)) {
                        foreach ($fields['item'] as $itemPath => $unused) {
                            self::copyField($child, explode('.', (string)$itemPath), $item);
                        }
                    }
                    $out['children'][] = $item;
                }

                continue;
            }
            self::copyField($node, explode('.', $path), $out);
        }

        return $out;
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
                if (
                    in_array($record->leaf, is_array($data['verbatim_leaves'] ?? null) ? $data['verbatim_leaves'] : [], true)
                    && !in_array($record->leaf, $rule['verbatim'], true)
                ) {
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
        if (isset($rule['when']) && !$this->value($rule['when'], $record)) {
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

        return $cache[$key] ??= '~' . $escaped . '~' . $flags;
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

    private function field(string $path, stdClass $record): mixed
    {
        if (!str_contains($path, '.')) {
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
                    $value instanceof stdClass && $part === 'text' && property_exists($value, 'text')
                    && $value->text === null && isset($value->start, $value->end)
                ) {
                    $value->text = substr($this->context->source, $value->start, $value->end - $value->start);
                }
                $value = $value->$part ?? null;
            } elseif (is_array($value)) {
                $value = $value[$part] ?? null;
            } else {
                return null;
            }
        }

        return $value;
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
     */
    private function lookup(array $expr, stdClass $record): mixed
    {
        $over = $expr['over'] ?? null;
        $types = $expr['types'] ?? null;
        $field = $expr['field'] ?? null;
        if (!in_array($over, ['block', 'inline'], true) || !is_array($types) || !is_string($field)) {
            return null;
        }
        $site = json_encode($expr, JSON_THROW_ON_ERROR);
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
