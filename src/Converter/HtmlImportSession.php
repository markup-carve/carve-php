<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Converter;

use DOMDocument;
use SplObjectStorage;

/**
 * Mutable state and DOM decisions owned by one HTML import.
 *
 * @internal
 *
 * @phpstan-import-type ImportedNode from \MarkupCarve\Carve\Converter\HtmlAstBuildResult
 */
final class HtmlImportSession
{
    public ?DOMDocument $builtDocument = null;

    /**
     * @var \SplObjectStorage<\DOMNode, int>
     */
    public SplObjectStorage $pathSiblingIndices;

    /**
     * Serialized summary titles; null keeps the summary as body content.
     * An empty string means the summary has no content to report.
     *
     * @var \SplObjectStorage<\DOMElement, string|null>
     */
    public SplObjectStorage $summaryTitles;

    /**
     * @var \SplObjectStorage<\DOMElement, null>
     */
    public SplObjectStorage $droppedEmptyElements;

    /**
     * @var \SplObjectStorage<\DOMElement, null>
     */
    public SplObjectStorage $unwrappedSameKindSpans;

    /**
     * @var \SplObjectStorage<\DOMElement, null>
     */
    public SplObjectStorage $droppedEmptyHeadings;

    /**
     * Elements whose URL-list attribute the tree carries.
     *
     * @var \SplObjectStorage<\DOMElement, null>
     */
    public SplObjectStorage $urlListCarriers;

    /**
     * @var array<string, list<string>>
     */
    public array $retainedTableAttributes = [];

    /**
     * @var array<string, true>
     */
    public array $retainedTablePartitions = [];

    /**
     * @var \SplObjectStorage<\DOMElement, \DOMElement|null>
     */
    public SplObjectStorage $codeLanguageWrappers;

    /**
     * @var array<string, list<string>>
     */
    public array $displacedFigureAttributes = [];

    /**
     * @var \SplObjectStorage<\DOMElement, null>
     */
    public SplObjectStorage $keptRawElements;

    /**
     * @var \SplObjectStorage<\DOMElement, null>
     */
    public SplObjectStorage $droppedBlankTableRows;

    /**
     * `<dl>` elements merged into the definition list before them.
     *
     * @var \SplObjectStorage<\DOMElement, null>
     */
    public SplObjectStorage $mergedDefinitionLists;

    /**
     * @var \SplObjectStorage<\DOMElement, null>
     */
    public SplObjectStorage $flattenedSummaryBlocks;

    /**
     * @var \SplObjectStorage<\DOMElement, bool>
     */
    public SplObjectStorage $tableBlockCells;

    public int $pipeCellDepth = 0;

    /**
     * @var \SplObjectStorage<\DOMElement, null>
     */
    public SplObjectStorage $foldedCodeSpans;

    /**
     * @var \SplObjectStorage<\DOMElement, null>
     */
    public SplObjectStorage $storedSourceElements;

    public ?bool $tableCellAllowsEmptyCode = null;

    /**
     * @var array<string, true>
     */
    public array $footnoteTargets = [];

    /**
     * @var list<ImportedNode>
     */
    public array $footnoteDefinitions = [];

    /**
     * @var array<string, string>
     */
    public array $referenceDefinitions = [];

    /**
     * The bracket text an ordered task item's checkbox is written as, keyed by
     * the `<input>` element's object id.
     *
     * @var array<int, string>
     */
    public array $orderedTaskBrackets = [];

    /**
     * @var array<string, string>
     */
    public array $abbreviationDefinitions = [];

    public bool $inFootnoteDefinition = false;

    /**
     * @var list<string>
     */
    public array $inlineTypeStack = [];

    public int $quoteDepth = 0;

    public bool $inCaption = false;

    public bool $inInlineProjection = false;

    public bool $preserveInlineWhitespace = false;

    /**
     * @var \SplObjectStorage<\DOMElement, true>
     */
    public SplObjectStorage $retainedListTypes;

    public function __construct()
    {
        $this->retainedListTypes = new SplObjectStorage();
        $this->pathSiblingIndices = new SplObjectStorage();
        $this->summaryTitles = new SplObjectStorage();
        $this->droppedEmptyElements = new SplObjectStorage();
        $this->unwrappedSameKindSpans = new SplObjectStorage();
        $this->droppedEmptyHeadings = new SplObjectStorage();
        $this->urlListCarriers = new SplObjectStorage();
        $this->codeLanguageWrappers = new SplObjectStorage();
        $this->keptRawElements = new SplObjectStorage();
        $this->droppedBlankTableRows = new SplObjectStorage();
        $this->mergedDefinitionLists = new SplObjectStorage();
        $this->flattenedSummaryBlocks = new SplObjectStorage();
        $this->tableBlockCells = new SplObjectStorage();
        $this->foldedCodeSpans = new SplObjectStorage();
        $this->storedSourceElements = new SplObjectStorage();
    }
}
