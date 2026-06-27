<?php

declare(strict_types=1);

namespace Carve\Node;

/**
 * Root document node
 */
class Document extends Node
{
    /**
     * Abbreviation definitions for round-trip support
     *
     * @var array<string, string>
     */
    protected array $abbreviations = [];

    protected bool $abbreviationsBeforeBody = false;

    /**
     * Byte length of the original source the document was parsed from.
     *
     * Used by renderers to size the abbreviation-expansion budget (a DoS
     * guard against output amplification when an abbreviation with a huge
     * definition occurs many times). 0 means "unknown" (document built
     * programmatically rather than parsed).
     */
    protected int $sourceLength = 0;

    /**
     * True when the parsed document contains at least one link node.
     *
     * Lets the cross-reference resolver skip the link-nesting enforcement pass
     * for documents with no links: a nested anchor can only exist inside a
     * link, so without one the pass is a no-op.
     */
    protected bool $hasLinks = false;

    /**
     * True when the parsed document contains at least one `</#id>`
     * cross-reference (HeadingRef) node.
     */
    protected bool $hasHeadingRefs = false;

    /**
     * True when the parsed document contains at least one numbered-caption
     * placeholder (CaptionNumber) node.
     *
     * Lets the cross-reference resolver skip the numbered-caption pass for
     * documents with no caption numbers: that pass only mutates a caption when
     * a CaptionNumber node is present, so without one it is a no-op.
     */
    protected bool $hasNumberedCaptions = false;

    public function getType(): string
    {
        return 'document';
    }

    /**
     * Whether the document contains at least one link node.
     */
    public function hasLinks(): bool
    {
        return $this->hasLinks;
    }

    public function setHasLinks(bool $hasLinks): void
    {
        $this->hasLinks = $hasLinks;
    }

    /**
     * Whether the document contains at least one `</#id>` cross-reference node.
     */
    public function hasHeadingRefs(): bool
    {
        return $this->hasHeadingRefs;
    }

    public function setHasHeadingRefs(bool $hasHeadingRefs): void
    {
        $this->hasHeadingRefs = $hasHeadingRefs;
    }

    /**
     * Whether the document contains at least one numbered-caption placeholder.
     */
    public function hasNumberedCaptions(): bool
    {
        return $this->hasNumberedCaptions;
    }

    public function setHasNumberedCaptions(bool $hasNumberedCaptions): void
    {
        $this->hasNumberedCaptions = $hasNumberedCaptions;
    }

    /**
     * Get the byte length of the original source.
     */
    public function getSourceLength(): int
    {
        return $this->sourceLength;
    }

    /**
     * Set the byte length of the original source.
     */
    public function setSourceLength(int $sourceLength): void
    {
        $this->sourceLength = $sourceLength;
    }

    /**
     * Get abbreviation definitions
     *
     * @return array<string, string>
     */
    public function getAbbreviations(): array
    {
        return $this->abbreviations;
    }

    /**
     * Set abbreviation definitions
     *
     * @param array<string, string> $abbreviations
     */
    public function setAbbreviations(array $abbreviations): void
    {
        $this->abbreviations = $abbreviations;
    }

    public function hasAbbreviationsBeforeBody(): bool
    {
        return $this->abbreviationsBeforeBody;
    }

    public function setAbbreviationsBeforeBody(bool $beforeBody): void
    {
        $this->abbreviationsBeforeBody = $beforeBody;
    }
}
