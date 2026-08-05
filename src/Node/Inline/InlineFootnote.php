<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Node\Inline;

/**
 * Anonymous inline footnote ^[content]
 */
class InlineFootnote extends InlineNode
{
    /**
     * The number this footnote resolved to.
     *
     * An inline footnote takes a number from the SAME sequence as a reference:
     * carve-js and carve-rs both number `[^a] ^[x] [^a] ^[y]` as 1, 2, 1, 3, so a
     * pass that counted only references would disagree with both (carve-php#843).
     *
     * @var int|null
     */
    protected ?int $number = null;

    public function getNumber(): ?int
    {
        return $this->number;
    }

    /**
     * Null clears it: a reference that no longer resolves must not publish the
     * number of a footnote the renderer will not emit.
     */
    public function setNumber(?int $number): void
    {
        $this->number = $number;
    }

    public function getType(): string
    {
        return 'inline_footnote';
    }
}
