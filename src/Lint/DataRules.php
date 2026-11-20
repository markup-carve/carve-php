<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Lint;

use stdClass;

final class DataRules
{
    /**
     * @var array<string, mixed>|null
     */
    private static ?array $data = null;

    /**
     * @var array<string, true>|null
     */
    private static ?array $ids = null;

    /**
     * @var array<string, mixed>
     */
    private array $vars = [];

    /**
     * @var array<string, array{0: int, 1: int}>
     */
    private array $groups = [];

    private int $base = 0;

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

    // phpcs:disable Generic.Files.LineLength.MaxExceeded

    /**
     * @return list<array{id: string, over: string, verbatim: array<mixed>, types: array<mixed>|null, when: mixed} & array<string, mixed>>
     */
    private static function rules(): array
    {
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

        return $rules;
    }

    // phpcs:enable Generic.Files.LineLength.MaxExceeded

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
                    ];
                }
            }
        }
        $apply = static function (stdClass $record, array $rules) use ($self): void {
            foreach ($rules as $rule) {
                if ($rule['types'] === null || in_array($record->type ?? null, $rule['types'], true)) {
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
        $acceptBlock = static function (string $type, string $parentType, string $itemOwnerType) use ($typedBlocks, $genericBlocks): bool {
            if (isset($typedBlocks[$type])) {
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
            static function (stdClass $record) use ($apply, $byKind): void {
                $apply($record, $byKind['inline']);
            },
            static function (stdClass $record) use ($apply, $byKind): void {
                $apply($record, $byKind['block']);
            },
            $acceptBlock,
        );
        foreach ($byKind['line'] as $rule) {
            $filter = $lineFilters[$rule['id']];
            foreach ($model->lines as $record) {
                if (
                    ($filter['leafs'] !== null && !isset($filter['leafs'][$record->leaf]))
                    || ($filter['shapes'] !== null && !isset($filter['shapes'][$record->shape]))
                ) {
                    continue;
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
        foreach ($matches as $match) {
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
        return '~' . str_replace('~', '\\~', $source) . '~' . $flags;
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
            $this->groups = $groups;
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
        $value = $record;
        foreach (explode('.', $path) as $part) {
            if (is_object($value)) {
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
        if (is_string($expr['f'] ?? null)) {
            return $this->field($expr['f'], $record);
        }
        if (is_string($expr['v'] ?? null)) {
            $name = $expr['v'];
            $original = [$this->groups, $this->base];
            $value = $this->value($this->vars[$name] ?? null, $record);
            [$this->groups, $this->base] = $original;

            return $value;
        }
        if (is_string($expr['match'] ?? null)) {
            return $this->groups[$expr['match']][($expr['at'] ?? null) === 'end' ? 1 : 0] ?? null;
        }
        if (is_string($expr['has'] ?? null)) {
            return $this->field($expr['has'], $record) !== null;
        }
        if (is_array($expr['all'] ?? null)) {
            foreach ($expr['all'] as $item) {
                if (!$this->value($item, $record)) {
                    return false;
                }
            }

            return true;
        }
        if (is_array($expr['any'] ?? null)) {
            foreach ($expr['any'] as $item) {
                if ($this->value($item, $record)) {
                    return true;
                }
            }

            return false;
        }
        if (isset($expr['not'])) {
            return !$this->value($expr['not'], $record);
        }
        if (is_array($expr['if'] ?? null) && array_key_exists(2, $expr['if'])) {
            return $this->value($expr['if'][$this->value($expr['if'][0], $record) ? 1 : 2], $record);
        }
        if (is_array($expr['some'] ?? null) && is_string($expr['some']['in'] ?? null)) {
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
        foreach (
            [
                'eq', 'ne', 'lt', 'le', 'gt', 'ge', 'add', 'sub', 'in', 'contains',
                'starts', 'matches', 'concat', 'slice', 'extend_left', 'skip_right',
            ] as $op
        ) {
            if (!is_array($expr[$op] ?? null) || !array_key_exists(1, $expr[$op])) {
                continue;
            }
            $parts = $expr[$op];
            $a = $this->value($parts[0], $record);
            $b = $this->value($parts[1], $record);

            return match ($op) {
                'eq' => $a === $b, 'ne' => $a !== $b, 'lt' => $a !== null && $b !== null && $a < $b,
                'le' => $a !== null && $b !== null && $a <= $b,
                'gt' => $a !== null && $b !== null && $a > $b,
                'ge' => $a !== null && $b !== null && $a >= $b,
                'add' => self::integer($a) + self::integer($b),
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
