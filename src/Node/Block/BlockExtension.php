<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Node\Block;

use InvalidArgumentException;

/**
 * A block an extension owns, carried with a core fallback (PART 12 §33,
 * CARVE-P12-055).
 *
 * The fallback is not a placeholder: it is what the document MEANS to a reader
 * that does not implement the extension, so every renderer and the canonical
 * writer have one defined answer instead of a special case each.
 *
 * Interchange only. Carve 0.1 source spells no block extension, so the parser
 * produces none.
 */
class BlockExtension extends BlockNode
{
    /**
     * The extension's own data, which is NOT Carve content.
     *
     * Kept out of the codec's reflection walk (`ReferenceShape::INTERNAL_ONLY`)
     * and published by hand, because the walk builds a node out of any nested
     * array carrying a `type` key - and `payload.value` is opaque, so a
     * `{"type": "swimlane"}` in a diagram spec is data rather than a node.
     *
     * @var array<string, mixed>|null
     */
    protected ?array $extensionPayload = null;

    /**
     * @param string $name Globally qualified, so two extensions cannot collide:
     *   `org.example.diagram`, not `diagram`.
     * @param \MarkupCarve\Carve\Node\Block\BlockNode $fallback REQUIRED.
     * @param string|null $version The extension's own version, opaque here.
     */
    public function __construct(
        protected string $name,
        protected BlockNode $fallback,
        protected ?string $version = null,
    ) {
        $this->setFallback($fallback);
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getVersion(): ?string
    {
        return $this->version;
    }

    public function getFallback(): BlockNode
    {
        return $this->fallback;
    }

    /**
     * The fallback is also the node's single child, so every walk that reaches
     * children - sanitization, profile enforcement, resolution, rendering -
     * reaches it without knowing this type exists.
     */
    public function setFallback(BlockNode $fallback): void
    {
        $this->fallback = $fallback;
        $this->setChildren([$fallback]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getPayload(): ?array
    {
        return $this->extensionPayload;
    }

    /**
     * @param array<string, mixed>|null $payload
     *
     * @throws \InvalidArgumentException
     */
    public function setPayload(?array $payload): void
    {
        if ($payload !== null && !is_string($payload['format'] ?? null)) {
            throw new InvalidArgumentException('A block extension payload needs a `format` naming how its value is encoded');
        }
        $this->extensionPayload = $payload;
    }

    public function __clone(): void
    {
        $this->parent = null;
        $this->setFallback(clone $this->fallback);
    }

    public function getType(): string
    {
        return 'block_extension';
    }
}
