<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser;

use MarkupCarve\Carve\Parser\Block\FencedBlockParser;
use MarkupCarve\Carve\Parser\Block\ListParser;
use MarkupCarve\Carve\Parser\Utility\IndentationHelper;
use MarkupCarve\Carve\Parser\Utility\LayoutWork;

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
 *
 * WHAT AN OPENER IS is asked of the block parser and not spelled again here.
 * This tracker matched the fence RUN alone, so it opened a region on lines the
 * block parser reads as prose - and a region it opens has no closer ahead,
 * because the block parser never opened one to close. Every definition below
 * such a line then stopped being collected while still being consumed, so the
 * author's line rendered nowhere and defined nothing (carve-php#1348).
 *
 * The line PART 7's separator rule divides is the one that showed it:
 *
 * ```
 * ```<TAB>php
 * [r]: /u
 *
 * see [t][r]
 * ```
 *
 * A tab before content is a SEPARATOR, and the slot is a literal space it
 * cannot satisfy (markup-carve/carve#1295), so the fence does not open in any
 * engine - but this tracker opened one and swallowed `[r]: /u`.
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

    protected FencedBlockParser $fencedBlockParser;

    protected ListParser $listParser;

    public function __construct(?FencedBlockParser $fencedBlockParser = null)
    {
        $this->fencedBlockParser = $fencedBlockParser ?? new FencedBlockParser();
        $this->listParser = new ListParser();
    }

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
        $closer = $this->atQuoteDepth($line);
        if ($closer === null) {
            $this->reset();

            return self::LEFT;
        }

        // Whatever the prefix walk did NOT spend is the closer's own
        // indentation inside the innermost quote. Asking for the whole content
        // column again here would ask a second time for the columns the quote
        // markers already paid (markup-carve/carve-php#1431).
        $deIndentedCloseLine = ContainerPrefix::atContentColumn($closer['line'], $closer['budget']) ?? $closer['line'];

        if (
            // The closer INDEX and the closer TESTS spell the same class, so the
            // index stays a superset of what the tests match - by being EQUAL to
            // them rather than wider. Both are PART 7's four characters: a VERTICAL
            // TAB after a fence is CONTENT, so the line is not a closer here or
            // there (markup-carve/carve#963).
            preg_match('/^([`~]{3,})[ \t]*$/', $deIndentedCloseLine, $closeMatch) !== 1
            || $closeMatch[1][0] !== $this->char
            || strlen($closeMatch[1]) < $this->length
        ) {
            return self::INSIDE;
        }

        $this->reset();

        return self::CLOSED;
    }

    /**
     * The line with exactly the fence's own blockquote markers removed, and the
     * part of the content column those markers did not spend - or null when the
     * line never reaches that depth.
     *
     * Reading the closer at the depth the fence OPENED at is what keeps a
     * nested `> > ``` ` as quoted code content rather than the closer of
     * `> ``` `: stripping every marker ended the region there and let the
     * definitions under it register (carve-php#685).
     *
     * THE COLUMN IS A BUDGET, SPENT ACROSS THE WHOLE PREFIX. A marker inside a
     * list item sits at the item's content column rather than at position 0
     * (a quoted fence written at an item's content column), so the indentation
     * in front of it is spent from the column here instead of ending the walk - exactly the column, never
     * arbitrary indentation (markup-carve/carve-php#1431).
     *
     * @return array{line: string, budget: int}|null
     */
    protected function atQuoteDepth(string $line): ?array
    {
        $rest = $line;
        $budget = $this->contentColumn;
        for ($depth = 0; $depth < $this->quoteDepth; $depth++) {
            $trimmed = ltrim($rest, " \t");
            $spend = min(strlen($rest) - strlen($trimmed), $budget);
            $rest = substr($rest, $spend);
            $budget -= $spend;
            $content = ContainerPrefix::quoteContent($rest);
            if ($content === null) {
                return null;
            }
            $budget = max(0, $budget - (strlen($rest) - strlen($content)));
            $rest = $content;
        }

        return ['line' => $rest, 'budget' => $budget];
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
        if ($first !== '`' && $first !== '~') {
            return false;
        }

        // THE BLOCK PARSER DECIDES. A raw block hides its body the same way a
        // code block does, so both openers count - and nothing else does. The
        // fence RUN alone used to be the whole test, which admitted every line
        // the info-string rules refuse: ```` ```<TAB>php ````, ```` ```<SP><SP>php ````,
        // ```` ```=html<TAB>x ````. Each opened a region here that the block
        // parser never opened, and a region with no closer ahead runs to the end
        // of the document.
        $opener = $this->fencedBlockParser->parseCodeFenceOpener($view['line'])
            ?? $this->fencedBlockParser->parseRawBlockOpener($view['line']);
        if ($opener === null) {
            return false;
        }

        $this->char = $opener['fence'][0];
        $this->length = $opener['length'];
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
        $length = strlen($line);
        $newline = strpos($line, "\n");
        if ($newline !== false && $newline !== $length - 1) {
            return $this->fenceViewFromCopies($line, $contentColumn);
        }

        $quoteDepth = 0;
        // THE COLUMN IS A BUDGET here too, and for the same reason the closer
        // spends one {@see self::atQuoteDepth()}: inside `> - a` the fence's
        // own line writes `>   ``` `, where two of the four columns are the
        // quote marker. Dedenting by the whole column AFTER the markers came
        // off asked for those two a second time, found two columns of
        // indentation and left the residual `  ``` ` - which opens no fence, so
        // a definition inside the SAMPLE was collected as a real one
        // (markup-carve/carve-php#1431).
        $budget = $contentColumn;
        $at = 0;
        do {
            $previousAt = $at;

            $whitespaceAt = IndentationHelper::pastLeadingWhitespace($line, $at);
            $spend = min($whitespaceAt - $at, $budget);
            if ($spend > 0) {
                $at += $spend;
                $budget -= $spend;
            }

            $quoteWidth = ContainerPrefix::quoteMarkerWidth($line, $at);
            if ($quoteWidth !== null) {
                $budget = max(0, $budget - $quoteWidth);
                $at += $quoteWidth;
                $quoteDepth++;
            }

            $head = $this->listParser->markerHeadAt(
                $line,
                IndentationHelper::pastLeadingWhitespace($line, $at),
            );
            if ($head !== null) {
                $budget = max(0, $budget - ($head['content'] - $at));
                $at = $head['content'];
            }
        } while ($at !== $previousAt);

        // No trailing dedent: the loop exits only when the budget is spent or
        // the line has no indentation left, so there is nothing a dedent here
        // could still remove. One that asked for the whole column again was
        // what broke a quoted item's fence in the first place, and one that
        // asks for the REMAINDER cannot fire at all - a check that cannot fail
        // is worse than none (markup-carve/carve#755).
        if (LayoutWork::$on) {
            LayoutWork::$fencePrescan += $length - $at;
        }

        return ['line' => substr($line, $at), 'quoteDepth' => $quoteDepth];
    }

    /**
     * The exact capturing-parser fallback for a subject with an interior newline.
     *
     * @return array{line: string, quoteDepth: int}
     */
    private function fenceViewFromCopies(string $line, int $contentColumn): array
    {
        $fenceLine = $line;
        $quoteDepth = 0;
        $budget = $contentColumn;
        do {
            $previousFenceLine = $fenceLine;
            $trimmed = ltrim($fenceLine, " \t");
            $spend = min(strlen($fenceLine) - strlen($trimmed), $budget);
            if ($spend > 0) {
                $fenceLine = substr($fenceLine, $spend);
                $budget -= $spend;
            }
            $quoteContent = ContainerPrefix::quoteContent($fenceLine);
            if ($quoteContent !== null) {
                $budget = max(0, $budget - (strlen($fenceLine) - strlen($quoteContent)));
                $fenceLine = $quoteContent;
                $quoteDepth++;
            }
            $marker = $this->listParser->parseListItemMarker($fenceLine);
            if ($marker !== null) {
                $content = (string)$marker['content'];
                $budget = max(0, $budget - (strlen($fenceLine) - strlen($content)));
                $fenceLine = $content;
            }
        } while ($fenceLine !== $previousFenceLine);

        return ['line' => $fenceLine, 'quoteDepth' => $quoteDepth];
    }

    /**
     * Present a block opener after composed quote/list prefixes and the current
     * content-column budget are spent. Line blocks and code fences share this
     * view because either may open on a list item's own marker line.
     *
     * @return array{line: string, quoteDepth: int}
     */
    public function containerOpenerView(string $line, int $contentColumn): array
    {
        return $this->fenceView($line, $contentColumn);
    }
}
