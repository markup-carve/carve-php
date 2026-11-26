<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Import\Hub;

use League\CommonMark\Node\Inline\Text;
use League\CommonMark\Parser\Cursor;
use League\CommonMark\Parser\Inline\InlineParserInterface;
use League\CommonMark\Parser\Inline\InlineParserMatch;
use League\CommonMark\Parser\InlineParserContext;
use WeakReference;

/**
 * `$x$` and `$$x$$` as `micromark-extension-math` reads them: a code-span-like run closed by a run of the same length.
 */
final class MathInlineParser implements InlineParserInterface
{
    /**
     * @var \WeakReference<\League\CommonMark\Parser\Cursor>|null
     */
    private ?WeakReference $cursor = null;

    /**
     * Run length => the character positions of the runs of that length, in order; scanned once per cursor.
     *
     * @var array<int, array<int>>
     */
    private array $runs = [];

    /**
     * @var array<int, int>
     */
    private array $next = [];

    public function getMatchDefinition(): InlineParserMatch
    {
        return InlineParserMatch::regex('\$+');
    }

    public function parse(InlineParserContext $inlineContext): bool
    {
        $cursor = $inlineContext->getCursor();
        $dollars = $inlineContext->getFullMatch();
        $length = strlen($dollars);
        $this->scan($cursor);
        $start = $cursor->getPosition() + $length;
        $close = $this->closer($length, $start);
        if ($close === null) {
            $cursor->advanceBy($length);
            $inlineContext->getContainer()->appendChild(new Text($dollars));

            return true;
        }
        $value = $cursor->getSubstring($start, $close - $start);
        $cursor->advanceBy($close + $length - $cursor->getPosition());
        $first = $value[0] ?? '';
        $last = substr($value, -1);
        if (strlen($value) > 1 && in_array($first, [' ', "\n"], true) && in_array($last, [' ', "\n"], true) && trim($value, " \n") !== '') {
            $value = substr($value, 1, -1);
        }
        $inlineContext->getContainer()->appendChild(new Math($value));

        return true;
    }

    private function scan(Cursor $cursor): void
    {
        if ($this->cursor !== null && $this->cursor->get() === $cursor) {
            return;
        }
        $this->cursor = WeakReference::create($cursor);
        $this->runs = [];
        $this->next = [];
        $text = $cursor->getRemainder();
        $position = $cursor->getPosition();
        $byte = 0;
        preg_match_all('/\$+/', $text, $matches, PREG_OFFSET_CAPTURE);
        foreach ($matches[0] as [$run, $offset]) {
            $position += mb_strlen(substr($text, $byte, $offset - $byte), 'UTF-8');
            $byte = $offset;
            $this->runs[strlen($run)][] = $position;
        }
    }

    /**
     * The first run of exactly `$length` dollars at or after `$from`; each length's index only moves forward.
     */
    private function closer(int $length, int $from): ?int
    {
        $runs = $this->runs[$length] ?? [];
        $at = $this->next[$length] ?? 0;
        $count = count($runs);
        while ($at < $count && $runs[$at] < $from) {
            $at++;
        }
        $this->next[$length] = $at;

        return $runs[$at] ?? null;
    }
}
