<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser;

/**
 * The trailing block of a collected container body.
 *
 * @internal
 */
final class TrailingBlockState
{
    /**
     * @var list<array{marker:int, parent:int}>
     */
    public array $nestedDivHosts = [];

    public ?self $nestedDiv = null;

    public int $nestedDivColumn = 0;

    public bool $nestedDivBody = false;

    public bool $literalDiv = false;

    public bool $namedDiv = false;

    public bool $nestedListSeen = false;

    public ?int $literalCommentLength = null;

    /**
     * Column, from the tracked container's content, of the footnote definition whose body is open.
     */
    public int $footnoteColumn = 0;

    /**
     * The nested quote's own state after its last line: nested column and footnote body only.
     */
    public ?self $quoteInner = null;

    public function __construct(
        public bool $openParagraph = false,
        public ?TrailingCodeFence $fence = null,
        public bool $inDiv = false,
        public int $divFenceLength = 0,
        public int $divColumn = 0,
        public bool $absorbingFence = false,
        public int $divDepth = 0,
        public bool $isLead = true,
        public bool $inTable = false,
        public bool $afterInvisible = false,
        public bool $afterComment = false,
        public bool $inFootnoteBody = false,
        public bool $quotedTable = false,
        public bool $quoteParagraph = false,
        public int $nestedColumn = 0,
        // Is the container `nestedColumn` names a QUOTE rather than a list? The
        // two are not interchangeable to a dedented line: a description body
        // keeps the line for an open nested LIST and ends on an open nested
        // quote (markup-carve/carve-php#2904).
        public bool $nestedIsQuote = false,
    ) {
    }

    public function hasOpenDiv(): bool
    {
        return $this->inDiv || $this->nestedDiv !== null;
    }

    public function hasLiteralDiv(): bool
    {
        $state = $this;
        while ($state->nestedDiv !== null) {
            $state = $state->nestedDiv;
        }

        return $state->inDiv && $state->literalDiv;
    }

    public function openFence(string $char, int $length, int $column, int $hostColumn = 0, bool $pairedInline = false): void
    {
        $this->fence = new TrailingCodeFence($char, $length, $column, $hostColumn, $pairedInline);
    }
}
