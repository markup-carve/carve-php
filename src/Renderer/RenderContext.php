<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Renderer;

/**
 * Per-render mutable state for HtmlRenderer.
 */
class RenderContext
{
    /**
     * Tracks footnote reference counts for generating unique IDs.
     *
     * @var array<string, int>
     */
    public array $footnoteRefCounts = [];

    public HeadingIdTracker $headingIdTracker;

    /**
     * Maps footnote labels to their assigned numbers (order of first reference).
     *
     * @var array<string, int>
     */
    public array $footnoteNumbers = [];

    /**
     * Counter for footnote numbering.
     */
    public int $footnoteCounter = 0;

    public int $admonitionCounter = 0;

    /**
     * Collected footnote nodes for rendering at end.
     *
     * @var array<string, \MarkupCarve\Carve\Node\Block\Footnote>
     */
    public array $collectedFootnotes = [];

    /**
     * Deferred content renderers for inline footnotes (number => callback).
     *
     * @var array<int, \Closure(): string>
     */
    public array $inlineFootnoteRenderers = [];

    /**
     * Whether the document holds a note the endnotes section will carry, read
     * off the tree before rendering starts.
     *
     * The first `::: footnotes` marker in such a document PLACES the section and
     * names it with its own title, which reserves an `adm-{n}` id; a marker in a
     * document with no note degrades to a div and reserves none. The decision
     * has to be made where the marker renders, so the sequence follows document
     * order, and `footnoteNumbers` cannot answer it there - a note referenced
     * AFTER the marker has not been numbered yet (CARVE-P9-072).
     */
    public bool $documentHasNote = false;

    public bool $footnotesPlaced = false;

    /**
     * Identity (`spl_object_id`) of the blocks that sit at the document's own
     * top level, recorded before rendering starts.
     *
     * Only a `::: footnotes` marker among these places the endnotes section
     * (CARVE-P9-073); one inside any block-level container renders the §12
     * floor where it is written. Identity rather than shape, because a marker
     * nested in a container is the same node type with the same class.
     *
     * @var array<int, true>
     */
    public array $topLevelBlocks = [];

    /**
     * The `aria-labelledby` and the opening child lines the placing marker
     * contributes to the section it places.
     *
     * @var array{name: string, head: string}
     */
    public array $placedFootnoteTokens = ['name' => '', 'head' => ''];

    public function __construct(?HeadingIdTracker $headingIdTracker = null)
    {
        $this->headingIdTracker = $headingIdTracker ?? new HeadingIdTracker();
    }

    public function reset(): void
    {
        $this->footnoteRefCounts = [];
        $this->headingIdTracker->reset();
        $this->footnoteNumbers = [];
        $this->footnoteCounter = 0;
        $this->admonitionCounter = 0;
        $this->collectedFootnotes = [];
        $this->inlineFootnoteRenderers = [];
        $this->documentHasNote = false;
        $this->footnotesPlaced = false;
        $this->topLevelBlocks = [];
        $this->placedFootnoteTokens = ['name' => '', 'head' => ''];
    }
}
