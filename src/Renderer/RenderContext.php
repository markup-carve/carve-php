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
        $this->placedFootnoteTokens = ['name' => '', 'head' => ''];
    }
}
