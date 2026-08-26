<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Node\Inline;

use MarkupCarve\Carve\Ast\SourceSpan;

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
     * @param list<array{type?: string, key: string, suppressAuthor: bool, prefix?: list<\MarkupCarve\Carve\Node\Inline\InlineNode>, locator?: list<\MarkupCarve\Carve\Node\Inline\InlineNode>, locatorLabel?: string, locatorValue?: string, suffix?: list<\MarkupCarve\Carve\Node\Inline\InlineNode>, number?: int, useIndex?: int, pos?: array<string, int>}> $items
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
     * @return list<array{type?: string, key: string, suppressAuthor: bool, prefix?: list<\MarkupCarve\Carve\Node\Inline\InlineNode>, locator?: list<\MarkupCarve\Carve\Node\Inline\InlineNode>, locatorLabel?: string, locatorValue?: string, suffix?: list<\MarkupCarve\Carve\Node\Inline\InlineNode>, number?: int, useIndex?: int, pos?: array<string, int>}>
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
     * @param list<array{type?: string, key: string, suppressAuthor: bool, prefix?: list<\MarkupCarve\Carve\Node\Inline\InlineNode>, locator?: list<\MarkupCarve\Carve\Node\Inline\InlineNode>, locatorLabel?: string, locatorValue?: string, suffix?: list<\MarkupCarve\Carve\Node\Inline\InlineNode>, number?: int, useIndex?: int, pos?: array<string, int>}> $items
     */
    public function setItems(array $items): void
    {
        $this->items = $items;
    }

    public function setPos(?SourceSpan $pos): void
    {
        parent::setPos($pos);
        if ($pos === null || $pos->startLine !== $pos->endLine) {
            return;
        }

        $innerStart = $this->integral ? 2 : 1;
        if (strlen($this->raw) <= $innerStart || !str_ends_with($this->raw, ']')) {
            return;
        }
        $inner = substr($this->raw, $innerStart, -1);
        $cursor = 0;
        foreach (explode(';', $inner) as $index => $part) {
            if (!isset($this->items[$index])) {
                break;
            }
            $leading = strlen($part) - strlen(ltrim($part));
            $trailing = strlen($part) - strlen(rtrim($part));
            $startBytes = $innerStart + $cursor + $leading;
            $endBytes = $innerStart + $cursor + strlen($part) - $trailing;
            $start = mb_strlen(substr($this->raw, 0, $startBytes), 'UTF-8');
            $end = mb_strlen(substr($this->raw, 0, $endBytes), 'UTF-8');
            $this->items[$index]['pos'] = [
                'startLine' => $pos->startLine,
                'endLine' => $pos->startLine,
                'startColumn' => $pos->startColumn + $start,
                'endColumn' => $pos->startColumn + $end,
                'startOffset' => $pos->startOffset + $start,
                'endOffset' => $pos->startOffset + $end,
            ];
            $cursor += strlen($part) + 1;
        }
    }

    /**
     * Byte ranges of the authored items relative to the group's raw source.
     *
     * @return list<array{int, int}>
     */
    public function itemSourceRanges(): array
    {
        $innerStart = $this->integral ? 2 : 1;
        if (strlen($this->raw) <= $innerStart || !str_ends_with($this->raw, ']')) {
            return [];
        }
        $ranges = [];
        $cursor = 0;
        foreach (explode(';', substr($this->raw, $innerStart, -1)) as $part) {
            $leading = strlen($part) - strlen(ltrim($part));
            $trailing = strlen($part) - strlen(rtrim($part));
            $ranges[] = [
                $innerStart + $cursor + $leading,
                $innerStart + $cursor + strlen($part) - $trailing,
            ];
            $cursor += strlen($part) + 1;
        }

        return $ranges;
    }

    public function setItemPos(int $index, ?SourceSpan $pos): void
    {
        if (isset($this->items[$index]) && $pos !== null) {
            $this->items[$index]['pos'] = $pos->toArray();
        }
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
