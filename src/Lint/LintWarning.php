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
    /**
     * @param int $line
     * @param int $column
     * @param string $rule
     * @param string $message
     * @param int $start
     * @param int $end
     * @param string|null $file
     * @param list<array{file: string, line: int, column: int}>|null $includedBy
     */
    public function __construct(
        public readonly int $line,
        public readonly int $column,
        public readonly string $rule,
        public readonly string $message,
        public readonly int $start,
        public readonly int $end,
        public readonly ?string $file = null,
        public readonly ?array $includedBy = null,
    ) {
    }

    /**
     * @return array{line: int, column: int, rule: string, message: string, start: int, end: int}
     */
    public function toArray(): array
    {
        $result = [
            'line' => $this->line,
            'column' => $this->column,
            'rule' => $this->rule,
            'message' => $this->message,
            'start' => $this->start,
            'end' => $this->end,
        ];
        if ($this->file !== null) {
            $result['file'] = $this->file;
        }
        if ($this->includedBy !== null) {
            $result['includedBy'] = $this->includedBy;
        }

        return $result;
    }
}
