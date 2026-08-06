<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser;

/**
 * Whether a line-based prepass is inside a fenced code block.
 *
 * Both definition prepasses need it for the same reason: a `[^a]: note` or
 * `[r]: url` shown inside a ``` / ~~~ SAMPLE is code, and registering it makes
 * documenting the syntax change the prose around it. Getting that wrong is
 * silent in one direction only - the definition resolves somewhere far from the
 * sample - so the two prepasses must answer the question identically.
 *
 * The fence may be opened on a list MARKER line (`- ``` `) and closed by a line
 * carrying the item's indentation, so the opener is read after the marker is
 * stripped and the closer after the item's content column is removed. Missing
 * that left the footnote prepass reading a fenced sample's body as document
 * content (carve-php#761).
 */
class PrepassFenceTracker
{
    /**
     * @var string
     */
    public const INSIDE = 'inside';

    /**
     * @var string
     */
    public const CLOSED = 'closed';

    /**
     * @var string
     */
    public const LEFT = 'left';

    protected ?string $char = null;

    protected int $length = 0;

    protected int $contentColumn = 0;

    protected int $quoteDepth = 0;

    public function isOpen(): bool
    {
        return $this->char !== null;
    }

    /**
     * Feed the next raw line while a fence is OPEN.
     *
     * `self::INSIDE` and `self::CLOSED` both mean the line is the fence's, so a
     * prepass consumes it either way. `self::LEFT` means the line no longer
     * reaches the blockquote depth the fence was opened at - the region ended
     * with the quote, and the caller must go on reading this line normally.
     *
     * @return string One of INSIDE, CLOSED, LEFT.
     */
    public function advance(string $line): string
    {
        $closeLine = $this->atQuoteDepth($line);
        if ($closeLine === null) {
            $this->reset();

            return self::LEFT;
        }

        $deIndentedCloseLine = ContainerPrefix::atContentColumn($closeLine, $this->contentColumn) ?? $closeLine;

        if (
            preg_match('/^([`~]{3,})\s*$/', $deIndentedCloseLine, $closeMatch) !== 1
            || $closeMatch[1][0] !== $this->char
            || strlen($closeMatch[1]) < $this->length
        ) {
            return self::INSIDE;
        }

        $this->reset();

        return self::CLOSED;
    }

    /**
     * The line with exactly the fence's own blockquote markers removed, or null
     * when it never reaches that depth.
     *
     * Reading the closer at the depth the fence OPENED at is what keeps a
     * nested `> > ``` ` as quoted code content rather than the closer of
     * `> ``` `: stripping every marker ended the region there and let the
     * definitions under it register (carve-php#685).
     */
    protected function atQuoteDepth(string $line): ?string
    {
        $rest = $line;
        for ($depth = 0; $depth < $this->quoteDepth; $depth++) {
            $content = ContainerPrefix::looseQuoteContent($rest);
            if ($content === null) {
                return null;
            }
            $rest = $content;
        }

        return $rest;
    }

    protected function reset(): void
    {
        $this->char = null;
        $this->length = 0;
        $this->contentColumn = 0;
        $this->quoteDepth = 0;
    }

    /**
     * Feed the next raw line while NO fence is open; true when one opened here.
     *
     * @param string $line The raw line, container prefixes still attached.
     * @param int $contentColumn The content column of the item the line sits in.
     */
    public function opensOn(string $line, int $contentColumn): bool
    {
        $view = $this->fenceView($line, $contentColumn);
        $first = $view['line'][0] ?? '';
        if (($first !== '`' && $first !== '~') || preg_match('/^([`~]{3,})/', $view['line'], $openMatch) !== 1) {
            return false;
        }

        $this->char = $openMatch[1][0];
        $this->length = strlen($openMatch[1]);
        $this->contentColumn = $contentColumn;
        $this->quoteDepth = $view['quoteDepth'];

        return true;
    }

    /**
     * The line as the fence scanner must read it: blockquote markers and list
     * markers removed, then dedented by the item's content column.
     *
     * @return array{line: string, quoteDepth: int}
     */
    protected function fenceView(string $line, int $contentColumn): array
    {
        $fenceLine = $line;
        $quoteDepth = 0;
        do {
            $previousFenceLine = $fenceLine;
            $quoteContent = ContainerPrefix::looseQuoteContent($fenceLine);
            if ($quoteContent !== null) {
                $fenceLine = $quoteContent;
                $quoteDepth++;
            }
            $fenceLine = $this->stripListMarker($fenceLine);
        } while ($fenceLine !== $previousFenceLine);

        return [
            'line' => ContainerPrefix::atContentColumn($fenceLine, $contentColumn) ?? $fenceLine,
            'quoteDepth' => $quoteDepth,
        ];
    }

    protected function stripListMarker(string $line): string
    {
        $first = $line[0] ?? '';
        if (
            $first !== ' '
            && $first !== "\t"
            && $first !== '-'
            && $first !== '*'
            && ($first < '0' || $first > '9')
            && ($first < 'a' || $first > 'z')
            && ($first < 'A' || $first > 'Z')
        ) {
            return $line;
        }

        return preg_replace(
            '/^[ \t]*(?:[-*]|(?:[0-9]+|[ivxlcdm]+|[IVXLCDM]+|[a-z]|[A-Z])[.)])(?:\{[^}]*\})? +(?:\[[ xX\-_>?]\] +)?(?=\S)/',
            '',
            $line,
        ) ?? $line;
    }
}
