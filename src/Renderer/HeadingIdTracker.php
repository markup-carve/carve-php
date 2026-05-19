<?php

declare(strict_types=1);

namespace Carve\Renderer;

use Carve\Node\Block\Heading;
use Carve\Node\Inline\Code;
use Carve\Node\Inline\HardBreak;
use Carve\Node\Inline\Math;
use Carve\Node\Inline\RawInline;
use Carve\Node\Inline\SoftBreak;
use Carve\Node\Inline\Symbol;
use Carve\Node\Inline\Text;
use Carve\Node\Node;

/**
 * Shared service for generating and deduplicating heading IDs
 *
 * Used by HtmlRenderer, TableOfContentsExtension, and HeadingPermalinksExtension
 * to ensure consistent heading IDs across all consumers.
 *
 * Uses spl_object_id caching so the same heading node always returns the same ID
 * regardless of how many times it's queried.
 */
class HeadingIdTracker
{
    /**
     * Tracks used IDs for deduplication
     *
     * @var array<string, int>
     */
    protected array $usedIds = [];

    /**
     * Counter for auto-generated section IDs (when heading has no text)
     */
    protected int $sectionCounter = 0;

    /**
     * Cache of resolved IDs per heading node (keyed by spl_object_id)
     *
     * @var array<int, string>
     */
    protected array $resolvedIds = [];

    /**
     * Cache of plain text per node (keyed by spl_object_id)
     *
     * Caching ensures the first caller captures the original text before
     * any extensions modify the node tree (e.g., HeadingPermalinksExtension
     * appending a permalink symbol).
     *
     * @var array<int, string>
     */
    protected array $resolvedTexts = [];

    /**
     * Resolved heading id => heading plain text (for </#id> refs)
     *
     * @var array<string, string>
     */
    protected array $textById = [];

    /**
     * Get the unique ID for a heading node
     *
     * Returns a cached result if this heading has already been resolved.
     * Otherwise generates, deduplicates, and caches the ID.
     */
    public function getIdForHeading(Heading $node): string
    {
        $objectId = spl_object_id($node);
        if (isset($this->resolvedIds[$objectId])) {
            return $this->resolvedIds[$objectId];
        }

        $id = $this->generateId($node);
        $this->resolvedIds[$objectId] = $id;
        if (!isset($this->textById[$id])) {
            $this->textById[$id] = $this->getPlainText($node);
        }

        return $id;
    }

    /**
     * Plain text of the heading owning $id, for </#id> cross-references.
     */
    public function getTextForId(string $id): ?string
    {
        return $this->textById[$id] ?? null;
    }

    /**
     * Track an explicit ID from a non-heading element
     *
     * This prevents auto-generated heading IDs from conflicting
     * with explicitly set IDs on other elements.
     */
    public function trackId(string $id): void
    {
        if ($id !== '' && !isset($this->usedIds[$id])) {
            $this->usedIds[$id] = 1;
        }
    }

    /**
     * Normalize text to a Carve heading identifier (the normative
     * "Automatic Identifiers" algorithm):
     *
     * 1. Lowercase, Unicode-aware.
     * 2. Trim whitespace.
     * 3. Delete the CSS-unsafe punctuation ' " ; : (so "What's New"
     *    becomes "whats-new", not "what-s-new").
     * 4. Replace every maximal run of characters that are not Unicode
     *    letters/digits/_/- (spaces included) with a single '-'.
     * 5. Collapse runs of '-', then trim leading/trailing '-'.
     * 6. If the result starts with a digit, prefix 'section-' (a CSS
     *    identifier may not start with a digit).
     * 7. If the result is empty, the identifier is 'section'.
     *
     * Deduplication against the document namespace (shared by explicit
     * {#id} and generated ids) is applied by the caller.
     */
    public function normalizeId(string $text): string
    {
        // Carve "Automatic Identifiers" algorithm (normative).
        $id = mb_strtolower($text, 'UTF-8'); // 2. lowercase
        $id = trim($id); // 3. trim
        $id = str_replace(["'", '"', ';', ':'], '', $id); // 4. drop CSS-unsafe punct
        // 5/6. non letter/digit/_/- runs (incl. spaces) -> single '-'
        $id = preg_replace('/[^\p{L}\p{N}_-]+/u', '-', $id) ?? $id;
        $id = preg_replace('/-{2,}/', '-', $id) ?? $id; // 7. collapse
        $id = trim($id, '-'); // 7. trim '-'

        if ($id !== '' && preg_match('/^\p{N}/u', $id)) {
            $id = 'section-' . $id; // 8. digit-leading
        }

        return $id !== '' ? $id : 'section'; // 9. empty -> 'section'
    }

    /**
     * Get plain text content of a node
     *
     * For Heading nodes, the result is cached by spl_object_id so that
     * the original text is preserved even if extensions later modify
     * the heading's children (e.g., appending a permalink symbol).
     */
    public function getPlainText(Node $node): string
    {
        if ($node instanceof Heading) {
            $objectId = spl_object_id($node);
            if (isset($this->resolvedTexts[$objectId])) {
                return $this->resolvedTexts[$objectId];
            }

            $text = $this->extractPlainText($node);
            $this->resolvedTexts[$objectId] = $text;

            return $text;
        }

        return $this->extractPlainText($node);
    }

    /**
     * Recursively extract plain text from a node tree
     */
    protected function extractPlainText(Node $node): string
    {
        $text = '';
        foreach ($node->getChildren() as $child) {
            if ($child instanceof Text) {
                $text .= $child->getContent();
            } elseif ($child instanceof SoftBreak || $child instanceof HardBreak) {
                $text .= ' ';
            } elseif ($child instanceof Code || $child instanceof Math) {
                $text .= $child->getContent();
            } elseif ($child instanceof Symbol) {
                $text .= ':' . $child->getName() . ':';
            } elseif ($child instanceof RawInline) {
                // Format-specific raw HTML is excluded from heading
                // text/id (matches PlainTextRenderer behaviour).
                continue;
            } elseif ($child instanceof Node) {
                $text .= $this->extractPlainText($child);
            }
        }

        return $text;
    }

    /**
     * Reset all state (called per render)
     */
    public function reset(): void
    {
        $this->usedIds = [];
        $this->sectionCounter = 0;
        $this->resolvedIds = [];
        $this->resolvedTexts = [];
        $this->textById = [];
    }

    /**
     * Generate and deduplicate an ID for a heading
     */
    protected function generateId(Heading $node): string
    {
        // If heading has explicit id attribute, use it
        if ($node->hasAttribute('id')) {
            $idAttr = $node->getAttribute('id');
            $id = $idAttr ?? '';
            // Track explicit IDs so auto-generated IDs don't conflict
            if (!isset($this->usedIds[$id])) {
                $this->usedIds[$id] = 1;
            }

            return $id;
        }

        // Generate from heading text
        $headingText = $this->getPlainText($node);

        $baseId = $this->normalizeId($headingText);

        // Track and deduplicate. First use is bare; later collisions
        // take the next 1-based numeric suffix (second -> -2, -> -3).
        if (!isset($this->usedIds[$baseId])) {
            $this->usedIds[$baseId] = 1;

            return $baseId;
        }

        $this->usedIds[$baseId]++;

        return $baseId . '-' . $this->usedIds[$baseId];
    }
}
