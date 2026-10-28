<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser;

use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Node\Node;
use MarkupCarve\Carve\Renderer\HeadingIdTracker;
use MarkupCarve\Carve\Util\StringUtil;

trait BlockReferenceMethods
{
    /**
     * Validate reference definitions vs usage
     * Generates warnings for unused references.
     * Note: Undefined references are warned about inline during parsing.
     */
    protected function validateReferences(): void
    {
        (new DocumentFinalizationPass($this->state))->validateReferences($this->addWarning(...));
    }

    /**
     * A tracker configured like the ones the parse passes use, so the ids the
     * reference index points at are the ids the renderer will emit.
     */
    protected function headingIdTrackerForReferences(): HeadingIdTracker
    {
        $tracker = new HeadingIdTracker();
        $tracker->setIdTransformer($this->headingIdTransformer);
        $tracker->setLowercase($this->headingIdLowercase);

        return $tracker;
    }

    /**
     * The key `$label` enters the implicit heading index under (PART 9R R1).
     *
     * ONE DERIVATION FOR BOTH SIDES. The index is keyed by a heading's rendered
     * plain text, and R1's "ON THIS PATH THE LABEL ENTERS AS ITS RENDERED PLAIN
     * TEXT, the same string kind the heading side already enters as" makes that
     * a single routine rather than two that have to be kept in step: this is
     * HeadingReferenceCollector::register()'s own trim-and-collapse over
     * HeadingIdTracker::getPlainText(), reached from the reference site instead
     * of the heading. A second spelling here is what let `# an /em/ heading` go
     * unreachable by `[an /em/ heading][]` (markup-carve/carve#1011).
     *
     * @param \MarkupCarve\Carve\Node\Node $label The label's PARSED inline nodes.
     */
    public function headingIndexKey(Node $label): string
    {
        return LabelKey::normalize($this->headingIndexLabel($label));
    }

    /**
     * The label's DERIVED TEXT: the same extraction headingIndexKey() matches
     * on, before the trim-and-collapse that only the MATCH needs.
     *
     * Two different strings, and PART 12 §3a publishes this one. `ref` is the
     * resolution key in the sense markup-carve/carve#962 ruled - markup stripped, so
     * `` [`code()` heading][] `` publishes `code() heading` and not its
     * backticks, while `rawRef` keeps the authored spelling. It is NOT the
     * lookup key: PART 9R R1 matches the heading index looser than it matches a
     * definition, trimming, collapsing whitespace, NFC-normalizing and folding
     * CASE, and no engine publishes a case-folded `ref`. Publishing the
     * half-normalized middle - collapsed but not folded - names a string that
     * appears nowhere in the resolution, and dropped an authored double space
     * that carve-js and carve-rs both keep (markup-carve/carve#1023).
     *
     * @param \MarkupCarve\Carve\Node\Node $label The label's PARSED inline nodes.
     */
    public function headingIndexLabel(Node $label): string
    {
        $this->referenceLabelTracker ??= new HeadingIdTracker();

        return $this->referenceLabelTracker->getPlainText($label);
    }

    /**
     * Re-run the parse with the tree-derived heading index seeded.
     *
     * Resets exactly the state `parse()` resets, so the second pass starts
     * from the same place the first did and cannot double-count warnings,
     * footnotes or used-reference bookkeeping. The seed is applied AFTER the
     * extract passes, so a real link definition still wins the tie (R1).
     *
     * @param array<string> $lines
     * @param array<string, array{0: string, 1: \MarkupCarve\Carve\Parser\ReferenceDefinition}> $headingReferences
     * @param int $sourceLength
     */
    protected function reparseWithHeadingReferences(
        array $lines,
        array $headingReferences,
        int $sourceLength,
    ): Document {
        $this->resetParseState();

        $document = new Document();
        $this->extractDefinitions($lines, $this->state->source->normalizedSource);
        // ARMED THE SAME WAY parse() ARMS IT. The definitions are collected by
        // the structural walk below, and only while discovery is on. The first
        // pass turns it OFF before finishing, so without this the second walk
        // collected no definitions at all and every reference in the rebuilt
        // tree was unresolvable (carve-php#1937).
        $this->state->session->discoveringDefinitions = true;
        $this->extractHeadingReferences($lines);
        $this->seedHeadingReferences($headingReferences);
        $this->parseBlocks($document, $lines, 0, topLevel: true);
        // THE SECOND PASS HAS TO FINISH THE SAME WAY THE FIRST ONE DOES.
        //
        // Inline parsing resolves a reference against the definitions
        // collected SO FAR, so a definition written below its use is still
        // missing when the link is built. `parse()` repairs that afterwards by
        // running this same block - but on the tree the FIRST pass produced,
        // which this pass has just replaced. So the rebuilt tree kept every
        // forward reference unresolved and rendered it as its source text:
        // `[text][ref]` written above `[ref]: /target` came out literally
        // (carve-php#1937).
        //
        // Calling the whole finish rather than the forward-reference walk
        // alone is deliberate: the work the walk defers - footnote bodies
        // discovered mid-walk, caption slots, the collected definitions
        // themselves - has to land before anything can be resolved against it.
        $this->state->session->discoveringDefinitions = false;
        $this->finishIntegratedDefinitionPass($document, $lines);
        $document->setSourceLength($sourceLength);

        return $document;
    }

    public function getReference(string $label): ?ReferenceDefinition
    {
        if (!LabelKey::isSingleLine($label)) {
            return null;
        }

        return $this->state->session->references[LabelKey::normalize($label)] ?? null;
    }

    public function isCollectingDefinitions(): bool
    {
        return $this->state->session->discoveringDefinitions;
    }

    public function getCollapsedReference(string $label): ?ReferenceDefinition
    {
        if (!LabelKey::isSingleLine($label)) {
            return null;
        }

        return $this->state->session->references[LabelKey::normalize($label)] ?? $this->state->session->headingReferencesByFoldedLabel[$this->headingLabelKey($label)] ?? null;
    }

    /**
     * The line pre-scan no longer feeds the implicit-reference index.
     */
    protected function registerHeadingReference(string $label, ReferenceDefinition $reference): void
    {
        $this->state->session->headingReferencesByFoldedLabel[$this->headingLabelKey($label)] ??= $reference;
    }

    /**
     * The heading-index key: NFC-normalized, case exact (PART 9R R1,
     * CARVE-P9R-010). The second copy of HeadingReferenceCollector::labelKey()
     * - both must agree, or a reference resolves on one path and not the other.
     */
    protected function headingLabelKey(string $label): string
    {
        return StringUtil::normalizeNfc($label);
    }

    /**
     * Mark a reference as used (for validation warnings)
     * Only tracks when collectWarnings is enabled.
     */
    public function markReferenceUsed(string $label, int $line): void
    {
        if ($this->collectWarnings && !isset($this->state->session->usedReferences[$label])) {
            $this->state->session->usedReferences[$label] = $line;
        }
    }

    public function hasFootnote(string $label): bool
    {
        return LabelKey::isSingleLine($label) && isset($this->state->session->footnotes[LabelKey::normalize($label)]);
    }

    /**
     * Get all abbreviation definitions
     *
     * @return array<string, string> Map of abbreviation text to definition
     */
    public function getAbbreviations(): array
    {
        return $this->state->session->abbreviations;
    }

    /**
     * Get the definition for a specific abbreviation
     */
    public function getAbbreviation(string $abbr): ?string
    {
        return $this->state->session->abbreviations[$abbr] ?? null;
    }

    /**
     * Record that a collapsed `[text][]` reference found no definition.
     *
     * Called from the inline parser wherever a reference found no definition.
     * The second pass only runs when this fired, so a document whose
     * references all resolved parses exactly once.
     */
    public function markCollapsedReferenceUnresolved(string $label = ''): void
    {
        $this->state->session->sawUnresolvedCollapsedReference = true;
        if ($label === '') {
            // An inline parser outside this package may not pass one.
            $this->state->session->unresolvedReferenceLabelUnknown = true;

            return;
        }

        $this->state->session->unresolvedReferenceLabels[$this->headingLabelKey(
            trim((string)preg_replace('/\s+/', ' ', $label)),
        )] = true;
    }

    /**
     * Heading references collected from the PARSED TREE, keyed by folded
     * heading text (PART 11 R1).
     *
     * @param array<string, array{0: string, 1: \MarkupCarve\Carve\Parser\ReferenceDefinition}> $references
     */
    public function seedHeadingReferences(array $references): void
    {
        foreach ($references as $folded => [, $reference]) {
            $this->state->session->headingReferencesByFoldedLabel[$folded] ??= $reference;
        }
    }

    public function addUndefinedReferenceWarning(string $ref, int $line, int $column): void
    {
        $this->addWarning(
            "Undefined reference '{$ref}'",
            $line,
            $column,
            false,
            'reference',
            "Define with [{$ref}]: url or use inline link",
        );
    }

    /**
     * Add warning for undefined footnote (called from InlineParser)
     */
    public function addUndefinedFootnoteWarning(string $label, int $line, int $column): void
    {
        $this->addWarning("Undefined footnote '{$label}'", $line, $column, false);
    }

    /**
     * Track an anchor link for validation (called from InlineParser)
     * Only tracks when collectWarnings is enabled.
     */
    public function trackAnchorLink(string $fragment, int $line, int $column): void
    {
        if ($this->collectWarnings) {
            $this->state->session->anchorLinks[] = [
                'fragment' => $fragment,
                'line' => $line,
                'column' => $column,
            ];
        }
    }

    /**
     * Validate anchor links point to existing IDs in the document
     *
     * Checks all links with `#fragment` destinations against:
     * - Heading IDs (from heading auto-references)
     * - Explicit `{#id}` attributes on any element
     */
    protected function validateAnchorLinks(Document $document): void
    {
        (new DocumentFinalizationPass($this->state))->validateAnchors(
            $document,
            $this->collectExplicitIds(...),
            $this->addWarning(...),
        );
    }

    /**
     * Recursively collect explicit {#id} attributes from the AST
     *
     * @param \MarkupCarve\Carve\Node\Node $node
     * @param array<string, bool> $ids
     */
    protected function collectExplicitIds(Node $node, array &$ids): void
    {
        if ($node->hasAttribute('id')) {
            $id = $node->getAttribute('id');
            if ($id !== null && $id !== '') {
                $ids[$id] = true;
            }
        }

        foreach ($node->getChildren() as $child) {
            $this->collectExplicitIds($child, $ids);
        }
    }
}
