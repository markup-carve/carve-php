<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Converter\Markdown;

use Closure;

/**
 * Tracks the next nonblank quote line and which quote prefixes have held text.
 */
final class QuoteBlankRun
{
    private int $end = -1;

    private bool $futureContent = false;

    private string $prefix = '';

    /**
     * Quote prefixes that have held text.
     *
     * @var array<string, true>
     */
    private array $contentAt = [];

    /**
     * Use the converter's marker normalizer.
     *
     * @param \Closure(string): string $normalize
     */
    public function __construct(private readonly Closure $normalize)
    {
    }

    /**
     * Whether the next nonblank line is still quoted.
     *
     * @param array<int, string> $lines
     * @param int $index
     * @param int $lineCount
     * @param string $prefix
     */
    public function hasFutureQuote(array $lines, int $index, int $lineCount, string $prefix): bool
    {
        if ($index >= $this->end || $this->prefix !== $prefix) {
            $this->prefix = $prefix;
            $this->end = $index + 1;
            while ($this->end < $lineCount) {
                $candidate = ($this->normalize)(ltrim($lines[$this->end], ' '));
                if (!str_starts_with($candidate, $prefix) || trim(substr($candidate, strlen($prefix))) !== '') {
                    break;
                }
                $this->end++;
            }
            $candidate = $this->end < $lineCount ? ($this->normalize)(ltrim($lines[$this->end], ' ')) : '';
            $this->futureContent = str_starts_with($candidate, '>');
        }

        return $this->futureContent;
    }

    public function endsAfter(int $index): bool
    {
        return $index + 1 === $this->end;
    }

    public function hasContentAt(string $prefix): bool
    {
        return isset($this->contentAt[$prefix]);
    }

    public function markContentAt(string $prefix): void
    {
        $this->contentAt[$prefix] = true;
    }

    public function resetContent(): void
    {
        $this->contentAt = [];
    }
}
