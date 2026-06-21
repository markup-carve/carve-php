<?php

declare(strict_types=1);

namespace Carve\Renderer;

use Carve\Node\Block\Heading;
use Carve\Node\Inline\CaptionNumber;
use Carve\Node\Inline\Code;
use Carve\Node\Inline\EscapedText;
use Carve\Node\Inline\HardBreak;
use Carve\Node\Inline\Math;
use Carve\Node\Inline\RawInline;
use Carve\Node\Inline\SoftBreak;
use Carve\Node\Inline\Symbol;
use Carve\Node\Inline\Text;
use Carve\Node\Node;
use Closure;

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
     * Folded id => first registered id, for case-insensitive cross-reference
     * lookup without refolding every known id for each reference.
     *
     * @var array<string, string>
     */
    protected array $idByFoldedId = [];

    /**
     * Optional transform applied to the base slug (e.g. ASCII
     * transliteration). Set by AsciiHeadingIdsExtension; null leaves
     * non-ASCII characters in the id verbatim (the default).
     */
    protected ?Closure $idTransformer = null;

    /**
     * When true, the base slug is lowercased per code point (no whole-string
     * context mappings such as Greek final-sigma) after the idTransformer
     * step. Off by default: Carve heading ids are case-preserving.
     */
    protected bool $lowercase = false;

    public function setIdTransformer(?Closure $idTransformer): void
    {
        $this->idTransformer = $idTransformer;
    }

    /**
     * Enable opt-in per-code-point lowercasing of generated heading ids
     * (GitHub/SSG-style anchors). Default is case-preserving.
     */
    public function setLowercase(bool $lowercase): void
    {
        $this->lowercase = $lowercase;
    }

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
            $this->registerFoldedId($id);
        }

        return $id;
    }

    /**
     * Resolve an id directly from already-extracted plain text, with the same
     * slugging and 1-based collision suffixing as getIdForHeading(). Lets a
     * caller skip building and inline-parsing a Heading node when the heading
     * text is known to be plain (no inline markup) -- the id is identical.
     */
    public function getIdForText(string $plainText): string
    {
        $baseId = $this->normalizeId($plainText);

        if (!isset($this->usedIds[$baseId])) {
            $this->usedIds[$baseId] = 1;
            $id = $baseId;
        } else {
            $this->usedIds[$baseId]++;
            $id = $baseId . '-' . $this->usedIds[$baseId];
        }

        if (!isset($this->textById[$id])) {
            $this->textById[$id] = $plainText;
            $this->registerFoldedId($id);
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
     * Register cross-reference display text for a non-heading id.
     */
    public function setTextForId(string $id, string $text): void
    {
        if ($id !== '' && !isset($this->textById[$id])) {
            $this->textById[$id] = $text;
            $this->registerFoldedId($id);
        }
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
     * "Automatic Identifiers" algorithm, carve spec #73):
     *
     * 1. Replace each maximal run of non-alphanumeric ASCII with a
     *    single '-' and trim; non-ASCII code points (>= U+0080) are kept
     *    verbatim and letter case is preserved. There is NO Unicode
     *    (NFC) normalization step -- the default is case-preserving.
     * 2. If an id transformer is set (AsciiHeadingIdsExtension), apply
     *    it to the slug and re-run step 1 (opt-in ASCII transliteration).
     * 3. If lowercasing is enabled (opt-in), lowercase the slug PER CODE
     *    POINT so no whole-string context mapping (e.g. Greek
     *    final-sigma) applies. Off by default.
     * 4. If the result starts with a Unicode number, prefix 's-' (a CSS
     *    identifier may not start with a digit).
     * 5. If the result is empty, the identifier is 's'.
     *
     * Cross-reference resolution is case-insensitive (see
     * findIdCaseInsensitive()), so `</#getting-started>` still resolves
     * to a case-preserved `Getting-Started` id.
     *
     * Deduplication against the document namespace (shared by explicit
     * {#id} and generated ids) is applied by the caller.
     */
    public function normalizeId(string $text): string
    {
        $id = $this->slugRun($this->deTypography($text));
        if ($this->idTransformer !== null) {
            $id = $this->slugRun(($this->idTransformer)($id));
        }

        if ($this->lowercase) {
            $id = $this->foldCase($id);
        }
        if ($id !== '' && preg_match('/^\p{N}/u', $id)) {
            $id = 's-' . $id;
        }

        return $id !== '' ? $id : 's';
    }

    /**
     * Per-code-point lowercase fold (no whole-string context mappings such
     * as Greek final-sigma), so opt-in lowercasing and case-insensitive
     * cross-reference matching stay portable across implementations.
     */
    protected function foldCase(string $text): string
    {
        return (string)preg_replace_callback(
            '/./us',
            static fn (array $m): string => mb_strtolower($m[0], 'UTF-8'),
            $text,
        );
    }

    /**
     * Resolve a `</#id>` cross-reference target case-insensitively: return
     * the actual (verbatim) heading id whose case-folded form matches
     * $target, with the exact match preferred and first-occurrence winning
     * otherwise. Returns null when no heading id matches.
     */
    public function findIdCaseInsensitive(string $target): ?string
    {
        if (isset($this->textById[$target])) {
            return $target;
        }

        return $this->idByFoldedId[$this->foldCase($target)] ?? null;
    }

    protected function registerFoldedId(string $id): void
    {
        $this->idByFoldedId[$this->foldCase($id)] ??= $id;
    }

    /**
     * jgm/djot#393 slug step: replace each maximal run of
     * non-alphanumeric ASCII with a single '-' and trim. Non-ASCII
     * characters and letter case are preserved.
     */
    protected function slugRun(string $text): string
    {
        $text = preg_replace('/[^0-9A-Za-z\x{80}-\x{10FFFF}]+/u', '-', $text) ?? $text;

        return trim($text, '-');
    }

    /**
     * Reverse smart-typography substitutions to their ASCII source before a
     * slug is computed, so an id never depends on presentational typography
     * (`# That's all` keeps no curly `’`; `# Step 1 -> 2` keeps no `→`). The
     * map is the inverse of the parser's smart tokens plus smart quotes and
     * dashes; the recovered ASCII punctuation then collapses in slugRun().
     */
    protected function deTypography(string $text): string
    {
        return strtr($text, [
            '↔' => '<->',
            '™' => '(tm)',
            '…' => '...',
            '→' => '->',
            '←' => '<-',
            '⇒' => '=>',
            '≤' => '<=',
            '≥' => '>=',
            '≠' => '!=',
            '±' => '+-',
            '©' => '(c)',
            '®' => '(r)',
            '–' => '-',
            '—' => '-',
            '‘' => "'",
            '’' => "'",
            '“' => '"',
            '”' => '"',
        ]);
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
            } elseif ($child instanceof EscapedText) {
                $text .= $child->getContent();
            } elseif ($child instanceof CaptionNumber) {
                $text .= $child->getNumber() === null ? '' : (string)$child->getNumber();
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
        $this->idByFoldedId = [];
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
