<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Node\Inline;

/**
 * Footnote reference [^label]
 */
class FootnoteRef extends InlineNode
{
    public function __construct(protected string $label = '')
    {
    }

    /**
     * Whether the label has no definition, so the reference stays literal.
     *
     * NOT part of the wire shape: the reference derives this at render time
     * from the document's definitions, and PART 12 §3 forbids publishing a
     * field the reference does not have. `AstCodec` excludes it on the way out
     * and re-derives it on the way in.
     */
    protected bool $unresolved = false;

    public function getLabel(): string
    {
        return $this->label;
    }

    public function isUnresolved(): bool
    {
        return $this->unresolved;
    }

    public function setUnresolved(bool $unresolved): void
    {
        $this->unresolved = $unresolved;
    }

    public function getType(): string
    {
        return 'footnote_ref';
    }
}
