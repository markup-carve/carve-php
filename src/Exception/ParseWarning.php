<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Exception;

/**
 * Represents a non-fatal parsing warning
 */
class ParseWarning
{
    /**
     * @param string $message
     * @param int $line
     * @param int $column
     * @param string|null $category
     * @param string|null $suggestion
     * @param string|null $file Identity of the file the warning arose in: the
     *   canonical id a resolver returned for that file, or the directive path
     *   when the resolver supplied none. Null when there is no identity to
     *   report - a top-level document parsed from a string has no path, and
     *   none is invented for it.
     * @param string|null $detail Untrusted supplementary text from outside the
     *   processor, such as a host resolver's exception message. Kept OFF the
     *   message so it is never rendered by default: resolver errors commonly
     *   embed absolute paths, which would disclose host filesystem layout in a
     *   hosted preview. A host that controls its own resolver may opt in.
     * @param string|null $rule Stable, host-independent identifier for the
     *   condition that raised the warning (e.g. `include-unresolved`). Unlike
     *   `message`, which is human-worded prose, this is the machine-readable
     *   contract a tool keys on - notably the cross-engine include-conformance
     *   suite, which asserts rule ids rather than wording.
     */
    public function __construct(
        protected string $message,
        protected int $line,
        protected int $column = 1,
        protected ?string $category = null,
        protected ?string $suggestion = null,
        protected ?string $file = null,
        protected ?string $detail = null,
        protected ?string $rule = null,
    ) {
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getLine(): int
    {
        return $this->line;
    }

    public function getColumn(): int
    {
        return $this->column;
    }

    public function getCategory(): ?string
    {
        return $this->category;
    }

    public function getSuggestion(): ?string
    {
        return $this->suggestion;
    }

    public function getFile(): ?string
    {
        return $this->file;
    }

    public function getDetail(): ?string
    {
        return $this->detail;
    }

    public function getRule(): ?string
    {
        return $this->rule;
    }

    /**
     * @return array{message: string, line: int, column: int, category: string|null, suggestion: string|null, file: string|null, detail: string|null, rule: string|null}
     */
    public function toArray(): array
    {
        return [
            'message' => $this->message,
            'line' => $this->line,
            'column' => $this->column,
            'category' => $this->category,
            'suggestion' => $this->suggestion,
            'file' => $this->file,
            'detail' => $this->detail,
            'rule' => $this->rule,
        ];
    }

    public function __toString(): string
    {
        $str = sprintf('%s at line %d, column %d', $this->message, $this->line, $this->column);
        if ($this->category !== null) {
            $str = "[{$this->category}] " . $str;
        }

        return $str;
    }
}
