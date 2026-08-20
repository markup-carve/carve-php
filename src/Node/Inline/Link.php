<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Node\Inline;

/**
 * Hyperlink
 */
class Link extends InlineNode
{
    /**
     * The reference label if this link was created from a reference link
     * like [text][ref] or [text][]. Null for inline links.
     */
    protected ?string $referenceLabel = null;

    /**
     * Verbatim authored source of an UNRESOLVED reference (`rawRef` in PART 12
     * §3a). Writers restore the construct from this field.
     */
    protected ?string $rawReferenceLabel = null;

    /**
     * Whether the reference that produced this link was DERIVED from a heading
     * (PART 11 R1) rather than written as a `[label]: url` line.
     *
     * The canonical writer needs the distinction and `referenceLabel` alone
     * cannot carry it: this engine keeps that label for BOTH kinds, because the
     * HTML round trip reproduces a `[label]: url` line from it. A heading has
     * no such line, so the authored `[text][]` is the only record of what was
     * written - resolving it to `[text](#Some-Id)` bakes a generated id into
     * the source on every `fmt` pass.
     */
    protected bool $fromHeadingReference = false;

    /**
     * Whether this link was created from an autolink like <url> or <email>
     */
    protected bool $isAutolink = false;

    public function __construct(
        protected ?string $destination = null,
        protected ?string $title = null,
    ) {
    }

    public function getReferenceLabel(): ?string
    {
        return $this->referenceLabel;
    }

    public function setReferenceLabel(string $label): void
    {
        $this->referenceLabel = $label;
    }

    public function getRawReferenceLabel(): ?string
    {
        return $this->rawReferenceLabel;
    }

    public function setRawReferenceLabel(string $label): void
    {
        $this->rawReferenceLabel = $label;
    }

    public function isFromHeadingReference(): bool
    {
        return $this->fromHeadingReference;
    }

    public function setFromHeadingReference(bool $fromHeadingReference): void
    {
        $this->fromHeadingReference = $fromHeadingReference;
    }

    public function isAutolink(): bool
    {
        return $this->isAutolink;
    }

    public function setAutolink(bool $isAutolink): void
    {
        $this->isAutolink = $isAutolink;
    }

    public function getDestination(): ?string
    {
        return $this->destination;
    }

    public function setDestination(string $destination): void
    {
        $this->destination = $destination;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function resolveReference(string $destination, ?string $title): void
    {
        $this->destination = $destination;
        $this->title = $title;
    }

    public function getType(): string
    {
        return 'link';
    }
}
