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

    private bool $resolvedEmptyReference = false;

    /**
     * The block attribute line merged in by promotion, kept apart for the AST
     * (PART 12 §23: promotion moves no attributes on the wire).
     *
     * @var array{block: array<string, string|list<string>>, blockOrder: list<string>, own: array<string, string|list<string>>, ownOrder: list<string>, merged: array<string, string|list<string>>, mergedOrder: list<string>}|null
     */
    private ?array $blockAttributeSplit = null;

    /**
     * @param array<string, string|list<string>> $block
     * @param list<string> $blockOrder
     */
    public function mergeBlockAttributes(array $block, array $blockOrder): void
    {
        $own = $this->getAttributeEntries();
        $ownOrder = $this->getAttributeOrder();
        $this->mergeLeadingAttributes($block, $blockOrder);
        $this->blockAttributeSplit = [
            'block' => $block,
            'blockOrder' => $blockOrder,
            'own' => $own,
            'ownOrder' => $ownOrder,
            'merged' => $this->getAttributeEntries(),
            'mergedOrder' => $this->getAttributeOrder(),
        ];
    }

    /**
     * The split, or null when there is none or the attributes changed since.
     *
     * @return array{block: array<string, string|list<string>>, blockOrder: list<string>, own: array<string, string|list<string>>, ownOrder: list<string>}|null
     */
    public function getBlockAttributeSplit(): ?array
    {
        $split = $this->blockAttributeSplit;
        if (
            $split === null
            || $split['merged'] !== $this->getAttributeEntries()
            || $split['mergedOrder'] !== $this->getAttributeOrder()
        ) {
            return null;
        }

        return [
            'block' => $split['block'],
            'blockOrder' => $split['blockOrder'],
            'own' => $split['own'],
            'ownOrder' => $split['ownOrder'],
        ];
    }

    public function setResolvedEmptyReference(bool $resolved): void
    {
        $this->resolvedEmptyReference = $resolved;
    }

    public function isResolvedEmptyReference(): bool
    {
        return $this->resolvedEmptyReference;
    }

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

    public function resolveReference(string $source, ?string $title): void
    {
        $this->source = $source;
        $this->title = $title;
        $this->resolvedEmptyReference = $this->referenceLabel !== null && $source === '';
    }

    public function getType(): string
    {
        return 'image';
    }
}
