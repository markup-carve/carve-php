<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser;

/**
 * Document lines for block recognition, mapped to bytes in the original source.
 *
 * @internal
 */
final class SourceLines
{
    public readonly string $normalized;

    public readonly string $original;

    /**
     * @var list<string>
     */
    public readonly array $lines;

    /**
     * @var list<int>
     */
    public readonly array $byteLineStarts;

    public function __construct(string $input, string $original)
    {
        $this->normalized = str_replace(["\r\n", "\r"], "\n", $input);
        $this->lines = explode("\n", $this->normalized);
        $this->original = $original !== '' ? $original : $this->normalized;

        // Recognition uses normalized lines, but spans count the original BOM
        // and line endings at their actual byte widths (PART 12 section 4).
        $offset = str_starts_with($this->original, "\u{FEFF}") ? 3 : 0;
        $starts = [];
        foreach ($this->lines as $line) {
            $starts[] = $offset;
            $offset += strlen($line);
            $ending = substr($this->original, $offset, 2);
            $offset += str_starts_with($ending, "\r\n") ? 2 : 1;
        }
        $this->byteLineStarts = $starts;
    }

    /**
     * The terminal newline ends the last document line. Keep empty lines that
     * precede it; container collectors must not trim their own trailing blanks.
     *
     * @return list<string>
     */
    public function blockLines(): array
    {
        $lines = $this->lines;
        if (end($lines) === '') {
            array_pop($lines);
        }

        return $lines;
    }
}
