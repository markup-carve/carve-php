<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Node\Inline;

/**
 * Heading cross-reference (Carve: </#id>).
 *
 * Renders as <a href="#id"><target heading text></a>, the label
 * resolved from the heading whose identifier is <id>.
 */
class HeadingRef extends InlineNode
{
    /**
     * The resolved destination (`#Id`), or null where the target does not
     * exist. PART 12 §3a publishes the resolution BESIDE the authored
     * construct - `target` is what the author wrote, `href` is what it
     * resolved to - so a consumer does not have to rebuild the heading-id
     * table to render a crossref (markup-carve/carve#614).
     */
    protected ?string $href = null;

    public function __construct(protected string $targetId)
    {
    }

    public function getTargetId(): string
    {
        return $this->targetId;
    }

    public function getHref(): ?string
    {
        return $this->href;
    }

    public function setHref(?string $href): void
    {
        $this->href = $href;
    }

    public function getType(): string
    {
        return 'heading_ref';
    }
}
