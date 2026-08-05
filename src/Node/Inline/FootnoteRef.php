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

    /**
     * The number this reference resolved to, once numbering has run.
     *
     * PART 12 §5 serializes it: footnote numbering is a resolution result that a
     * consumer cannot recompute without reimplementing PART 9R. It used to live
     * only in HtmlRenderer's render context, so the published tree carried none
     * (carve-php#843).
     *
     * @var int|null
     */
    protected ?int $number = null;

    public function getNumber(): ?int
    {
        return $this->number;
    }

    /**
     * Null clears it, for a reference that stopped resolving after the profile
     * filter removed its definition (carve-php#849).
     */
    public function setNumber(?int $number): void
    {
        $this->number = $number;
    }

    public function getType(): string
    {
        return 'footnote_ref';
    }
}
