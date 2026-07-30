<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Node\Inline;

/**
 * Strong emphasis (asterisk delimited: *text*)
 */
class Strong extends InlineNode
{
    /**
     * Whether the author wrote this as the COMBINED bold-italic form -- a
     * slash-star opener and its mirror closer -- rather than by nesting `*`
     * around `/`.
     *
     * Both spellings parse to the same Strong wrapping Emphasis, so without this
     * the writer cannot tell them apart and normalizes the spelling Carve
     * documents into one documented nowhere (PART 11 section 6; carve#375).
     *
     * Serialized as `boldItalic` (PART 12 section 3). Same role as a list's bullet
     * character and ordered delimiter: source fidelity for a choice the tree would
     * otherwise lose.
     */
    protected bool $boldItalic = false;

    public function getType(): string
    {
        return 'strong';
    }

    public function isBoldItalic(): bool
    {
        return $this->boldItalic;
    }

    public function setBoldItalic(bool $boldItalic): void
    {
        $this->boldItalic = $boldItalic;
    }
}
