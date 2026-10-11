<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser;

use MarkupCarve\Carve\Parser\Block\FencedBlockParser;
use MarkupCarve\Carve\Parser\Utility\IndentationHelper;

/**
 * Comment pairing within an immutable literal owner's source lines.
 *
 * @internal
 */
final class LiteralCommentClosers
{
    /**
     * @var array<int, list<int>>
     */
    private array $positions = [];

    private RangeMaximum $boundaries;

    /**
     * @param array<string> $lines
     * @param \MarkupCarve\Carve\Parser\Block\FencedBlockParser $parser
     */
    public function __construct(array $lines, FencedBlockParser $parser)
    {
        $this->boundaries = new RangeMaximum();
        foreach ($lines as $index => $line) {
            $comment = $parser->parseFencedCommentOpenerAnyColumn($line);
            if ($comment !== null) {
                $this->positions[$comment['length']][] = $index;
            }
            $threshold = IndentationHelper::isBlankLine($line) || str_starts_with(ltrim($line, " \t"), '%%')
                ? 0
                : PHP_INT_MAX - IndentationHelper::getLeadingColumns($line);
            $this->boundaries->set($index, $threshold);
        }
    }

    public function hasCloser(int $width, int $from, int $floor): bool
    {
        $positions = $this->positions[$width] ?? [];
        $low = 0;
        $high = count($positions);
        while ($low < $high) {
            $middle = intdiv($low + $high, 2);
            if ($positions[$middle] <= $from) {
                $low = $middle + 1;
            } else {
                $high = $middle;
            }
        }
        $closer = $positions[$low] ?? null;

        return $closer !== null
            && $this->boundaries->maximum($from + 1, $closer) <= PHP_INT_MAX - $floor;
    }
}
