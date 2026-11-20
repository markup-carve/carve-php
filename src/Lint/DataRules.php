<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Lint;

use stdClass;

/** An interpreter for the C2-W-141 expressions in the pinned lint model. */
final class DataRules
{
    /** @var array<string, mixed>|null */
    private static ?array $data = null;

    /** @var array<string, mixed> */
    private array $vars = [];

    /** @var array<string, array{0: int, 1: int}> */
    private array $groups = [];

    private int $base = 0;

    private function __construct(private Context $context, private DataModel $model)
    {
    }

    public static function enabled(): bool
    {
        return getenv('CARVE_LINT_RULES') === 'data' && (self::data()['rules'] ?? []) !== [];
    }

    /** @return array<string, true> */
    public static function ids(): array
    {
        $out = [];
        foreach (self::data()['rules'] ?? [] as $rule) {
            $out[$rule['id']] = true;
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private static function data(): array
    {
        if (self::$data === null) {
            $file = dirname(__DIR__, 2) . '/spec/data/lint-model.json';
            self::$data = is_file($file) ? json_decode((string)file_get_contents($file), true, 512, JSON_THROW_ON_ERROR) : [];
        }

        return self::$data;
    }

    public static function run(Context $context): void
    {
        $data = self::data();
        if (($data['rules'] ?? []) === []) {
            return;
        }
        $model = new DataModel($context);
        $self = new self($context, $model);
        foreach ($data['rules'] as $rule) {
            if (!$context->active($rule['id'])) {
                continue;
            }
            $records = match ($rule['over']) {
                'line' => $model->lines,
                'block' => $model->blocks,
                'inline' => $model->inline,
            };
            foreach ($records as $record) {
                if (isset($rule['types']) && !in_array($record->type ?? null, $rule['types'], true)) {
                    continue;
                }
                if ($rule['over'] === 'line' && in_array($record->leaf, $data['verbatim_leaves'], true)
                    && !in_array($record->leaf, $rule['verbatim'] ?? [], true)) {
                    continue;
                }
                $self->apply($rule, $record);
            }
        }
    }

    /** @param array<string, mixed> $rule */
    private function apply(array $rule, stdClass $record): void
    {
        $this->vars = $rule['let'] ?? [];
        $this->groups = [];
        $this->base = 0;
        if (isset($rule['when']) && !$this->value($rule['when'], $record)) {
            return;
        }
        if (!isset($rule['each'])) {
            $this->report($rule, $record);

            return;
        }
        [$text, $pattern] = $rule['each']['matches'];
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

    /** @param array<string, mixed> $rule */
    private function report(array $rule, stdClass $record): void
    {
        foreach ($rule['report'] as $entry) {
            if (isset($entry['when']) && !$this->value($entry['when'], $record)) {
                continue;
            }
            $start = $this->value($entry['start'] ?? $rule['range']['start'] ?? ['f' => 'start'], $record);
            $end = $this->value($entry['end'] ?? $rule['range']['end'] ?? ['f' => 'end'], $record);
            if (!is_int($start) || !is_int($end)) {
                return;
            }
            $values = [];
            foreach (array_merge($rule['values'] ?? [], $entry['values'] ?? []) as $name => $expr) {
                $value = $this->value($expr, $record);
                $values[$name] = is_int($value) || is_string($value) ? $value : '';
            }
            $this->context->report($rule['id'], $start, $end, $record->path ?? '', $values, $entry['message'] ?? 0);

            return;
        }
    }

    private static function pattern(string $source, string $flags = ''): string
    {
        return '~' . str_replace('~', '\\~', $source) . '~' . $flags;
    }

    /** @param array<string, mixed> $match */
    private function saveGroups(array $match, int $base): void
    {
        $groups = [];
        foreach ($match as $key => $part) {
            if (!is_string($key) || !is_array($part) || $part[1] < 0 || $part[0] === null) {
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
            return (int)$this->value($expr['text_from'], $record);
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

    private function value(mixed $expr, stdClass $record): mixed
    {
        if (!is_array($expr) || array_is_list($expr)) {
            return $expr;
        }
        if (isset($expr['f'])) {
            return $this->field($expr['f'], $record);
        }
        if (isset($expr['v'])) {
            $name = $expr['v'];
            $original = [$this->groups, $this->base];
            $value = $this->value($this->vars[$name] ?? null, $record);
            [$this->groups, $this->base] = $original;

            return $value;
        }
        if (isset($expr['match'])) {
            return $this->groups[$expr['match']][($expr['at'] ?? null) === 'end' ? 1 : 0] ?? null;
        }
        if (isset($expr['has'])) {
            return $this->field($expr['has'], $record) !== null;
        }
        if (isset($expr['all'])) {
            foreach ($expr['all'] as $item) {
                if (!$this->value($item, $record)) {
                    return false;
                }
            }

            return true;
        }
        if (isset($expr['any'])) {
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
        if (isset($expr['if'])) {
            return $this->value($expr['if'][$this->value($expr['if'][0], $record) ? 1 : 2], $record);
        }
        if (isset($expr['some'])) {
            $list = $this->field($expr['some']['in'], $record);
            if (!is_array($list)) {
                return false;
            }
            foreach (array_slice($list, $expr['some']['from'] ?? 0) as $item) {
                $copy = clone $record;
                $copy->item = $item;
                if ($this->value($expr['some']['where'], $copy)) {
                    return true;
                }
            }

            return false;
        }
        foreach (['eq', 'ne', 'lt', 'le', 'gt', 'ge', 'add', 'sub', 'in', 'contains', 'starts', 'matches', 'concat', 'slice', 'extend_left', 'skip_right'] as $op) {
            if (!isset($expr[$op])) {
                continue;
            }
            $parts = $expr[$op];
            $a = $this->value($parts[0], $record);
            $b = $this->value($parts[1], $record);
            return match ($op) {
                'eq' => $a === $b, 'ne' => $a !== $b, 'lt' => $a !== null && $b !== null && $a < $b,
                'le' => $a !== null && $b !== null && $a <= $b,
                'gt' => $a !== null && $b !== null && $a > $b,
                'ge' => $a !== null && $b !== null && $a >= $b, 'add' => (int)$a + (int)$b,
                'sub' => (int)$a - (int)$b,
                'in' => in_array($a, is_array($b) ? $b : (self::data()[$b] ?? []), true),
                'contains' => is_array($a) ? in_array($b, $a, true) : (is_string($a) && is_string($b) && str_contains($a, $b)),
                'starts' => is_string($a) && is_string($b) && str_starts_with($a, $b),
                'matches' => $this->matches($a, $b, $parts[2] ?? '', $parts[0], $record),
                'concat' => implode('', array_map(static fn (mixed $v): string => (string)$v, array_map(fn (mixed $x): mixed => $this->value($x, $record), $parts))),
                'slice' => is_string($a) ? self::slice($a, (int)$b, isset($parts[2]) ? (int)$this->value($parts[2], $record) : null) : '',
                'extend_left' => $this->scan((int)$a, (string)$b, -1, $record),
                'skip_right' => $this->scan((int)$a, (string)$b, 1, $record),
            };
        }
        if (isset($expr['len'])) {
            $value = $this->value($expr['len'], $record);

            return is_string($value) ? strlen($value) : (is_array($value) ? count($value) : 0);
        }
        if (isset($expr['trim_end'])) {
            return rtrim((string)$this->value($expr['trim_end'], $record), " \t");
        }
        if (isset($expr['words'])) {
            return str_replace('_', ' ', (string)$this->value($expr['words'], $record));
        }
        if (isset($expr['text_from'])) {
            $from = (int)$this->value($expr['text_from'], $record);
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
        $start = $record->line->start ?? $record->start ?? 0;
        $at = $from - $start;
        if ($direction < 0) {
            while ($at > 0 && str_contains($characters, $line[$at - 1])) {
                $at--;
            }
        } else {
            while ($at < strlen($line) && str_contains($characters, $line[$at])) {
                $at++;
            }
        }

        return $start + $at;
    }
}
