<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Renderer;

use Closure;
use MarkupCarve\Carve\Node\Block\Heading;
use MarkupCarve\Carve\Node\Inline\CaptionNumber;
use MarkupCarve\Carve\Node\Inline\Code;
use MarkupCarve\Carve\Node\Inline\EscapedText;
use MarkupCarve\Carve\Node\Inline\HardBreak;
use MarkupCarve\Carve\Node\Inline\InlineExtension;
use MarkupCarve\Carve\Node\Inline\LiteralInline;
use MarkupCarve\Carve\Node\Inline\Math;
use MarkupCarve\Carve\Node\Inline\RawInline;
use MarkupCarve\Carve\Node\Inline\SmartPunctuation;
use MarkupCarve\Carve\Node\Inline\SoftBreak;
use MarkupCarve\Carve\Node\Inline\Span;
use MarkupCarve\Carve\Node\Inline\Symbol;
use MarkupCarve\Carve\Node\Inline\Text;
use MarkupCarve\Carve\Node\Node;
use MarkupCarve\Carve\Util\StringUtil;

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
     * Resolved heading id => a CLONE of the heading's inline nodes.
     *
     * PART 9R R4 says the label of a resolved cross-reference is the target
     * heading's inline NODES cloned, and the difference from a rendered string
     * is the whole point: a node carries its SOURCE RUN, a string does not.
     * Flattening here - which is what this class used to do, keeping only
     * `textById` - discarded the run before any renderer existed, so smart
     * typography's SOURCE mode could not recover it on any target and no
     * renderer change could reach it (markup-carve/carve#952).
     *
     * A CLONE rather than the live children, for the same reason `resolvedTexts`
     * is captured eagerly: an extension may append to the heading afterwards
     * (HeadingPermalinksExtension adds a permalink symbol), and the label is the
     * heading as the author wrote it. `Node::__clone()` is already deep.
     *
     * Only HEADING ids get an entry. A numbered caption registers its label
     * through `setTextForId()` as an already-composed string ("Figure 2"), which
     * has no nodes behind it, so those ids keep the string path.
     *
     * @var array<string, list<\MarkupCarve\Carve\Node\Node>>
     */
    protected array $nodesById = [];

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
            $this->nodesById[$id] = array_values((clone $node)->getChildren());
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
        $id = $this->dedupe($this->normalizeId($plainText));

        if (!isset($this->textById[$id])) {
            $this->textById[$id] = $plainText;
            $this->registerFoldedId($id);
        }

        return $id;
    }

    /**
     * Track $baseId and return it, or on collision the next free 1-based
     * suffix (second use -> -2, -> -3). Skips suffix candidates that are
     * already reserved - an explicit `{#Foo-2}` must not be silently
     * duplicated by an auto-id collision on `# Foo`.
     */
    protected function dedupe(string $baseId): string
    {
        if (!isset($this->usedIds[$baseId])) {
            $this->usedIds[$baseId] = 1;

            return $baseId;
        }

        do {
            $this->usedIds[$baseId]++;
            $candidate = $baseId . '-' . $this->usedIds[$baseId];
        } while (isset($this->usedIds[$candidate]));

        $this->usedIds[$candidate] = 1;

        return $candidate;
    }

    /**
     * Reserve a unique id in the document id namespace.
     *
     * Returns $baseId when free, otherwise the next free 1-based suffix
     * ($baseId-2, -3, ...), skipping candidates already reserved by explicit
     * attributes or previously generated ids.
     */
    public function uniqueId(string $baseId): string
    {
        return $this->dedupe($baseId);
    }

    /**
     * Display text of the heading owning $id, for </#id> cross-references.
     *
     * In SOURCE mode the text is re-derived from the heading's cloned NODES, so
     * a smart-typography substitution comes back as the run the author typed.
     * The `</#id>` label is a FLATTENED string on every target - corpus
     * `118-cyclic-cross-reference-resolves-to-one-level` pins `<a href="#B">B
     * </a>` for a heading that itself holds a cross-reference - so this walks
     * the nodes with the same reader the glyph path uses rather than handing
     * them to a renderer. What changes is which half of a SmartPunctuation node
     * is read, which is exactly the difference the mode names.
     */
    public function getTextForId(string $id, SmartTypographyMode $mode = SmartTypographyMode::Glyph): ?string
    {
        if ($mode === SmartTypographyMode::Source && isset($this->nodesById[$id])) {
            $text = '';
            foreach ($this->nodesById[$id] as $child) {
                $text .= $this->extractPlainTextFrom($child, true);
            }

            return $text;
        }

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
     * 1. NFC-normalize the text and strip bidi override/isolate +
     *    zero-width controls (Trojan-Source hardening, carve spec #117),
     *    then replace each maximal run of non-alphanumeric ASCII with a
     *    single '-' and trim; non-ASCII code points (>= U+0080) are kept
     *    verbatim and letter case is preserved (the default is
     *    case-preserving).
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
        // Trojan-Source hardening (carve spec #117): NFC-normalize and strip
        // bidi/zero-width controls so an id can never depend on invisible or
        // reordering code points, and so precomposed/decomposed spellings of
        // the same grapheme produce the same id.
        $id = $this->slugRun($this->deTypography(StringUtil::normalizeIdSource($text)));
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
     *
     * @param \MarkupCarve\Carve\Node\Node $node
     * @param bool $sourceRuns Read a smart-typography node's SOURCE RUN instead
     *   of its glyph. Only ever true for a cross-reference LABEL: a heading id
     *   is slugged from the glyph and must not move (see the branch below).
     */
    protected function extractPlainText(Node $node, bool $sourceRuns = false): string
    {
        $text = '';
        foreach ($node->getChildren() as $child) {
            $text .= $this->extractPlainTextFrom($child, $sourceRuns);
        }

        return $text;
    }

    /**
     * The plain text ONE inline node contributes.
     *
     * Split out of the loop above so a caller holding a node LIST - the cloned
     * heading children a cross-reference label is built from - reads it through
     * the same rules rather than through a second copy of them. The two used to
     * be one function, and a label derived anywhere else would have been a
     * second spelling of every branch here.
     *
     * @param \MarkupCarve\Carve\Node\Node $child
     * @param bool $sourceRuns See extractPlainText().
     */
    protected function extractPlainTextFrom(Node $child, bool $sourceRuns = false): string
    {
        return $this->inlineTextLeaf($child, $sourceRuns) ?? $this->extractPlainText($child, $sourceRuns);
    }

    /**
     * The text a node contributes ON ITS OWN, or null when it contributes only
     * through its children.
     *
     * The LEAF RULES live here and nowhere else. Three callers need them - the
     * id slug, a cross-reference label as text, and a cross-reference label as
     * NODES - and a second copy would be a second answer to "does an
     * `:index[]` marker count".
     *
     * @param \MarkupCarve\Carve\Node\Node $child
     * @param bool $sourceRuns See extractPlainText().
     */
    protected function inlineTextLeaf(Node $child, bool $sourceRuns = false): ?string
    {
        if ($child instanceof InlineExtension && $child->getExtensionType() === 'index') {
            // An `:index[term]` marker is invisible (§8.1): it emits no
            // visible text, so its term must not feed the heading-id slug.
            // Matches carve-js / carve-rs.
            return '';
        }
        if ($child instanceof Span && $child->hasClass('section-number')) {
            // The HeadingNumbers extension (Tier-3, #198) injects a
            // `<span class="section-number">` into the heading. It is
            // presentational only and must not feed the heading-id slug
            // (otherwise the number would pollute the auto id).
            return '';
        }
        if ($child instanceof Text) {
            return $child->getContent();
        }
        if ($child instanceof SmartPunctuation) {
            // THE ONLY BRANCH THE MODE REACHES, and the reason it takes a flag
            // rather than being decided by the caller's type: a heading id has
            // always been slugified from the RENDERED character (`Don't` ->
            // `Don-t`), so the id path stays on the glyph whatever a renderer
            // asks for. A cross-reference LABEL is presentation, not identity,
            // and PART 9R R4 gives it the heading's nodes precisely so the run
            // survives (markup-carve/carve#952).
            if ($sourceRuns) {
                return $child->getContent();
            }

            return $child->getGlyph() ?? SmartPunctuation::GLYPHS[$child->getKind()] ?? $child->getContent();
        }
        if ($child instanceof EscapedText) {
            return $child->getContent();
        }
        if ($child instanceof CaptionNumber) {
            return $child->getNumber() === null ? '' : (string)$child->getNumber();
        }
        if ($child instanceof SoftBreak || $child instanceof HardBreak) {
            return ' ';
        }
        if ($child instanceof Code || $child instanceof Math || $child instanceof LiteralInline) {
            // An inline literal renders as visible prose (§27), so it
            // contributes its content to the heading text -- otherwise
            // `` # !`Cat` `` would slug to the empty fallback and a
            // `</#cat>` crossref could never resolve.
            return $child->getContent();
        }
        if ($child instanceof Symbol) {
            return ':' . $child->getName() . ':';
        }
        if ($child instanceof RawInline) {
            // Format-specific raw HTML is excluded from heading
            // text/id (matches PlainTextRenderer behaviour).
            return '';
        }

        return null;
    }

    /**
     * The label of the heading owning $id, as NODES a renderer can still make
     * its own choices about.
     *
     * Returns null for an id that has no heading behind it (a numbered caption
     * registers a composed string), which is the caller's signal to keep the
     * string path.
     *
     * The sequence is FLAT - Text runs with the smart-typography nodes left
     * standing between them - because a `</#id>` label renders as plain text on
     * every target: corpus `118-cyclic-cross-reference-resolves-to-one-level`
     * pins `<a href="#B">B </a>` for a heading that itself holds a
     * cross-reference, so splicing the heading's own markup in would change what
     * the label IS, not just how it reads. What the nodes buy is that the one
     * decision a renderer legitimately owns - glyph or source run - is still
     * open when the renderer runs, instead of being answered here.
     *
     * @return list<\MarkupCarve\Carve\Node\Node>|null
     */
    public function getLabelNodesForId(string $id): ?array
    {
        if (!isset($this->nodesById[$id])) {
            return null;
        }

        $nodes = [];
        $buffer = '';
        $this->collectLabelNodes($this->nodesById[$id], $nodes, $buffer);
        if ($buffer !== '') {
            $nodes[] = new Text($buffer);
        }

        return $nodes;
    }

    /**
     * @param list<\MarkupCarve\Carve\Node\Node> $children
     * @param list<\MarkupCarve\Carve\Node\Node> $nodes
     * @param string $buffer
     */
    protected function collectLabelNodes(array $children, array &$nodes, string &$buffer): void
    {
        foreach ($children as $child) {
            if ($child instanceof SmartPunctuation) {
                if ($buffer !== '') {
                    $nodes[] = new Text($buffer);
                    $buffer = '';
                }
                $nodes[] = clone $child;

                continue;
            }

            $leaf = $this->inlineTextLeaf($child);
            if ($leaf !== null) {
                $buffer .= $leaf;

                continue;
            }

            $this->collectLabelNodes(array_values($child->getChildren()), $nodes, $buffer);
        }
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
        $this->nodesById = [];
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

        return $this->dedupe($baseId);
    }
}
