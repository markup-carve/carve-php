<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Lint;

/**
 * One diagnostic: a construct that parses cleanly but almost certainly does not
 * mean what the author intended.
 *
 * Mirrors the carve-js `LintWarning` shape so the two engines can report the
 * same findings in the same terms.
 */
class LintWarning
{
    public function __construct(
        /** 1-based line number. */
        public readonly int $line,
        /** 1-based column number. */
        public readonly int $column,
        /** Stable rule id, e.g. `markdown-strong-asterisks`. */
        public readonly string $rule,
        /** What was written, what it renders as, and what to write instead. */
        public readonly string $message,
        /** 0-based start offset in the source, inclusive. */
        public readonly int $start,
        /** 0-based end offset in the source, exclusive. */
        public readonly int $end,
    ) {
    }

    /**
     * @return array{line: int, column: int, rule: string, message: string, start: int, end: int}
     */
    public function toArray(): array
    {
        return [
            'line' => $this->line,
            'column' => $this->column,
            'rule' => $this->rule,
            'message' => $this->message,
            'start' => $this->start,
            'end' => $this->end,
        ];
    }
}
