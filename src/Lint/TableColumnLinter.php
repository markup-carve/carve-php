<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Lint;

class TableColumnLinter
{
    /**
     * @return list<\MarkupCarve\Carve\Lint\LintWarning>
     */
    public function lint(string $source): array
    {
        $warnings = [];
        $lines = preg_split('/\R/', $source) ?: [];
        foreach ($lines as $lineIndex => $line) {
            if (str_starts_with(ltrim($line), '|')) {
                if (preg_match_all('/(?:\||\|=)([<>~^v?]{1,2})(?![<>~^v?{\s])/', $line, $matches, PREG_OFFSET_CAPTURE)) {
                    foreach ($matches[1] as [$run, $offset]) {
                        $warnings[] = $this->warning($source, $lineIndex, $offset, strlen($run), 'table-alignment-run-padding', sprintf('The table alignment run "%s" has no terminating space, so it is literal cell content. Add a space after the run to make it alignment.', $run));
                    }
                }

                continue;
            }
            if (!str_contains($line, 'aligns=') && !str_contains($line, 'valigns=') && !str_contains($line, 'widths=')) {
                continue;
            }
            $next = $lines[$lineIndex + 1] ?? '';
            if (!str_starts_with(ltrim($next), '|')) {
                continue;
            }
            $columns = max(0, substr_count($next, '|') - 1);
            foreach (['aligns', 'valigns', 'widths'] as $key) {
                if (preg_match('/\b' . $key . '=(?:"([^"]*)"|([^\s}]+))/', $line, $match, PREG_OFFSET_CAPTURE) !== 1) {
                    continue;
                }
                $quoted = $match[1];
                $bare = $match[2] ?? ['', -1];
                $raw = $quoted[1] >= 0 ? $quoted[0] : $bare[0];
                $offset = strpos($line, $key);
                $values = explode(',', $raw);
                if (count($values) < $columns) {
                    $warnings[] = $this->warning($source, $lineIndex, (int)$offset, strlen($key), 'table-column-arity', sprintf('%s supplies %d column entries for a %d-column table; the unset tail is valid but may be accidental.', $key, count($values), $columns));
                }
                if ($key === 'widths' && array_sum(array_map(static fn (string $value): float => is_numeric(trim($value)) ? (float)trim($value) : 0.0, $values)) > 100) {
                    $warnings[] = $this->warning($source, $lineIndex, (int)$offset, strlen($key), 'table-width-total', 'The specified table column widths total more than 100%.');
                }
            }
            $header = $next;
            foreach (['aligns' => '[<>~]', 'valigns' => '[~^v]'] as $key => $sigil) {
                if (preg_match('/\b' . $key . '=/', $line, $attribute, PREG_OFFSET_CAPTURE) === 1 && preg_match('/\|=' . $sigil . '/', $header) === 1) {
                    $offset = $attribute[0][1];
                    $warnings[] = $this->warning($source, $lineIndex, $offset, strlen($key), 'table-column-overlap', 'A column supplies the same alignment axis both in the table and in a table attribute; the in-table marker wins.');
                }
            }
        }

        return $warnings;
    }

    private function warning(string $source, int $lineIndex, int $column, int $length, string $rule, string $message): LintWarning
    {
        $before = implode("\n", array_slice(preg_split('/\R/', $source) ?: [], 0, $lineIndex));
        $start = ($lineIndex === 0 ? 0 : strlen($before) + 1) + $column;

        return new LintWarning($lineIndex + 1, $column + 1, $rule, $message, $start, $start + $length);
    }
}
