<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Node\Inline;

/**
 * A bracketed citation group, e.g. [@key] or [see @key, p. 3].
 *
 * Each item in `$items` is a map with:
 *   - key: string
 *   - suppressAuthor: bool
 *   - prefix?: list<InlineNode>
 *   - locator?: list<InlineNode> (the full raw locator inlines, for rendering)
 *   - locatorLabel?: string (citeproc label, e.g. "page", "chapter")
 *   - locatorValue?: string (the numeric/roman portion, e.g. "33-35, 38")
 *   - suffix?: list<InlineNode> (trailing inline content after the locator value)
 *
 * The group-level `$integral` flag is set when the source opens with `[+@...`
 * (the `+` immediately follows the opening `[`). Integral groups are wrapped
 * in `<span class="citation" data-cite-mode="integral">` when rendered.
 */
class CitationGroup extends InlineNode
{
    /**
     * @param list<array{key: string, suppressAuthor: bool, prefix?: list<\MarkupCarve\Carve\Node\Inline\InlineNode>, locator?: list<\MarkupCarve\Carve\Node\Inline\InlineNode>, locatorLabel?: string, locatorValue?: string, suffix?: list<\MarkupCarve\Carve\Node\Inline\InlineNode>}> $items
     * @param string $raw
     * @param bool $integral Whether this group carries the integral (`+`) group marker.
     */
    public function __construct(
        protected array $items,
        protected string $raw,
        protected bool $integral = false,
    ) {
    }

    /**
     * @return list<array{key: string, suppressAuthor: bool, prefix?: list<\MarkupCarve\Carve\Node\Inline\InlineNode>, locator?: list<\MarkupCarve\Carve\Node\Inline\InlineNode>, locatorLabel?: string, locatorValue?: string, suffix?: list<\MarkupCarve\Carve\Node\Inline\InlineNode>}>
     */
    public function getItems(): array
    {
        return $this->items;
    }

    /**
     * Replaces the items, used by the PART 12 §1a text-run pass: `prefix`,
     * `locator` and `suffix` are inline arrays that live outside `children`, so
     * a walk over the tree cannot reach them through the ordinary child list.
     *
     * @param list<array{key: string, suppressAuthor: bool, prefix?: list<\MarkupCarve\Carve\Node\Inline\InlineNode>, locator?: list<\MarkupCarve\Carve\Node\Inline\InlineNode>, locatorLabel?: string, locatorValue?: string, suffix?: list<\MarkupCarve\Carve\Node\Inline\InlineNode>}> $items
     */
    public function setItems(array $items): void
    {
        $this->items = $items;
    }

    public function getRaw(): string
    {
        return $this->raw;
    }

    public function isIntegral(): bool
    {
        return $this->integral;
    }

    public function getType(): string
    {
        return 'citation_group';
    }
}
