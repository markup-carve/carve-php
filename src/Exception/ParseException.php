<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Exception;

use Exception;

/**
 * Exception thrown when parsing fails in strict mode
 */
class ParseException extends Exception
{
    protected int $sourceLine;

    protected int $sourceColumn;

    protected string $reason;

    public function __construct(
        string $message,
        int $sourceLine,
        int $sourceColumn = 1,
        ?Exception $previous = null,
    ) {
        $this->sourceLine = $sourceLine;
        $this->sourceColumn = $sourceColumn;
        $this->reason = $message;

        $fullMessage = sprintf('%s at line %d, column %d', $message, $sourceLine, $sourceColumn);
        parent::__construct($fullMessage, 0, $previous);
    }

    public function getSourceLine(): int
    {
        return $this->sourceLine;
    }

    public function getSourceColumn(): int
    {
        return $this->sourceColumn;
    }

    /**
     * The same failure reported on another source line.
     */
    public function atLine(int $sourceLine): self
    {
        return new self($this->reason, $sourceLine, $this->sourceColumn, $this);
    }
}
