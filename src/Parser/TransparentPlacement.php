<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser;

use MarkupCarve\Carve\Node\Block\AbbreviationDefinition;
use MarkupCarve\Carve\Node\Block\BlockNode;
use MarkupCarve\Carve\Node\Block\BlockQuote;
use MarkupCarve\Carve\Node\Block\Comment;
use MarkupCarve\Carve\Node\Block\DefinitionDescription;
use MarkupCarve\Carve\Node\Block\DefinitionList;
use MarkupCarve\Carve\Node\Block\Div;
use MarkupCarve\Carve\Node\Block\Footnote;
use MarkupCarve\Carve\Node\Block\ListBlock;
use MarkupCarve\Carve\Node\Block\ListItem;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Node\Inline\HardBreak;
use MarkupCarve\Carve\Node\Inline\SoftBreak;
use MarkupCarve\Carve\Node\Node;
use SplObjectStorage;
use WeakMap;

/**
 * Where an erased line sat inside a paragraph's lines (carve#5012), so the
 * canonical writer puts it back there. Read from the tree's positions on each
 * render, so an edited tree is never written from a stale record.
 *
 * @internal
 */
final class TransparentPlacement
{
    /**
     * @var \WeakMap<\MarkupCarve\Carve\Node\Document, true>|null
     */
    private static ?WeakMap $erased = null;

    /**
     * @var \WeakMap<\MarkupCarve\Carve\Node\Block\Comment, int>|null
     */
    private static ?WeakMap $plusLines = null;

    /**
     * @var \SplObjectStorage<\MarkupCarve\Carve\Node\Block\Paragraph, array<int, array{0: int, 1: \MarkupCarve\Carve\Node\Node}>>
     */
    private SplObjectStorage $inParagraph;

    /**
     * @var \SplObjectStorage<\MarkupCarve\Carve\Node\Node, true>
     */
    private SplObjectStorage $placed;

    /**
     * Comments whose line lies inside the block before them, by that block: the
     * stand-in line written there, and the comment that replaces it.
     *
     * @var \SplObjectStorage<\MarkupCarve\Carve\Node\Node, array<string, \MarkupCarve\Carve\Node\Block\Comment>>
     */
    private SplObjectStorage $relocated;

    /**
     * @var array<int, array{0: \MarkupCarve\Carve\Node\Node, 1: int, 2: \MarkupCarve\Carve\Node\Block\Comment}>
     */
    private array $standIns = [];

    /**
     * @var \SplObjectStorage<\MarkupCarve\Carve\Node\Node, array{0: int, 1: int}|null>
     */
    private SplObjectStorage $lineMemo;

    /**
     * Per container, the child a stand-in search last stopped at and that search's line.
     *
     * @var \SplObjectStorage<\MarkupCarve\Carve\Node\Node, array{0: int, 1: int}>
     */
    private SplObjectStorage $slotCursor;

    private function __construct()
    {
        $this->inParagraph = new SplObjectStorage();
        $this->placed = new SplObjectStorage();
        $this->relocated = new SplObjectStorage();
        $this->lineMemo = new SplObjectStorage();
        $this->slotCursor = new SplObjectStorage();
    }

    /**
     * Every line comment or abbreviation definition whose line lies inside the
     * paragraph before it. Only an erased line can sit there, so a tree read
     * back from the wire is placed as the parser placed it. Null when none is.
     */
    public static function of(Document $document): ?self
    {
        $placement = new self();
        $placement->collect($document);

        return $placement->placed->count() === 0 ? null : $placement;
    }

    /**
     * @return array<int, array{0: int, 1: \MarkupCarve\Carve\Node\Node}> After which written line, in source order.
     */
    public function inParagraph(Paragraph $paragraph): array
    {
        return $this->inParagraph->offsetExists($paragraph) ? $this->inParagraph[$paragraph] : [];
    }

    public function isPlaced(Node $node): bool
    {
        return $this->placed->offsetExists($node);
    }

    /**
     * Stand-in comments to insert between blocks, as container, child index and node.
     *
     * @return array<int, array{0: \MarkupCarve\Carve\Node\Node, 1: int, 2: \MarkupCarve\Carve\Node\Block\Comment}>
     */
    public function standIns(): array
    {
        return $this->standIns;
    }

    /**
     * @return array<string, \MarkupCarve\Carve\Node\Block\Comment> Each stand-in line and the comment written in its place.
     */
    public function relocatedIn(Node $block): array
    {
        return $this->relocated->offsetExists($block) ? $this->relocated[$block] : [];
    }

    public static function markErased(Document $document): void
    {
        self::$erased ??= new WeakMap();
        self::$erased[$document] = true;
    }

    public static function hasErasedLines(Document $document): bool
    {
        return isset(self::$erased[$document]);
    }

    /**
     * A consumed `+` is parsed as an empty comment line. Unlike an authored one
     * it still parts the blocks around it, so the writer spells it as the `+`.
     */
    public static function markPlusLine(Comment $comment, int $column): void
    {
        self::$plusLines ??= new WeakMap();
        self::$plusLines[$comment] = $column;
    }

    /**
     * The 1-based source column of the `+` this comment stands for, or null.
     */
    public static function plusLineColumn(Node $node): ?int
    {
        return $node instanceof Comment ? self::$plusLines[$node] ?? null : null;
    }

    private function collect(Node $node, int $depth = 0): void
    {
        $host = null;
        $previous = null;
        foreach ($node->getChildren() as $child) {
            $pos = $child->getPos();
            $hostLines = $host === null ? null : self::lines($host);
            if (
                ($child instanceof AbbreviationDefinition
                    || $child instanceof Comment && $child->getFenceLength() === null && !$child->isDelimited())
                && $hostLines !== null && $pos !== null
                && $hostLines[0] < $pos->startLine && $pos->startLine <= $hostLines[1]
            ) {
                $entries = $this->inParagraph->offsetExists($host) ? $this->inParagraph[$host] : [];
                $entries[] = [self::lineBreaksBefore($host, $pos->startLine), $child];
                $this->inParagraph[$host] = $entries;
                $this->placed[$child] = true;

                continue;
            }
            if (
                $child instanceof Comment && $child->getFenceLength() === null && !$child->isDelimited()
                && $pos !== null && $previous !== null && $this->relocate($previous, $child, $pos->startLine)
            ) {
                continue;
            }
            $previous = $child;
            $host = $child instanceof Paragraph ? $child : null;
            if (($child instanceof BlockNode || $child instanceof Document) && $depth < BlockParser::MAX_NESTING_DEPTH * 4) {
                $this->collect($child, $depth + 1);
            }
        }
    }

    /**
     * A comment the tree holds after a block whose lines run past it (carve#5178)
     * is written at its line, at the column of the block before it.
     */
    private function relocate(Node $before, Comment $comment, int $line): bool
    {
        $lines = $this->linesOf($before);
        if ($lines === null || $line <= $lines[0] || $line >= $lines[1] || $before instanceof Paragraph) {
            return false;
        }
        $slot = $this->standInSlot($before, $line);
        if ($slot === null) {
            return false;
        }
        $key = "\u{F8FF}carve-relocated-" . count($this->relocated) . '-' . spl_object_id($comment) . "\u{F8FF}";
        $standIn = new Comment($key);
        $standIn->setPos($comment->getPos());
        if ($slot[0] instanceof Paragraph) {
            $entries = $this->inParagraph->offsetExists($slot[0]) ? $this->inParagraph[$slot[0]] : [];
            $entries[] = [self::lineBreaksBefore($slot[0], $line), $standIn];
            $this->inParagraph[$slot[0]] = $entries;
        } else {
            $this->standIns[] = [$slot[0], $slot[1], $standIn];
        }
        $relocated = $this->relocated->offsetExists($before) ? $this->relocated[$before] : [];
        $relocated['%% ' . $key] = $comment;
        $this->relocated[$before] = $relocated;
        $this->placed[$comment] = true;

        return true;
    }

    /**
     * The paragraph whose lines hold `$line`, else the container and child index it falls before.
     *
     * @return array{0: \MarkupCarve\Carve\Node\Node, 1: int}|null
     */
    private function standInSlot(Node $node, int $line, int $depth = 0): ?array
    {
        $children = $node->getChildren();
        // Comments come in source order, so a search resumes where the last one stopped.
        $cursor = $this->slotCursor->offsetExists($node) ? $this->slotCursor[$node] : [0, 0];
        $count = count($children);
        for ($i = $cursor[1] <= $line ? $cursor[0] : 0; $i < $count; $i++) {
            $child = $children[$i];
            $this->slotCursor[$node] = [$i, $line];
            $lines = $this->linesOf($child);
            if ($lines === null || $lines[1] < $line) {
                continue;
            }
            if ($lines[0] > $line) {
                if (!$node instanceof ListBlock && !$node instanceof DefinitionList) {
                    return [$node, $i];
                }
                // Between two items the line is written at the end of the one above.
                $above = $children[$i - 1] ?? null;

                return $above instanceof ListItem || $above instanceof DefinitionDescription ? [$above, count($above->getChildren())] : null;
            }
            if ($child instanceof Paragraph) {
                return [$child, -1];
            }
            $container = $child instanceof ListBlock || $child instanceof ListItem || $child instanceof DefinitionList
                || $child instanceof DefinitionDescription || $child instanceof BlockQuote || $child instanceof Div || $child instanceof Footnote;

            return $container && $depth < BlockParser::MAX_NESTING_DEPTH * 4 ? $this->standInSlot($child, $line, $depth + 1) : null;
        }

        return null;
    }

    /**
     * How many of the paragraph's line breaks start above `$line`.
     */
    private static function lineBreaksBefore(Node $node, int $line, int &$at = 0, int $depth = 0): int
    {
        $count = 0;
        foreach ($node->getChildren() as $child) {
            $pos = $child->getPos();
            if ($child instanceof SoftBreak || $child instanceof HardBreak) {
                // A break joined across an erased line has no pos (CARVE-P12-022); it starts where the content before it ends.
                $count += ($pos === null ? $at : $pos->startLine) < $line ? 1 : 0;
                $at = $pos === null ? $at + 1 : $pos->endLine;
            } elseif ($depth < BlockParser::MAX_NESTING_DEPTH * 4) {
                $count += self::lineBreaksBefore($child, $line, $at, $depth + 1);
            }
            if ($pos !== null && !$child instanceof SoftBreak && !$child instanceof HardBreak) {
                $at = $pos->endLine;
            }
        }

        return $count;
    }

    /**
     * The first and last source line a node covers, from its placed descendants when it has no pos.
     *
     * @return array{0: int, 1: int}|null
     */
    private function linesOf(Node $node): ?array
    {
        if (!$this->lineMemo->offsetExists($node)) {
            $this->lineMemo[$node] = self::lines($node);
        }

        return $this->lineMemo[$node];
    }

    /**
     * @return array{0: int, 1: int}|null
     */
    private static function lines(Node $node, int $depth = 0): ?array
    {
        $pos = $node->getPos();
        if ($pos !== null) {
            return [$pos->startLine, $pos->endLine];
        }
        $lines = null;
        if ($depth < BlockParser::MAX_NESTING_DEPTH * 4) {
            foreach ($node->getChildren() as $child) {
                $inner = self::lines($child, $depth + 1);
                if ($inner !== null) {
                    $lines = $lines === null ? $inner : [min($lines[0], $inner[0]), max($lines[1], $inner[1])];
                }
            }
        }

        return $lines;
    }
}
