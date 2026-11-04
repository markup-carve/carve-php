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
use WeakMap;

/**
 * Where an erased line sat inside a paragraph's lines (carve#5012), so the
 * canonical writer puts it back there. Runtime data only, never on the wire.
 *
 * @internal
 */
final class TransparentPlacement
{
    /**
     * @var \WeakMap<\MarkupCarve\Carve\Node\Block\Paragraph, array<int, array{0: int, 1: \MarkupCarve\Carve\Node\Node}>>|null
     */
    private static ?WeakMap $inParagraph = null;

    /**
     * @var \WeakMap<\MarkupCarve\Carve\Node\Node, true>|null
     */
    private static ?WeakMap $placed = null;

    /**
     * @var \WeakMap<\MarkupCarve\Carve\Node\Document, true>|null
     */
    private static ?WeakMap $erased = null;

    /**
     * `$node` is written after the paragraph's `$after`-th written line.
     */
    public static function place(Paragraph $paragraph, int $after, Node $node): void
    {
        self::$inParagraph ??= new WeakMap();
        self::$placed ??= new WeakMap();
        $entries = self::$inParagraph[$paragraph] ?? [];
        $entries[] = [$after, $node];
        self::$inParagraph[$paragraph] = $entries;
        self::$placed[$node] = true;
    }

    /**
     * @return array<int, array{0: int, 1: \MarkupCarve\Carve\Node\Node}>
     */
    public static function inParagraph(Paragraph $paragraph): array
    {
        return self::$inParagraph[$paragraph] ?? [];
    }

    public static function isPlaced(Node $node): bool
    {
        return isset(self::$placed[$node]);
    }

    /**
     * Place every line comment or abbreviation definition whose line lies
     * inside the paragraph before it. Only an erased line can sit there, so a
     * tree read back from the wire places them as the parser did.
     */
    public static function placeFromPositions(Node $node, int $depth = 0): bool
    {
        $found = false;
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
                if (!self::isPlaced($child)) {
                    self::place($host, self::lineBreaksBefore($host, $pos->startLine), $child);
                }
                $found = true;

                continue;
            }
            $host = $child instanceof Paragraph ? $child : null;
            if (($child instanceof BlockNode || $child instanceof Document) && $depth < 400) {
                $found = self::placeFromPositions($child, $depth + 1) || $found;
            }
        }

        return $found;
    }

    /**
     * How many of the paragraph's line breaks start above `$line`.
     */
    public static function lineBreaksBefore(Node $node, int $line, int $depth = 0): int
    {
        $count = 0;
        foreach ($node->getChildren() as $child) {
            if ($child instanceof SoftBreak || $child instanceof HardBreak) {
                $pos = $child->getPos();
                $count += $pos !== null && $pos->startLine < $line ? 1 : 0;
            } elseif ($depth < 400) {
                $count += self::lineBreaksBefore($child, $line, $depth + 1);
            }
        }

        return $count;
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
}
