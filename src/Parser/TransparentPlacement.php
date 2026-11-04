<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser;

use MarkupCarve\Carve\Node\Block\AbbreviationDefinition;
use MarkupCarve\Carve\Node\Block\BlockNode;
use MarkupCarve\Carve\Node\Block\Comment;
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

    private function __construct()
    {
        $this->inParagraph = new SplObjectStorage();
        $this->placed = new SplObjectStorage();
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
            $host = $child instanceof Paragraph ? $child : null;
            if (($child instanceof BlockNode || $child instanceof Document) && $depth < BlockParser::MAX_NESTING_DEPTH * 4) {
                $this->collect($child, $depth + 1);
            }
        }
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
