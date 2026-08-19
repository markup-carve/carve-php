<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Node;

use MarkupCarve\Carve\Node\Block\AbbreviationDefinition;

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
     * Every `*[ABBR]: …` line the author wrote, in source order.
     *
     * The map above answers WHICH definition wins - the last one, per PART 9R.
     * That is a resolution result, and PART 12 section 3a puts the serialized
     * tree BEFORE resolution, so a shadowed definition is still a line that has
     * to survive: the formatter prints it and the tree carries a node for it.
     * Collecting into the map alone silently dropped it (carve#553).
     *
     * Each entry is `['abbr' => string, 'expansion' => string]`. Empty for a
     * document built programmatically, where the map is all there is.
     *
     * @var array<int, array<string, string>>
     */
    protected array $abbreviationDefinitions = [];

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
     * What the DOCUMENT says about itself. On the parse path the parser
     * measured it; on the ingest path it is `srcByteLength`, read off the wire
     * exactly as written, because PART 12 §7 makes it a field of the payload
     * and a reader that rewrites it has silently repaired the record. 0 means
     * "unknown" (document built programmatically rather than parsed).
     *
     * NOT what a budget may be sized from when the document was ingested. See
     * `getExpansionBudgetLength()`.
     */
    protected int $sourceLength = 0;

    /**
     * Bytes the ingested payload actually cost, or 0 when none was ingested.
     *
     * Set by `AstCodec::decode()` and by nothing else. Internal: it is a fact
     * about how this document ARRIVED, not about the document, so it is listed
     * in `ReferenceShape::INTERNAL_ONLY` and never reaches the wire.
     */
    protected int $ingestPayloadLength = 0;

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
     * Record what the payload this document was decoded from actually cost.
     */
    public function setIngestPayloadLength(int $ingestPayloadLength): void
    {
        $this->ingestPayloadLength = $ingestPayloadLength;
    }

    /**
     * The length a per-render expansion budget may be sized from.
     *
     * The expansion budgets - abbreviations, the table of contents, the index -
     * are `max(floor, factor x this)`. A cap has to be enforced against
     * something the attacker does not supply, and on the parse path this is
     * exactly that: the parser measured the input, so a bigger budget costs a
     * bigger document.
     *
     * On the ingest path `sourceLength` is `srcByteLength`, which arrives
     * INSIDE the payload. Left alone it let the payload choose the size of the
     * guard meant to bound it: rewriting one number to `1000000000` took a
     * 214 KB payload from 1.05 MB of HTML to 102 MB, 478x, for nine extra bytes
     * (carve-php#1052). So an ingested document is bounded by what its payload
     * actually cost as well as by what it claims, and the smaller wins.
     *
     * The claim is still honored where it is smaller, because a document that
     * says it came from a short source is not made suspect by the AST for it
     * being verbose - and an encoded tree is normally several times the size of
     * the source it came from, so on an honest round trip this does not bind.
     */
    public function getExpansionBudgetLength(): int
    {
        if ($this->ingestPayloadLength <= 0) {
            return $this->sourceLength;
        }

        return min($this->sourceLength, $this->ingestPayloadLength);
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

    /**
     * Authored abbreviation definitions in source order, shadowed ones
     * included. Falls back to the resolved map for a document that never had
     * a source.
     *
     * @return array<int, array<string, string>>
     */
    public function getAbbreviationDefinitions(): array
    {
        if ($this->abbreviationDefinitions !== []) {
            return $this->abbreviationDefinitions;
        }

        $defs = [];
        foreach ($this->abbreviations as $abbr => $expansion) {
            $defs[] = ['abbr' => (string)$abbr, 'expansion' => $expansion];
        }

        return $defs;
    }

    /**
     * @param array<int, array<string, string>> $definitions
     */
    public function setAbbreviationDefinitions(array $definitions): void
    {
        $this->abbreviationDefinitions = $definitions;
    }

    /**
     * The definitions this document holds ONLY as map entries, with no
     * `AbbreviationDefinition` child carrying them.
     *
     * A parsed document has a node per authored line, and a renderer writes each
     * one where the author put it. A document assembled through the API has no
     * such node: `setAbbreviations()` is a supported entry point that the AST
     * codec and the ProseMirror bridge both use, and a term built that way can
     * hold characters `abbreviation_term` cannot even spell. Those definitions
     * are real and still have to be written, so they are reported here and the
     * renderers place them together, which is the only position available to a
     * definition with no source line of its own.
     *
     * A MISSING NODE IS NOT ALWAYS AN API DEFINITION. A profile denies
     * `abbreviation_def` by removing the node while the expansion map stays, so
     * the inline `abbr` it feeds keeps rendering (carve-php#858, and profiles.md
     * names the deny and the expansion as separate entries). Reporting that as
     * residual would put the denied line straight back into every non-HTML
     * target. The two cases are told apart by the parsed definition LIST: parsing
     * fills it, one entry per authored line, and the API path leaves it empty and
     * fills only the map. So a populated list means every definition here was
     * authored and any absent node was denied, and nothing is residual.
     *
     * @return array<int, array<string, string>>
     */
    public function getAbbreviationDefinitionsNotInTree(): array
    {
        if ($this->abbreviationDefinitions !== []) {
            return [];
        }

        $inTree = [];
        foreach ($this->children as $child) {
            if ($child instanceof AbbreviationDefinition) {
                $inTree[$child->getAbbr()] = true;
            }
        }

        $residual = [];
        foreach ($this->getAbbreviationDefinitions() as $definition) {
            if (isset($inTree[$definition['abbr']])) {
                continue;
            }
            $residual[] = $definition;
        }

        return $residual;
    }
}
