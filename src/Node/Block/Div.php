<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Node\Block;

use MarkupCarve\Carve\Node\Inline\Text;

/**
 * Generic div container (fenced with :::)
 */
class Div extends BlockNode
{
    /**
     * Grouping label from the opener `[label]` (grammar PART 9 §12). Structured
     * metadata: NOT rendered by core, consumed by a group extension (e.g. tabs)
     * as the tab name. Mirrors {@see \MarkupCarve\Carve\Node\Block\CodeBlock::getLabel()}.
     */
    protected ?string $label = null;

    /**
     * Quoted opener header (grammar PART 9 rule 12b). This is the ONLY source
     * of `<p class="admonition-title">`; distinct from a `title` attribute,
     * which is a plain HTML attribute.
     */
    protected ?string $header = null;

    /**
     * Parsed inline form of {@see $header}. The raw header remains the source
     * for formatters and round trips; renderers consume these nodes.
     *
     * @var list<\MarkupCarve\Carve\Node\Node>
     */
    protected array $headerNodes = [];

    /**
     * True when the div was opened with a type word (`::: sidebar`), false for
     * a bare `:::` whose classes come from a preceding attribute line. The two
     * forms render identically but must round-trip through the formatter in
     * their original spelling.
     */
    protected bool $typed = false;

    public function getType(): string
    {
        return 'div';
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function setLabel(?string $label): void
    {
        $this->label = $label;
    }

    public function getHeader(): ?string
    {
        return $this->header;
    }

    public function setHeader(?string $header): void
    {
        $this->header = $header;
    }

    /**
     * The parsed header inlines. When only `setHeader()` was called (a
     * programmatically built Div, outside the parser), the raw string is
     * surfaced as a single Text node so consumers never silently drop a
     * title that was set through the public API.
     *
     * @return list<\MarkupCarve\Carve\Node\Node>
     */
    public function getHeaderNodes(): array
    {
        if ($this->headerNodes === [] && $this->header !== null && $this->header !== '') {
            return [new Text($this->header)];
        }

        return $this->headerNodes;
    }

    /**
     * @param array<\MarkupCarve\Carve\Node\Node> $headerNodes
     */
    public function setHeaderNodes(array $headerNodes): void
    {
        $this->headerNodes = array_values($headerNodes);
    }

    public function isTyped(): bool
    {
        return $this->typed;
    }

    public function setTyped(bool $typed): void
    {
        $this->typed = $typed;
    }

    /**
     * Whether this div is a placement-directive carrier: `::: footnotes`
     * (relocates the endnotes section, {@see \MarkupCarve\Carve\Renderer\HtmlRenderer::renderDiv()})
     * or `::: toc` (relocates the table of contents,
     * {@see \MarkupCarve\Carve\Extension\TocPlacementExtension::register()}).
     *
     * Both are childless BY DESIGN - the directive marks a position, not
     * content - so this predicate lets other passes (e.g. the profile filter's
     * empty-container cleanup) recognize a carrier and leave it alone instead
     * of pruning it as structurally empty. Mirrors the class checks the
     * renderer and the TOC extension already perform; keep this in sync with
     * both if either changes.
     */
    public function isPlacementCarrier(): bool
    {
        return $this->hasClass('footnotes') || $this->hasClass('toc');
    }
}
