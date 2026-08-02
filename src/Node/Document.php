<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Node;

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
     * Where each `*[ABBR]: …` line sat, keyed by abbreviation.
     *
     * Definitions are collected out of the body into a map, which loses the one
     * thing a position needs. Kept beside them rather than on a node, because
     * the node does not exist until serialization builds it (carve-php#579).
     *
     * @var array<string, array<string, int>>
     */
    protected array $abbreviationSpans = [];

    /**
     * Byte length of the original source the document was parsed from.
     *
     * Used by renderers to size the abbreviation-expansion budget (a DoS
     * guard against output amplification when an abbreviation with a huge
     * definition occurs many times). 0 means "unknown" (document built
     * programmatically rather than parsed).
     */
    protected int $sourceLength = 0;

    public function getType(): string
    {
        return 'document';
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

    /**
     * @return array<string, array<string, int>>
     */
    public function getAbbreviationSpans(): array
    {
        return $this->abbreviationSpans;
    }

    /**
     * @param array<string, array<string, int>> $spans
     */
    public function setAbbreviationSpans(array $spans): void
    {
        $this->abbreviationSpans = $spans;
    }

    public function setAbbreviationsBeforeBody(bool $beforeBody): void
    {
        $this->abbreviationsBeforeBody = $beforeBody;
    }
}
