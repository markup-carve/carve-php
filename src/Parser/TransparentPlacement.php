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

    private function collect(Node $node, int $depth = 0): void
    {
        $host = null;
        foreach ($node->getChildren() as $child) {
            $pos = $child->getPos();
            $hostPos = $host?->getPos();
            if (
                ($child instanceof AbbreviationDefinition
                    || $child instanceof Comment && $child->getFenceLength() === null && !$child->isDelimited())
                && $host !== null && $pos !== null && $hostPos !== null
                && $hostPos->startLine < $pos->startLine && $pos->startLine <= $hostPos->endLine
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
    private static function lineBreaksBefore(Node $node, int $line, int $depth = 0): int
    {
        $count = 0;
        foreach ($node->getChildren() as $child) {
            if ($child instanceof SoftBreak || $child instanceof HardBreak) {
                $pos = $child->getPos();
                $count += $pos !== null && $pos->startLine < $line ? 1 : 0;
            } elseif ($depth < BlockParser::MAX_NESTING_DEPTH * 4) {
                $count += self::lineBreaksBefore($child, $line, $depth + 1);
            }
        }

        return $count;
    }
}
