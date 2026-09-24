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
 *   - mode?: string ("integral"; absent means parenthetical)
 *
 * PART 12 §31 (CARVE-P12-053) makes the ITEM's `mode` the real field, and the
 * group's flag its summary. The source spells `[+@...` once for the whole
 * group, so a parse stamps every item; an importer or an editing API can build
 * a group whose items disagree, and then the group publishes no flag rather
 * than a lie. `isIntegral()` derives from the items, which is what decides the
 * `<span class="citation" data-cite-mode="integral">` wrapper.
 */
class CitationGroup extends InlineNode
{
    /**
     * @param list<array{type?: string, key: string, suppressAuthor: bool, mode?: string, prefix?: list<\MarkupCarve\Carve\Node\Inline\InlineNode>, locator?: list<\MarkupCarve\Carve\Node\Inline\InlineNode>, locatorLabel?: string, locatorValue?: string, suffix?: list<\MarkupCarve\Carve\Node\Inline\InlineNode>, number?: int, useIndex?: int, pos?: array<string, int>}> $items
     * @param string $raw
     * @param bool $integral The AUTHORED `[+` shorthand, which applies to the
     *   whole group, so it is recorded on every item rather than kept beside
     *   them. Pass items that already carry their own `mode` to build a group
     *   the source cannot spell.
     */
    public function __construct(
        protected array $items,
        protected string $raw,
        bool $integral = false,
    ) {
        if ($integral) {
            foreach ($this->items as &$item) {
                $item['mode'] = 'integral';
            }
            unset($item);
        }
        $this->deriveIntegral();
    }

    /**
     * The group's summary of its items, never a fact of its own.
     *
     * A group with no items is NOT integral: `every` over an empty list is
     * vacuously true, and a flag on a group carrying nothing to be integral
     * about says less than its absence.
     */
    protected bool $integral = false;

    private function deriveIntegral(): void
    {
        foreach ($this->items as $item) {
            if (($item['mode'] ?? null) !== 'integral') {
                $this->integral = false;

                return;
            }
        }

        $this->integral = $this->items !== [];
    }

    /**
     * @return list<array{type?: string, key: string, suppressAuthor: bool, mode?: string, prefix?: list<\MarkupCarve\Carve\Node\Inline\InlineNode>, locator?: list<\MarkupCarve\Carve\Node\Inline\InlineNode>, locatorLabel?: string, locatorValue?: string, suffix?: list<\MarkupCarve\Carve\Node\Inline\InlineNode>, number?: int, useIndex?: int, pos?: array<string, int>}>
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
     * @param list<array{type?: string, key: string, suppressAuthor: bool, mode?: string, prefix?: list<\MarkupCarve\Carve\Node\Inline\InlineNode>, locator?: list<\MarkupCarve\Carve\Node\Inline\InlineNode>, locatorLabel?: string, locatorValue?: string, suffix?: list<\MarkupCarve\Carve\Node\Inline\InlineNode>, number?: int, useIndex?: int, pos?: array<string, int>}> $items
     */
    public function setItems(array $items): void
    {
        $this->items = $items;
        $this->deriveIntegral();
    }

    public function setPos(?SourceSpan $pos): void
    {
        parent::setPos($pos);
        if ($pos === null || $pos->startLine !== $pos->endLine) {
            return;
        }

        $innerStart = $this->markerWidth();
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
        $innerStart = $this->markerWidth();
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

    /**
     * Whether every item of this group is integral, which is the group-level
     * `mode` the wire carries.
     */
    public function isIntegral(): bool
    {
        return $this->integral;
    }

    /**
     * How far into `raw` the first item starts.
     *
     * Read off the RAW SOURCE rather than off `isIntegral()`: a group an
     * importer built can carry integral items with no `+` in its raw text, and
     * slicing that raw at offset 2 would cut a character out of the first key.
     */
    private function markerWidth(): int
    {
        return ($this->raw[1] ?? '') === '+' ? 2 : 1;
    }

    public function getType(): string
    {
        return 'citation_group';
    }
}
