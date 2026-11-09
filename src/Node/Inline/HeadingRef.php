<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Node\Inline;

use MarkupCarve\Carve\Node\Block\TableCell;

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

    /**
     * The target read by resolution and renderers. A table cell unescapes its
     * pipes before the inline pass, while the authored target stays on the AST.
     */
    public function getTargetId(): string
    {
        for ($parent = $this->getParent(); $parent !== null; $parent = $parent->getParent()) {
            if ($parent instanceof TableCell) {
                return str_replace('\\|', '|', $this->targetId);
            }
        }

        return $this->targetId;
    }

    /**
     * The target as written, for source-preserving output.
     */
    public function getAuthoredTargetId(): string
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

    public function setTargetId(string $targetId): void
    {
        $this->targetId = $targetId;
    }

    public function getType(): string
    {
        return 'heading_ref';
    }
}
