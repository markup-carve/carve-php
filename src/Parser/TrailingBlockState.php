<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser;

/**
 * The trailing block of a collected container body.
 *
 * @internal
 * @phpstan-type LegacyState array{openParagraph: bool, inFence: bool, fenceChar: string, fenceLength: int, fenceColumn: int, fenceHostColumn: int, inDiv: bool, divFenceLength: int, divColumn: int, absorbingFence: bool, divDepth: int, isLead: bool, inTable: bool, afterInvisible: bool, afterComment: bool, inFootnoteBody: bool, quotedTable: bool, quoteParagraph: bool, nestedColumn: int, nestedIsQuote: bool}
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

    // Legacy array hooks expose the fence metadata after its closer.
    private ?TrailingCodeFence $lastFence = null;

    /**
     * @var array<string, mixed>
     */
    private array $legacyExtras = [];

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

    /**
     * @phpstan-param LegacyState $state
     *
     * @param array $state
     */
    public static function fromArray(array $state): self
    {
        $result = new self(
            openParagraph: $state['openParagraph'],
            inDiv: $state['inDiv'],
            divFenceLength: $state['divFenceLength'],
            divColumn: $state['divColumn'],
            absorbingFence: $state['absorbingFence'],
            divDepth: $state['divDepth'],
            isLead: $state['isLead'],
            inTable: $state['inTable'],
            afterInvisible: $state['afterInvisible'],
            afterComment: $state['afterComment'],
            inFootnoteBody: $state['inFootnoteBody'],
            quotedTable: $state['quotedTable'],
            quoteParagraph: $state['quoteParagraph'],
            nestedColumn: $state['nestedColumn'],
            nestedIsQuote: $state['nestedIsQuote'],
        );
        if ($state['inFence'] || $state['fenceLength'] > 0) {
            $result->lastFence = new TrailingCodeFence($state['fenceChar'], $state['fenceLength'], $state['fenceColumn'], $state['fenceHostColumn']);
            $result->fence = $state['inFence'] ? $result->lastFence : null;
        }

        $result->legacyExtras = array_diff_key($state, $result->toArray());
        $result->restoreNestedDivState();

        return $result;
    }

    private function restoreNestedDivState(): void
    {
        $snapshot = $this->legacyExtras["\0carve.nestedDivState"] ?? null;
        if ($snapshot instanceof self) {
            $this->nestedDiv = $snapshot->nestedDiv;
            $this->nestedDivColumn = $snapshot->nestedDivColumn;
            $this->nestedDivBody = $snapshot->nestedDivBody;
            $this->nestedListSeen = $snapshot->nestedListSeen;
            $this->literalDiv = $snapshot->literalDiv;
            $this->namedDiv = $snapshot->namedDiv;
            $this->literalCommentLength = $snapshot->literalCommentLength;
            $this->nestedDivHosts = $snapshot->nestedDivHosts;
            $this->footnoteColumn = $snapshot->footnoteColumn;
            $this->quoteInner = $snapshot->quoteInner;
            unset($this->legacyExtras["\0carve.nestedDivState"]);
        }
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
        $this->lastFence = $this->fence;
    }

    /**
     * @return LegacyState
     */
    public function toArray(): array
    {
        $extras = $this->legacyExtras;
        if (
            $this->nestedDiv !== null || $this->nestedDivHosts !== [] || $this->literalDiv || $this->namedDiv || $this->nestedListSeen
            || $this->footnoteColumn !== 0 || $this->quoteInner !== null
        ) {
            $snapshot = clone $this;
            $snapshot->legacyExtras = [];
            $extras["\0carve.nestedDivState"] = $snapshot;
        }

        return [
            'openParagraph' => $this->openParagraph,
            'inFence' => $this->fence !== null,
            'fenceChar' => ($this->fence ?? $this->lastFence)->char ?? '',
            'fenceLength' => ($this->fence ?? $this->lastFence)->length ?? 0,
            'fenceColumn' => ($this->fence ?? $this->lastFence)->column ?? 0,
            'fenceHostColumn' => ($this->fence ?? $this->lastFence)->hostColumn ?? 0,
            'inDiv' => $this->inDiv,
            'divFenceLength' => $this->divFenceLength,
            'divColumn' => $this->divColumn,
            'absorbingFence' => $this->absorbingFence,
            'divDepth' => $this->divDepth,
            'isLead' => $this->isLead,
            'inTable' => $this->inTable,
            'afterInvisible' => $this->afterInvisible,
            'afterComment' => $this->afterComment,
            'inFootnoteBody' => $this->inFootnoteBody,
            'quotedTable' => $this->quotedTable,
            'quoteParagraph' => $this->quoteParagraph,
            'nestedColumn' => $this->nestedColumn,
            'nestedIsQuote' => $this->nestedIsQuote,
        ] + $extras;
    }
}
