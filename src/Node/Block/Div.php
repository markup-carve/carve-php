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
     * The eight canonical admonition types (grammar PART 9 §12, Tier 1). A div
     * carrying any of these classes renders as a semantic
     * `<aside class="admonition {type}">`; any other div is Tier-2 generic
     * `<div class="{type}">`. Shared with {@see \MarkupCarve\Carve\Renderer\HtmlRenderer}
     * so rendering and profile classification read the same list rather than
     * two copies kept in sync by hand.
     *
     * @var list<string>
     */
    public const ADMONITION_TYPES = ['note', 'tip', 'warning', 'danger', 'info', 'success', 'example', 'quote'];

    /**
     * The six kinds that name GENERATED CONTENT (grammar PART 12 §35,
     * CARVE-P12-057). A named container carrying one of them is a `directive` on
     * the wire and to a profile, not an admonition: a table of contents is not a
     * callout, and a consumer dispatching on the type had to carry this list
     * itself to find that out.
     *
     * The list is CLOSED by the clause, so `endnotes` and `contents` are
     * admonitions. One list here rather than a condition spelled at each call
     * site, for the same reason {@see ADMONITION_TYPES} is shared.
     *
     * @var list<string>
     */
    public const GENERATED_CONTENT_KINDS = ['bibliography', 'footnotes', 'glossary', 'index', 'references', 'toc'];

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
     * The Tier-1 admonition kind this div renders as, or null when it is a
     * plain (Tier-2) container. Mirrors the class-list scan in
     * {@see \MarkupCarve\Carve\Renderer\HtmlRenderer::renderDiv()} (there
     * expressed as `array_intersect($classes, self::ADMONITION_TYPES)`): a div
     * is an admonition because it CARRIES a Tier-1 class, not merely because
     * it was opened with a type word (`::: sidebar` is typed but not a
     * callout).
     *
     * When more than one Tier-1 class is present (e.g. `{.warning}` attached
     * above a `::: note` opener), the first one in class-list order is
     * returned. The renderer keeps the FULL intersection (every Tier-1 class
     * ends up on the rendered `class` attribute); classification only needs to
     * know THAT the div is an admonition, so a single representative value is
     * enough here.
     */
    public function admonitionKind(): ?string
    {
        foreach ($this->getClassList() as $class) {
            if (in_array($class, self::ADMONITION_TYPES, true)) {
                return $class;
            }
        }

        return null;
    }

    /**
     * The generated-content kind this container names, or null when it names
     * none (grammar PART 12 §35, CARVE-P12-057).
     *
     * A NAMED container whose kind is one of {@see GENERATED_CONTENT_KINDS} is a
     * `directive` rather than an admonition or a div. Two conditions, both from
     * the clause:
     *
     * - The opener word, not any class. An attribute-only container is a `div`,
     *   so `{.toc}` above a bare `:::` is not a directive - which is why this
     *   reads the FIRST class (where the parser puts the opener word) instead of
     *   scanning the list the way {@see admonitionKind()} does.
     * - The list is CLOSED. "Every other named container is an `admonition`", so
     *   a seventh generated-looking word like `endnotes` is an admonition.
     */
    public function directiveKind(): ?string
    {
        if (!$this->typed) {
            return null;
        }

        $opener = $this->getClassList()[0] ?? null;

        return in_array($opener, self::GENERATED_CONTENT_KINDS, true) ? $opener : null;
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
