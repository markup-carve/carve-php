<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser;

/**
 * The trailing block of a collected container body.
 *
 * @internal
 * @phpstan-type LegacyState array{openParagraph: bool, inFence: bool, fenceChar: string, fenceLength: int, fenceColumn: int, inDiv: bool, divFenceLength: int, divColumn: int, absorbingFence: bool, divDepth: int, isLead: bool, inTable: bool, afterInvisible: bool, afterComment: bool, inFootnoteBody: bool, quotedTable: bool, quoteParagraph: bool, nestedColumn: int}
 */
final class TrailingBlockState
{
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
        );
        if ($state['inFence'] || $state['fenceLength'] > 0) {
            $result->lastFence = new TrailingCodeFence($state['fenceChar'], $state['fenceLength'], $state['fenceColumn']);
            $result->fence = $state['inFence'] ? $result->lastFence : null;
        }

        $result->legacyExtras = array_diff_key($state, $result->toArray());

        return $result;
    }

    public function openFence(string $char, int $length, int $column): void
    {
        $this->fence = new TrailingCodeFence($char, $length, $column);
        $this->lastFence = $this->fence;
    }

    /**
     * @return LegacyState
     */
    public function toArray(): array
    {
        return [
            'openParagraph' => $this->openParagraph,
            'inFence' => $this->fence !== null,
            'fenceChar' => ($this->fence ?? $this->lastFence)->char ?? '',
            'fenceLength' => ($this->fence ?? $this->lastFence)->length ?? 0,
            'fenceColumn' => ($this->fence ?? $this->lastFence)->column ?? 0,
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
        ] + $this->legacyExtras;
    }
}
