<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Node\Inline;

/**
 * Image
 */
class Image extends InlineNode
{
    /**
     * The reference label if this image was created from a reference image
     * like ![alt][ref] or ![alt][]. Null for inline images.
     */
    protected ?string $referenceLabel = null;

    /**
     * Verbatim authored source of an UNRESOLVED reference (`rawRef` in PART 12
     * §3a). Writers restore the construct from this field.
     */
    protected ?string $rawReferenceLabel = null;

    public function __construct(
        protected string $source = '',
        protected string $alt = '',
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

    public function getSource(): string
    {
        return $this->source;
    }

    public function getAlt(): string
    {
        return $this->alt;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function getType(): string
    {
        return 'image';
    }
}
