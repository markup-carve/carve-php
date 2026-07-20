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
     */
    public function __construct(
        protected string $message,
        protected int $line,
        protected int $column = 1,
        protected ?string $category = null,
        protected ?string $suggestion = null,
        protected ?string $file = null,
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

    /**
     * @return array{message: string, line: int, column: int, category: string|null, suggestion: string|null, file: string|null}
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
