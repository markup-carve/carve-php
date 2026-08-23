<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use function basename;
use function file_get_contents;
use function glob;
use function preg_replace;

/**
 * Runs the canonical Carve spec corpus (markup-carve/carve, vendored as a
 * git submodule at tests/spec) against CarveConverter.
 *
 * Mirrors the carve-js / carve-rs runners: every NN-slug.crv is paired with
 * its NN-slug.html and compared after trimming. Category prefixes in
 * IMPLEMENTED run as real assertions; a category that is neither IMPLEMENTED
 * nor an explicit KNOWN_GAP FAILS (so a new category from a corpus bump turns
 * CI red, which drafts the downstream bump PR). Defer a category via
 * KNOWN_GAPS; promote it into IMPLEMENTED once the parser/renderer work lands.
 */
#[Group('corpus')]
class CarveCorpusTest extends TestCase
{
    /**
     * Categories the parser + renderer can produce byte-identical HTML for.
     *
     * A corpus file is `NN-slug` or `NN-slug-VARIANT`. The CATEGORY is the SLUG
     * ALONE: the leading number is the spec's ordering, not an identity. Keyed
     * with the number, every corpus renumbering broke this guard at once for
     * ~170 categories that had not changed, and the fix was a mechanical
     * re-listing that reviewed as real work. Keyed by slug, a renumbering is
     * invisible here - which is what it is.
     *
     * A category covers all its sub-examples (`emphasis` also covers
     * `01-emphasis-2` … `01-emphasis-7`). The guard keeps doing its job: a NEW
     * category still fails until someone lists it. Mirrors carve-js#528 and
     * carve-rs#438.
     *
     * @var array<string>
     */
    protected const IMPLEMENTED = [
        'an-attribute-line-below-a-list-item-interrupts-it',
        'an-attributed-cell-keeps-its-attributes-and-its-literal-marker',
        'an-engine-written-shape-says-what-it-is-called',
        // ARRIVED WITH THE PIN BUMP THIS CHANGE CARRIES (carve#1455 needs the
        // corpus that states the backlink's accessible name, and the pin was
        // several rulings behind). Each renders byte-identically to its pinned
        // HTML on this engine; the three that do not are deferred in
        // KNOWN_GAPS below.
        'a-block-at-a-container-s-content-column-ends-the-paragraph-whatever-it-renders',
        'a-block-attached-after-an-invisible-line-leaves-the-item-tight',
        'a-boolean-attribute-does-not-start-with-an-underscore',
        'a-braced-hyphen-pair-is-an-en-dash',
        'a-bracketed-construct-s-identifiers-stay-on-one-line',
        'a-bracketed-construct-spanning-a-line-boundary',
        'a-bracketed-construct-spanning-a-verse-boundary',
        'a-closed-inline-construct-spanning-a-verse-boundary',
        'a-column-0-line-after-a-container-s-last-block-when-that-block-left-no-paragraph-open',
        'a-comment-fence-reached-through-a-quote-registers-nothing-either',
        'a-container-whose-table-ends-on-a-continuation-row',
        'a-continuation-marker-attaches-one-block-and-the-boundary-is-that-block-s-extent',
        'a-continuation-marker-attaches-only-a-flush-left-block',
        'a-continuation-row-s-open-run-and-an-escaped-closing-pipe',
        'a-definition-behind-an-alternating-container-prefix-registers-at-the-innermost-content-column',
        'a-floating-attribute-is-scoped-to-the-container-that-holds-it',
        'a-footnote-definition-s-block-runs-to-the-end-of-its-body',
        'a-heading-at-an-item-s-content-column-leaves-no-paragraph-open',
        'a-hyphen-run-opening-a-word-after-whitespace-is-a-flag',
        'a-label-beginning-with-an-at-sign-is-not-a-reference-label',
        'a-lazy-marker-line-s-definition-defines-nothing-in-any-container',
        'a-line-block-s-last-body-line-keeps-its-backslash',
        'a-marker-line-link-definition-is-collected-where-no-paragraph-is-open',
        'a-raw-block-keeps-the-blank-line-at-the-end-of-its-payload-too',
        'a-reference-definition-cannot-take-its-destination-from-the-next-line',
        'a-resumed-lazy-run-belongs-to-the-innermost-marker-line-item',
        'a-tab-after-a-fence-or-a-frontmatter-opener-depends-on-where-it-sits',
        'a-task-item-s-checkbox-is-not-decided-by-its-first-block',
        'a-terminal-comment-in-a-quote-leaves-no-paragraph-open',
        'a-terminal-comment-line-still-leaves-an-empty-verse-line',
        'an-abbreviation-definition-in-an-item-body-is-paragraph-text',
        'an-attribute-block-reaches-the-nested-list-it-precedes',
        'an-attribute-line-after-a-continuation-marker-attributes-the-attached-block',
        'an-empty-brace-pair-is-not-a-construct',
        'an-escaped-hash-keeps-its-escape-at-a-container-s-content-position',
        'an-unclosed-inline-literal-reaches-the-end-of-its-block',
        'an-unclosed-inline-run-in-a-line-block-reaches-the-end-of-the-block',
        'an-unclosed-verbatim-run-in-a-row-stops-at-the-closing-pipe',
        'an-unterminated-fence-at-a-content-column-opens-no-block-so-the-paragraph-stays-open',
        'delimited-comments',
        'only-lazy-folding-demotes-a-marker-line-colon-opener',
        'pipe-tables-can-state-head-and-foot-row-counts',
        'the-canonical-writer-glues-a-code-fence-to-its-info-string',
        'the-doubled-run-is-the-canonical-arrow-in-both-families',
        'what-a-content-column-block-does-not-reach',
        'which-inline-content-a-heading-id-is-derived-from',
        // carve#1384. All four quote-reachability documents already render
        // byte-identically here; listing the category makes the final spec pin
        // assert that behavior instead of treating a new category as unknown.
        'a-quote-is-reached-by-its-marker-and-a-column-never-reaches-into-one',
        'table-columns-carry-alignment-vertical-alignment-and-widths',
        'a-table-alignment-run-carries-two-independent-axes',
        'a-vertical-table-marker-needs-a-horizontal-partner',
        'a-table-cell-can-inherit-horizontal-alignment',
        'a-collected-definition-closes-the-item-paragraph',
        'an-all-blank-raw-payload-still-emits-its-line',
        'the-semantic-registry-holds-no-element-carve-already-spells',
        'a-language-attribute-is-exact-sugar-for-lang',
        'a-malformed-language-tag-leaves-the-whole-block-literal',
        'a-language-attribute-and-lang-are-one-key',
        'the-language-sigil-takes-no-padding',
        'a-boolean-lang-is-the-third-spelling-of-the-same-key',
        'a-semantic-name-renames-the-span-and-the-leftovers-ride-the-element',
        'a-derived-title-yields-to-an-authored-one',
        'a-structural-attribute-leads-the-author-s-own',
        'a-caret-line-does-not-end-a-paragraph-it-cannot-caption',
        'heading-index-plain-text-covers-visible-leaves-and-rejects-an-empty-key',
        'a-column-zero-definition-ends-an-open-list-item',
        'adjacent-block-openers-in-an-attached-run-stay-separate',
        'adjacent-sibling-lists-survive-the-round-trip',
        'an-empty-footnote-body-is-written-with-the-empty-sentinel',
        'a-caption-attaches-across-one-blank-line',
        'a-container-a-lazy-line-folded-into-is-still-open',
        'a-ragged-table-keeps-each-row-s-cell-count',
        'two-blank-lines-detach-a-caption',
        'the-inline-attribute-interior-is-space-only-the-attribute-line-is-not',
        'an-autolink-body-admits-non-ascii-and-excludes-format-characters',
        'a-real-div-in-a-container-and-the-flush-left-line-after-it',
        'the-flush-left-line-after-a-container-a-quoted-line-opened',
        'a-definition-body-continuation-indented-past-its-column-is-lazy-text',
        'trailing-whitespace-on-a-content-line-is-dropped',
        'a-definition-marker-s-separator-is-a-space-and-it-is-a-run',
        'a-reference-definition-is-anchored-at-end-of-line',
        'a-blank-line-holds-spaces-and-tabs-and-nothing-else',
        'a-code-fence-opener-takes-exactly-one-space',
        'a-frontmatter-opener-takes-exactly-one-space',
        'a-link-title-takes-exactly-one-space',
        'a-reference-definition-s-metadata-slots-take-exactly-one-space',
        'a-tab-continues-a-list-item-just-as-two-spaces-do',
        'an-absorbed-colon-fence-leaves-a-block-quote-s-paragraph-open',
        'code-fence-metadata-slots-must-be-a-space-too',
        'colon-fence-metadata-slots-must-be-a-space-too',
        'colon-fence-separator-must-be-a-space',
        'link-and-image-title-slots-must-be-a-space',
        'table-cell-padding-must-be-a-space',
        'a-backslash-in-a-link-destination-is-a-literal-character',
        'a-bare-attribute-block-on-its-own-line-is-literal',
        'a-continuation-row-needs-a-body-row',
        'a-marker-separator-is-a-space-never-a-tab',
        'a-tab-as-the-first-character-of-a-definition-term',
        'a-link-definition-written-before-a-footnote-stays-before-it',
        'a-zero-width-character-in-a-reference-definition-destination',
        'a-block-image-is-separated-from-the-block-after-it-on-every-target',
        'a-tab-indent-is-the-column-it-reaches-whatever-the-line-holds',
        'a-tab-separates-two-attributes-and-pads-a-block-as-a-space-does',
        'the-same-column-written-with-four-spaces',
        'sibling-markers-that-reach-one-column-are-one-list',
        'heading-index-plain-text-covers-visible-leaves-and-rejects-an-empty-key',
        'the-continuation-marker-at-an-item-s-own-column-and-what-follows-it',
        'a-continuation-marker-after-a-blank-line-in-the-item',
        'a-continuation-marker-after-a-blank-line-in-a-loose-item',
        'an-attribute-name-admits-no-colon',
        'an-inline-attribute-block-does-not-span-lines-but-an-attribute-line-does',
        'trailing-whitespace-after-a-block-marker',
        'a-multi-line-raw-block-is-placed-at-its-opening-and-verbatim-after-it',
        'an-abbreviation-term-is-one-ascii-alphanumeric-word',
        'a-tab-reaches-a-footnote-body-s-column-just-as-two-spaces-do',
        'a-footnote-body-s-last-block-when-it-is-not-a-paragraph-gets-a-synthesized-paragraph-for-the-backlink',
        'a-definition-attached-by-a-continuation-marker-is-collected-and-the-item-keeps-no-trace',
        'a-definition-inside-a-definition-list-dd-is-collected-and-the-entry-keeps-no-trace',
        'a-line-at-a-footnote-definition-s-own-column-followed-by-non-blank-text-forms-its-own-tight-block',
        'an-empty-abbreviation-term-is-not-a-definition',
        'an-at-sign-is-a-reference-label-character-everywhere-but-the-first-position',
        'a-tab-after-a-heading-quote-or-caption-marker-leaves-the-line-as-prose',
        'two-dashes-are-not-a-thematic-break',
        'two-backticks-are-not-a-code-fence-opening-or-closing',
        'a-single-percent-is-not-a-comment',
        'an-uppercase-roman-numeral-is-a-list-marker',
        'a-table-delimiter-cell-needs-at-least-one-dash',
        'a-continuation-row-carries-no-trailing-text',
        'a-format-character-before-a-scheme-is-not-stripped-and-is-inert',
        'a-pipe-pair-with-no-cell-is-not-a-table',
        'a-repeated-definition-which-one-wins',
        'abbreviation-definition-interrupts-a-paragraph',
        'abbreviation-definition-separator-must-be-a-space',
        'abbreviation-matches-on-word-boundaries-only',
        'abbreviation-title-escapes-its-markup-characters',
        'abbreviations',
        'adjacent-attribute-blocks-on-one-line-merge',
        'adjacent-slash-and-underscore-emphasis-nest',
        'admonitions',
        'all-space-verbatim-content',
        'attribute-block-after-a-mention-stays-literal',
        'attribute-braces-on-a-list-item-marker-line',
        'attribute-edge-cases',
        'attribute-order-on-an-unwrapped-heading',
        'attributes',
        'autolink-display-keeps-the-raw-content',
        'autolinks',
        'bare-dot-ordered-markers',
        'bare-urls-stay-literal',
        'below-content-column-div-body-in-a-list-item-stays-literal',
        'block-attribute-lines',
        'block-quote-continuation-marker',
        'blocked-span-marker-renders-as-empty-cell',
        'blockquote-caption-after-a-blank-line',
        'blockquote-lazy-continuation',
        'blockquote-lazy-continuation-stops-at-a-fenced-block',
        'blockquote-with-attribution',
        'blocks-that-render-to-nothing',
        'bold-italic-delimiter-needs-content',
        'boolean-attributes',
        'a-boolean-and-a-key-value-of-the-same-name-are-one-attribute',
        'classes-are-deduplicated',
        'code-span-and-image-trailing-attributes-are-strict',
        'collapsed-reference-link',
        'colon-fence-as-a-block-opener-in-a-list-item',
        'colspan-marker-scans-left-past-a-consumed-cell',
        'comment-fence-with-trailing-text',
        'comments',
        'compact-list-blocks',
        'cross-reference',
        'cross-references-resolve-inside-footnote-bodies',
        'cyclic-cross-reference-resolves-to-one-level',
        'definition-list-as-a-first-class-block-opener',
        'definition-lists',
        'doubled-emphasis-delimiters',
        'editorial-markup',
        'editorial-markup-takes-a-trailing-attribute',
        'emphasis',
        'emphasis-edge-cases',
        'emphasis-opener-slash-adjacency',
        'emphasis-span-closes-before-a-following-delimiter',
        'empty-delimiters',
        'empty-link-and-image-titles-are-preserved',
        'escape-coverage',
        'escapes',
        'fence-folds-as-lazy-inline-code-above-the-content-column',
        'fence-opener-with-a-nested-list-body-inside-a-list-item',
        'fenced-code',
        'fenced-code-language-with-punctuation',
        'fenced-code-shorter-inner-fence',
        'footnote-definition-inside-a-container-is-collected',
        'footnote-definition-requires-an-inline-body',
        'footnote-definition-separator-must-be-a-space',
        'footnote-with-multiple-blocks',
        'footnotes',
        'footnotes-placement',
        'frontmatter',
        'generic-divs',
        'hard-line-breaks',
        'headerless-table-alignment',
        'heading-ids',
        'heading-marker-column-zero',
        'headings',
        'headings-inside-containers-are-not-wrapped',
        'image-trailing-attribute-is-strict-about-the-glue',
        'image-with-caption',
        'images',
        'implicit-heading-references-with-no-definition',
        'indented-attribute-line-stays-literal',
        'indented-colon-fence-blocks-stay-literal',
        'indented-image-and-caption-stay-literal',
        'indented-ordered-marker-content-column-includes-the-marker-indent',
        'indented-reference-and-footnote-definitions-stay-literal',
        'inline-code',
        'inline-extensions',
        'inline-footnotes',
        'inline-literal',
        'inline-span',
        'leading-attribute-brace-before-an-inline-span-stays-literal',
        'line-blocks',
        'line-endings-and-a-byte-order-mark',
        'link-destination-parentheses-balance',
        'link-reference-definition-separator-must-be-a-space',
        'links',
        'list-continuation-marker',
        'list-item-attributes',
        'list-lazy-continuation',
        'list-nesting-and-looseness',
        'lists',
        'literal-less-than-in-prose',
        'marker-line-nested-lists',
        'math',
        'mention-and-tag-name-boundaries',
        'mention-ignores-email-addresses',
        'mentions-and-tags',
        'nested-brackets-in-link-text',
        'nested-comment-fences',
        'nested-containers',
        'nested-item-looseness-does-not-propagate-to-the-outer-item',
        'non-breaking-space',
        'numbered-cross-references',
        'only-the-id-hoists-to-the-section-wrapper',
        'opaque-spans-inside-a-container',
        'openers-past-the-nesting-cap-are-one-paragraph',
        'ordered-list-dialects',
        'ordered-list-start-and-delimiter',
        'ordered-marker-vs-prose',
        'outer-item-with-an-internal-blank-before-an-attached-block-is-loose',
        'paragraph-interruption',
        'paragraph-trailing-whitespace',
        'parenthesized-ordered-marker',
        'post-blank-list-continuation-content-column-model',
        'quote-flanking-after-an-escaped-character',
        'raw-blocks',
        'raw-inline',
        'reference-labels-are-case-sensitive',
        'reference-link',
        'scheme-probe-strips-unicode-whitespace',
        'security-hardening',
        'single-line-headings',
        'smart-typography-arrows-and-symbols',
        'smart-typography-dashes-and-quotes',
        'smart-typography-escapes-and-code',
        'strong-emphasis-starting-with-a-link',
        'sublist-marker-interrupts-a-continuation-paragraph',
        'superscript-and-subscript',
        'superscript-in-a-table-cell',
        'symbols',
        'table-alignment-with-colspan',
        'table-as-a-block-opener-in-a-list-item',
        'table-cell-attributes',
        'table-cell-escaped-pipe',
        'table-cell-pipe-inside-code-span',
        'table-column-alignment',
        'table-doubled-alignment-marker',
        'table-header-cell-rowspan',
        'table-multi-line-cell-continuation',
        'table-per-cell-alignment-override',
        'table-row-attributes',
        'table-row-closing-pipe',
        'table-rowspan-with-multi-line-content',
        'table-span-marker-in-first-column',
        'table-stacked-rowspan',
        'table-without-alignment',
        'tables',
        'tables-with-rowspan-and-colspan',
        'tag-requires-a-word-boundary',
        'task-lists',
        'thematic-break-requires-contiguous-markers',
        'thematic-breaks',
        'tight-list-item-keeps-trailing-text-after-a-block-bare',
        'trailing-attribute-block-edge-cases',
        'trailing-whitespace-boundaries',
        'trojan-source-heading-ids-are-nfc-normalized-and-strip-invisible-controls',
        'trojan-source-rendered-text-and-code-strip-bidi-override-controls',
        'two-abbreviation-definitions',
        'two-char-delimiter-runs',
        'unclaimed-openers-stay-literal',
        'under-indented-definition-attaches-over-indented-definition-folds',
        'unquoted-attribute-values-may-contain-dots-and-colons',
        'unresolved-footnote-reference-with-a-trailing-attribute-stays-literal',
        'unresolved-reference-link',
        'unterminated-comment-fence',
        'widened-verbatim-fences',
        'wrapped-definition-term-continuation-below-the-content-column-strips-leading-whitespace',
        'a-flush-left-line-needs-an-open-paragraph-to-fold-into',
        'a-list-item-does-not-define-an-abbreviation-either',
        'an-abbreviation-definition-is-recognized-only-at-document-level',
        'a-comment-is-recognized-at-any-column',
        'a-definition-below-every-content-column-folds-as-text',
        'a-caret-is-a-reference-label-not-an-empty-footnote',
        'an-invisible-line-does-not-cancel-a-blank-line-separation',
        'a-comment-fence-is-a-comment-at-any-column-too',
        'a-floating-attribute-stops-at-the-item-boundary',
        'a-comment-under-a-nested-item-does-not-close-it',
        'a-definition-inside-a-comment-registers-nothing',
        'a-blank-after-a-comment-still-ends-the-item',
        'a-comment-fence-under-a-nested-item-does-not-close-it-either',
        'a-collapsed-reference-is-matched-by-the-label-the-author-wrote',
        'an-abbreviation-at-a-list-item-s-content-column-is-still-not-a-definition',
        'a-definition-inside-a-container-is-collected-at-that-container-s-content-column',
        'trailing-attributes-on-a-link-reference-definition',
        'a-block-attribute-line-inside-a-quote-ends-the-paragraph-above-it',
        'a-collapsed-image-reference-uses-its-alt-text-as-the-label',
        'a-combined-bold-italic-span-may-cross-a-line',
        'a-comment-ends-the-paragraph-it-sits-under',
        'a-comment-fence-at-column-0-ends-the-item-a-line-does-not',
        'a-definition-on-a-footnote-body-s-continuation-line-is-collected',
        'a-description-line-needs-a-term-above-it',
        'a-div-does-not-define-an-abbreviation-either',
        'a-flush-left-line-after-a-footnote-definition-belongs-to-the-document',
        'a-footnote-body-holds-blocks-and-they-render-where-they-were-written',
        'a-heading-id-keeps-a-non-ascii-space',
        'a-heading-in-a-footnote-body-takes-an-id-but-no-section-wrapper',
        // The `[Café][]` half folds NFC, the `[file][]` half must NOT fold
        // compatibility - `# ﬁle` (U+FB01) stays unreachable. #836 landed the
        // folding half; this engine now renders the fixture byte-for-byte, so the
        // entry is the whole change here (carve#725, carve#729).
        'a-heading-reference-folds-unicode-normalization-but-not-compatibility',
        'a-marker-attribute-may-hold-a-quoted-brace',
        'a-nested-list-in-a-footnote-body-stays-nested',
        'a-quote-marker-is-plus-a-space-and-a-lazy-line-keeps-its-own-text',
        'a-reference-image-takes-a-caption',
        'a-tag-inside-a-literal-brace-run-is-still-a-tag',
        'an-attribute-line-inside-a-footnote-body-attaches-inside-it',
        'an-image-takes-a-reference-the-way-a-link-does',
        'an-unresolved-image-reference-stays-literal',
        'an-unresolved-reference-image-takes-no-caption',
        'one-definition-serves-a-link-and-an-image',
        'a-footnote-body-s-own-column-is-two-and-a-third-column-is-its-text',
        'a-definition-below-a-footnote-body-s-column-is-the-document-s-own-text',
        'a-definition-past-a-footnote-body-s-column-is-the-body-s-own-text',
        'a-quoted-attribute-value-stops-at-the-newline',
        'a-collapsed-reference-reaches-a-heading-by-the-heading-s-rendered-text',
        'a-fence-opened-on-a-list-marker-line-body-below-the-content-column',
        'a-below-column-marker-after-a-comment-where-no-paragraph-is-open',
        'a-list-marker-at-the-content-column-inside-an-open-fence',
        'a-boundary-line-inside-an-open-fence-does-not-end-the-container',
        'a-fence-keeps-the-blank-line-at-the-end-of-its-content',
        'a-boolean-and-a-key-value-of-the-same-name-are-one-attribute',
        'two-attributes-need-a-separator-between-them',
        // Arrived with the same corpus bump as PART 11 §10e. Each names a rule
        // this engine already implements, from a fix that landed before the
        // corpus caught up: #1243 (math base class), #1241 (angle bracket) and
        // #1244 (abbr inside an inline container). All eleven documents render
        // byte-identically to their pinned HTML, so they are IMPLEMENTED rather
        // than deferred.
        'a-math-span-s-base-class-keeps-the-class-slot-in-place',
        'a-marker-glued-to-a-name-opens-nothing',
        'an-angle-bracket-is-escaped-only-where-it-opens-markup',
        'an-abbreviation-expands-inside-an-inline-container',
        // Arrived with the bump to the corpus that carries PART 9R R2. All ten
        // categories, 37 documents, render byte-identically to their pinned
        // HTML here, so each is IMPLEMENTED rather than deferred. The last one
        // is the rule this bump was made for: a footnote inside an unresolved
        // reference is not a reference (carve#1198), which this engine already
        // decides the way the clause states.
        'a-captioned-quote-holds-more-than-one-block',
        'an-empty-inline-note-is-literal',
        'a-multi-letter-ordered-marker-opens-no-list',
        'a-note-s-content-recognizes-no-note',
        'a-footnote-in-link-text-nests-the-anchors',
        'a-footnote-in-reference-link-text-nests-the-anchors-too',
        'a-note-body-s-own-references-resolve',
        'a-reference-link-s-text-survives-its-own-frame',
        'an-inline-note-s-content-resolves-after-the-note',
        'a-footnote-in-an-unresolved-reference-is-not-a-reference',
        'an-image-s-alt-text-closes-where-a-link-s-text-closes',
        'an-editorial-comment-s-bracket-is-content-not-the-close',
        'composite-figures',
        // PART 9 §5 T10 (carve#1226). All six documents render byte-identically
        // to their pinned HTML once the parser reads the marker run before the
        // attribute block, so the category is IMPLEMENTED rather than deferred.
        'cell-attributes-bind-after-the-kind-and-alignment-markers',
        'a-table-cell-s-marker-run-ends-at-a-space',
        // carve#1229. Both documents render byte-identically to their pinned
        // HTML already: the padding between a code fence's language, title and
        // label was never significant here, so the category arrives IMPLEMENTED
        // rather than deferred.
        'the-canonical-writer-glues-a-code-fence-to-its-info-string',
        // Arrived with the bump to carve 8a9724d. Four categories, 28 documents,
        // every one of them byte-identical to its pinned HTML here - verified
        // per document through CarveConverter and again through `bin/carve`
        // before being listed, because an unverified entry turns a real
        // divergence into a green run, which is the only thing this list is for.
        //
        // Each names a rule this engine already decides the way the clause
        // states, three of them from fixes that landed before the corpus caught
        // up: the `{% %}` comment spelling (carve#1247, carve-php#1318 for the
        // ProseMirror half), an attribute block above a no-blank-line nested
        // list (carve#1238, carve-php#1313), and a block attached after an
        // invisible line (carve#1266). The last is carve#1269: an abbreviation
        // line in an item body is the paragraph it renders, which PART 12 §7
        // rules by scope - carve-php#1319 and carve-php#1320 are its two halves.
        'delimited-comments',
        'an-attribute-block-reaches-the-nested-list-it-precedes',
        'a-block-attached-after-an-invisible-line-leaves-the-item-tight',
        'an-abbreviation-definition-in-an-item-body-is-paragraph-text',
        // Arrived with the bump to carve b6917ab. TEN categories came in; each
        // of their 69 documents was rendered through CarveConverter and diffed
        // against its pinned `.html` before anything was listed here, because an
        // entry asserts this engine implements the category - an unverified one
        // converts a real divergence into a green run, which is the only thing
        // this list exists to prevent.
        //
        // SIX are byte-identical throughout and are listed. The other four -
        // 326, 327, 329 and 333 - are not: they carry 18 documents this engine
        // renders differently, and every one of those is named individually in
        // KNOWN_GAPS below with the rule it is waiting on. The categories are
        // listed so their PASSING documents keep asserting; the failing ones are
        // deferred by name rather than by silence.
        'an-attribute-line-after-a-continuation-marker-attributes-the-attached-block',
        'an-unclosed-verbatim-run-in-a-row-stops-at-the-closing-pipe',
        'a-tab-after-a-fence-or-a-frontmatter-opener-depends-on-where-it-sits',
        'an-unclosed-inline-run-in-a-line-block-reaches-the-end-of-the-block',
        'which-inline-content-a-heading-id-is-derived-from',
        'a-label-beginning-with-an-at-sign-is-not-a-reference-label',
        // The four with per-document gaps. Listed for their passing documents.
        'a-column-0-line-after-a-container-s-last-block-when-that-block-left-no-paragraph-open',
        'a-continuation-marker-attaches-one-block-and-the-boundary-is-that-block-s-extent',
        'a-floating-attribute-is-scoped-to-the-container-that-holds-it',
        'a-continuation-row-s-open-run-and-an-escaped-closing-pipe',
        // Arrived with the bump to carve 8b80822: PART 9 §24 S2 and §28 make a
        // comment's body verbatim and invisible WHEREVER the fence sits, which
        // the corpus had only ever pinned at column 0. Every one of the seven
        // was rendered through CarveConverter and diffed against its `.html`
        // before it was listed; six of them failed and were fixed rather than
        // deferred, so KNOWN_GAPS stays empty.
        'a-comment-fence-at-an-item-s-content-column-registers-nothing-either',
        'a-footnote-definition-inside-an-item-s-comment-registers-nothing',
        'a-comment-fence-opened-on-an-item-s-marker-line-hides-its-body-too',
        'a-comment-fence-one-item-deeper-registers-nothing-either',
        'a-wider-comment-fence-inside-an-item-hides-its-body-the-same-way',
        'an-abbreviation-inside-a-comment-defines-nothing',
        'a-comment-fence-inside-a-colon-container-registers-nothing',
        // Arrived with the bump to carve 483bcea: PART 9 §25 probes a URL-list
        // attribute at every candidate rather than at its head. All TEN
        // documents were rendered through CarveConverter and diffed against
        // their `.html` before this line was added; seven of them failed and
        // were fixed rather than deferred, so KNOWN_GAPS stays empty.
        'url-list-attributes-are-probed-token-wise',
        // Arrived with the bump to carve 5866bd0: PART 11 §8b M2b measures a
        // line's content position AFTER its container prefix, so `> \# heading`
        // keeps the escape the author wrote instead of coming back through an
        // importer as a heading. Both documents were rendered through
        // CarveConverter and diffed against their `.html` and their `.md`
        // before this line was added; both failed and were fixed rather than
        // deferred, so KNOWN_GAPS stays empty. The narrowing is pinned in the
        // same pair and still holds: `> C\# is a language` and `- \#tag rest`
        // drop their escapes exactly as they do outside a container.
        'an-escaped-hash-keeps-its-escape-at-a-container-s-content-position',
        // PART 9 §23 (carve#1333): a comment-only body line is removed at the
        // BLOCK layer. Four documents; one failed - the shape with an unclosed
        // verbatim run above the comment, which is the whole ruling - and was
        // fixed rather than deferred. The other three are its controls.
        //
        // PART 11 §7c (carve#1334): a line block's hard break keeps its
        // backslash where a bare newline would be re-read. Three documents,
        // all of which the engine already RENDERED correctly and all of which
        // it WROTE wrongly, so the corpus test never saw them - the `.fmt`
        // fixtures in CarveFmtCorpusTest are what these three pin.
        //
        // So KNOWN_GAPS stays empty.
        'a-comment-only-line-in-a-line-block-is-removed-before-any-inline-run',
        'a-line-block-s-hard-break-keeps-its-backslash',
        // Arrived with the bump to carve 9015c3b, which restates PART 11 §7c as
        // a PROPERTY rather than a list of the cases where the bare newline is
        // unsafe: the writer spells a `hard_break` inside a line block bare
        // where, and only where, re-reading that newline yields the same tree.
        // The list did not reach a stanza's LAST line, whose boundary §23 never
        // hardens, so `a\` and `a  \` lost the break outright with no space
        // involved in either (carve#1340).
        //
        // Four documents, all four rendered through CarveConverter and diffed
        // against their `.html` AND their `.fmt` before this line was added.
        // All four already matched, because this engine derived the rule from
        // the property rather than from the bullets - which is what the clause
        // now says to do. KNOWN_GAPS stays empty.
        'a-line-block-s-last-body-line-keeps-its-backslash',
        // Arrived with the bump to carve 6af110c, which pins PART 9 §28 at the
        // one column the corpus had never reached: a definition inside a
        // comment fence written THROUGH A QUOTE. §28 names no column and no
        // container, and the column-0 and list-item spellings were already
        // pinned, so all three engines leaked through the only spelling nobody
        // had written down (carve#1341).
        //
        // Three documents, all three rendered through CarveConverter and diffed
        // against their `.html` before this line was added. All three already
        // matched: carve-php#1402 closed the leak a day before the corpus
        // arrived, and these are what keep it closed. KNOWN_GAPS stays empty.
        'a-comment-fence-reached-through-a-quote-registers-nothing-either',
        'a-comment-fence-reached-through-a-quote-registers-nothing-either-2',
        'a-comment-fence-reached-through-a-quote-registers-nothing-either-3',
        // Eighteen documents from three rulings, all rendered through
        // CarveConverter and diffed byte for byte against their `.html` before
        // these lines were added. All three needed engine work, none is
        // deferred, so KNOWN_GAPS stays empty.
        //
        // 348 is markup-carve/carve#1351: a line block hardens a soft break at
        // EVERY depth, so `*a` over `b*` puts the `<br>` inside the `<strong>`.
        // The engine contradicted itself here - the backslash spelling of the
        // same boundary already hardened at depth - and the exemption turns out
        // to be node-presence rather than depth.
        'a-closed-inline-construct-spanning-a-verse-boundary',
        // 349 is markup-carve/carve#1348: a table is a table however its last
        // row is spelled, so a container whose table ends on a CONTINUATION row
        // leaves no open paragraph for a column-0 line to continue - the same
        // answer the standard-row spelling already gave.
        'a-container-whose-table-ends-on-a-continuation-row',
        // 350 is markup-carve/carve#1350: an invisible line AT a container's
        // content column ends the paragraph rather than the container. Below
        // that column it is a lazy line and still folds, which is what keeps
        // corpus 183 and 214-2 pointing the other way.
        'a-definition-at-a-container-s-content-column',
        // The freeze at carve 287b4b8 (corpus 1239) added twenty-nine
        // documents; these are the eight categories among them. Every one was
        // rendered through CarveConverter and diffed byte for byte against its
        // `.html` before these lines were added, and the fifty `.fmt` fixtures
        // still match. None is deferred, so KNOWN_GAPS stays empty.
        //
        // carve#1352: a bracketed construct spans a line boundary like any
        // other inline content. 351 needed one engine change - a captioned
        // host was writing the FIGURE's indentation into the `alt` value, so
        // `![a` over `b](/i)` came back holding two spaces the author never
        // wrote (markup-carve/carve-php#1422). 352 and 353 already answered.
        'a-bracketed-construct-spanning-a-line-boundary',
        'a-bracketed-construct-s-identifiers-stay-on-one-line',
        'a-bracketed-construct-spanning-a-verse-boundary',
        // carve#1354: a continuation row joins the row above it whatever that
        // row's cells hold. Already answered - the reader half was never the
        // divergence.
        'a-continuation-row-joins-the-row-above-it-whatever-its-cells-hold',
        // carve#1348 reaching the joined-header spelling. Already answered by
        // the container half landed for corpus 349.
        'a-container-whose-table-ends-on-a-joined-header-row',
        // carve#1355: a quote is asked its own body, and that body may be a
        // QUOTE. The lazy tracker read an inner `> # H` as prose - it starts
        // with `>` and not `#` - so the outer quote reported an open paragraph
        // the flush-left line folded into, while the same heading one level up
        // already ended it.
        'a-quote-inside-a-quote-is-asked-what-it-ends-on',
        // carve#1364 and carve#1357: at a container's content column a block
        // ends the paragraph it sits under, and WHAT IT RENDERS IS NOT A
        // PARAMETER. This is the family markup-carve/carve-php#1421 was filed
        // for: one flag answered both "is a paragraph open" and "is the
        // container still collecting", so closing the paragraph for an
        // invisible block also ended the item, which corpus 197 and 277 refuse.
        // The two questions are separate now.
        'a-block-at-a-container-s-content-column-ends-the-paragraph-whatever-it-renders',
        'what-a-content-column-block-does-not-reach',
        // carve#1363: THE BLOCK'S EXTENT IS THE DEFINITION'S, BLANK LINES AND
        // ALL. A blank inside a footnote body separates the NOTE's own blocks
        // rather than ending it, so the item ends and the flush-left line is
        // top-level. Three passes had to agree: the prepass that collects the
        // note, the item's own line collection, and the trailing-block tracker
        // - the prepass stopping at the blank while the block parser skipped
        // past it is what made the second block leave the document entirely.
        //
        // The second document is the LINK-REFERENCE CONTROL and is required
        // rather than incidental: a link definition has NO body, so it must not
        // open a body run. That difference is the whole rule, and it is what
        // catches a fix written one construct too wide.
        'a-footnote-definition-s-block-runs-to-the-end-of-its-body',
        // markup-carve/carve#1372, four documents. The ruled link case and its
        // FOOTNOTE kind, plus two controls this engine already answered before
        // the fix - the heading at the same column, and the peeled spelling the
        // ruling argues from. All four render byte-identically to their pinned
        // HTML here, verified per document through CarveConverter and again
        // through `bin/carve`, because an unverified entry in this list turns a
        // real divergence into a green run.
        'a-definition-behind-an-alternating-container-prefix-registers-at-the-innermost-content-column',
        // markup-carve/carve#1375, five documents, and markup-carve/carve#1379,
        // three. Both arrived with the bump to carve 275d99d and both were
        // already answered here: the first by
        // markup-carve/carve-php#1439's continuation-row fix, the second by a
        // reading this engine had before the ruling named it - the spec run
        // that filed it records carve-php as a fourth agreeing reader it had
        // not measured. Every document was rendered and compared before being
        // listed.
        'a-paragraph-opened-after-a-block-in-an-item-is-still-open-for-a-lazy-line',
        'an-unterminated-container-does-not-extend-the-item-past-a-blank-line',
        // markup-carve/carve#1385. A task item's checkbox is not decided by its
        // first block, and the document pins its PLACEMENT rather than merely
        // that it is emitted - the divergence an engine carries unnoticed
        // because it reads as whitespace. Rendered and compared before being
        // listed: this engine writes the checkbox on the `li` opener whether
        // the first block is a quote, a heading or a thematic break.
        'a-task-item-s-checkbox-is-not-decided-by-its-first-block',
        // markup-carve/carve#1386, two documents. Only lazy folding demotes a
        // marker-line colon opener - a blank below it does not. Both render
        // byte-identically here already; rendered and compared before listing.
        'only-lazy-folding-demotes-a-marker-line-colon-opener',
        // markup-carve/carve#1388, three documents, and the one category of the
        // bump to carve 22f7f47 that needed engine work here. §17 L1's first
        // disjunct is read at the LIST's level: a blank line with nothing of the
        // item after it separates the items however the item's interior
        // accounted for it. The category arrived with an OPEN engine window
        // naming this engine, closed by markup-carve/carve-php#1448 - a code or
        // a tilde fence with no closer absorbed the blank as a payload line, so
        // the list stayed tight where a div, an admonition, a raw block and a
        // comment fence all loosened. The third document is the §11 axis
        // control: a `*` after a `-` list opens a different list, so nothing is
        // followed by a blank before one of its OWN siblings and it stays tight.
        'a-blank-line-before-a-sibling-marker-separates-the-items-whatever-consumed-it',
        // markup-carve/carve#1377. A heading at an item's content column is a
        // bounded block and leaves no paragraph open for a flush-left line.
        'a-heading-at-an-item-s-content-column-leaves-no-paragraph-open',
        // The bump to carve e88d6e3. Six categories, ten documents, and every
        // one renders byte-identically to its pinned HTML here - each names a
        // ruling this engine already decides the way the clause states, so all
        // six are IMPLEMENTED rather than deferred.
        //
        // markup-carve/carve#1526 and #1542: a container's span - and a
        // definition list's - ends at its last PLACED child.
        'a-container-s-span-ends-at-its-last-placed-child',
        'a-definition-list-ends-at-its-last-placed-child-too',
        // markup-carve/carve#1516: an escape escalation reaches the block that
        // needed one, never the whole document.
        'a-leading-escaped-caret-keeps-its-escape',
        'an-idle-escape-does-not-spread-from-the-block-that-needed-one',
        // markup-carve/carve#1513: a hard list boundary is written as exactly
        // three blank lines.
        'a-longer-run-at-a-list-boundary-is-written-as-exactly-three-blank-lines',
        // markup-carve/carve#1525: a null byte is replaced before the document
        // is read.
        'a-null-byte-is-replaced-before-the-document-is-read',
        // The same bump, second half. markup-carve/carve#1550: a container's
        // span starts at its OPENING MARKUP even where its first child is
        // unplaced. markup-carve/carve#1548 pins what carve-php#1576 already
        // fixed here - a marker at an item's content column opens a sublist
        // whether or not it is the first thing in the item. Both render
        // byte-identically to their pinned HTML, so both are IMPLEMENTED.
        'a-container-starts-at-its-opening-markup-even-where-its-first-child-is-unplaced',
        'a-marker-at-an-item-content-column-opens-a-sublist-first-in-the-item-or-not',
        // ARRIVED WITH THE BUMP TO carve d0b6c92. Each renders byte-identically
        // to its pinned HTML on this engine already, so all five are declared
        // rather than deferred - the bump found no reader defect here.
        // markup-carve/carve#1564: a container ends at the markup that closes
        // it even where its last child is unplaced.
        'a-container-ends-at-the-markup-that-closes-it-even-where-its-last-child-is-unplaced',
        // markup-carve/carve#1570: the writer escapes per opener OCCURRENCE,
        // which carve-php#1578 had already landed.
        'an-idle-escape-does-not-spread-from-the-occurrence-that-needed-one',
        // markup-carve/carve#1583: a caption's marker separator is a run and
        // none of it is content, and a quoted figure indents like any other
        // nested block. carve-php#1594 landed the caption half.
        'a-caption-s-marker-separator-is-a-run-and-none-of-it-is-content',
        'a-quote-holding-a-captioned-block-indents-it-like-any-other-nested-block',
        // markup-carve/carve#1587: a heading's marker separator is a run and
        // none of it is content. The READER already agreed; the WRITER dropped
        // the leading tab of `406-3`, which is fixed in this change rather
        // than deferred.
        'a-heading-s-marker-separator-is-a-run-and-none-of-it-is-content',
    ];

    /**
     * Sub-examples inside an IMPLEMENTED category that still fail because
     * of a specific unimplemented construct. Each is a tracked follow-up,
     * not a regression. Remove once the construct lands.
     *
     * EMPTY, AND THAT IS THE POINT. An entry here is an EXCLUSION: the document
     * is named, its assertion is skipped, and the suite goes green around it.
     * Nothing in the pins may be excluded, so a new gap is closed rather than
     * listed.
     *
     * The thirteen entries that stood here up to the bump to carve d0b6c92 all
     * rendered byte-identically to their pinned HTML - measured one document at
     * a time against BOTH pins, so they were stale before the bump too, not by
     * it. Each was skipping an assertion the engine passes. They are deleted
     * rather than re-worded: a deferral nobody re-measures is how a guard stops
     * guarding.
     *
     * @var array<string, string>
     */
    protected const KNOWN_GAPS = [];

    /**
     * Documents this engine renders per the CURRENT spec, which the PINNED
     * corpus predates.
     *
     * The mirror of KNOWN_GAPS: that list is for a rule the engine has not
     * caught up to, this one for a rule it reached first. Each entry FAILS IN
     * BOTH DIRECTIONS - the output must equal what the spec now states, so an
     * engine regression is caught exactly as the corpus would have caught it,
     * and it must still DIFFER from the pinned golden, so an entry that went
     * stale when the submodule bumped fails and has to be deleted with it.
     *
     * EMPTY: the pin has caught up everywhere. The one entry that stood here,
     * for carve#1442's arrow rule, named a document that was ALSO in
     * KNOWN_GAPS - and the gap is consulted first, so the entry was skipped
     * before either of its two assertions ran. It could not have failed in
     * either direction, which is what `testNoCaseIsBothDeferredAndAheadOfPin`
     * below now refuses.
     *
     * @var array<string, array{reason: string, html: string}>
     */
    protected const AHEAD_OF_PIN = [];

    protected CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    /**
     * @throws \RuntimeException
     *
     * @return array<string, array{slug: string, crv: string, html: string}>
     */
    public static function corpusProvider(): array
    {
        $dir = __DIR__ . '/spec/tests/corpus';
        $crvFiles = glob($dir . '/*.crv') ?: [];
        if ($crvFiles === []) {
            throw new RuntimeException(
                "Carve spec corpus not found at {$dir}.\n"
                . "Initialize the submodule:\n  git submodule update --init",
            );
        }

        $cases = [];
        foreach ($crvFiles as $crvPath) {
            $slug = basename($crvPath, '.crv');
            $htmlPath = $dir . '/' . $slug . '.html';
            if (!file_exists($htmlPath)) {
                continue;
            }
            $cases[$slug] = [
                'slug' => $slug,
                'crv' => (string)file_get_contents($crvPath),
                'html' => (string)file_get_contents($htmlPath),
            ];
        }

        return $cases;
    }

    protected static function isImplemented(string $slug): bool
    {
        [$named, $base] = self::categoryNames($slug);
        foreach (self::IMPLEMENTED as $category) {
            if ($named === $category || $base === $category) {
                return true;
            }
        }

        return false;
    }

    /**
     * Return exactly the two category names an IMPLEMENTED entry may match.
     *
     * @return array{string, string}
     */
    private static function categoryNames(string $slug): array
    {
        $named = (string)preg_replace('/^\d+-/', '', $slug);
        $base = (string)preg_replace('/-\d+$/', '', $named);

        return [$named, $base];
    }

    /**
     * AN ENTRY THAT NAMES NOTHING IS NOT A PASS.
     *
     * The assertions behind AHEAD_OF_PIN run only for an entry whose slug is IN
     * the corpus, so a declaration left behind after an upstream RENAME matches
     * no case, runs no assertion, and reads as coverage. markup-carve/carve#1162
     * renamed `293-a-semantic-span-keeps-its-wrapper-…` to
     * `293-a-semantic-name-renames-the-span-…`; entries naming the old slug
     * would have gone on "passing" while checking nothing in either direction.
     */

    /**
     * A CASE IN BOTH LISTS IS IN NEITHER.
     *
     * `testCorpus()` consults KNOWN_GAPS first and returns, so a slug named by
     * both constants is skipped before AHEAD_OF_PIN's two assertions run. The
     * entry then reads as a pinned divergence while checking nothing, and stays
     * readable that way after the divergence closes - which is exactly what
     * carve#1442's entry did until the bump to carve d0b6c92 was worked.
     */
    public function testNoCaseIsBothDeferredAndAheadOfPin(): void
    {
        $both = array_values(array_intersect(array_keys(self::AHEAD_OF_PIN), array_keys(self::KNOWN_GAPS)));

        self::assertSame(
            [],
            $both,
            'Case(s) in KNOWN_GAPS and AHEAD_OF_PIN at once: ' . implode(', ', $both)
                . ' - the gap is consulted first, so the AHEAD_OF_PIN assertions never run.',
        );
    }

    public function testAheadOfPinNamesOnlyCasesThatExist(): void
    {
        $slugs = array_keys(self::corpusProvider());
        $orphaned = array_values(array_diff(array_keys(self::AHEAD_OF_PIN), $slugs));

        self::assertSame(
            [],
            $orphaned,
            'AHEAD_OF_PIN names case(s) the corpus does not have: ' . implode(', ', $orphaned)
                . ' - renamed upstream, or already retired; either way the entry asserts nothing.',
        );
    }

    public function testImplementedAndKnownGapsNameCorpusCases(): void
    {
        $slugs = array_keys(self::corpusProvider());
        $categories = [];
        foreach ($slugs as $slug) {
            foreach (self::categoryNames($slug) as $category) {
                $categories[$category] = true;
            }
        }

        $orphanedImplemented = array_values(array_diff(self::IMPLEMENTED, array_keys($categories)));
        $orphanedGaps = array_values(array_diff(array_keys(self::KNOWN_GAPS), $slugs));

        self::assertSame(
            [],
            $orphanedImplemented,
            'IMPLEMENTED names category or categories the corpus does not have: '
                . implode(', ', $orphanedImplemented)
                . ' - renamed upstream, or already retired; either way the entry asserts nothing.',
        );
        self::assertSame(
            [],
            $orphanedGaps,
            'KNOWN_GAPS names case(s) the corpus does not have: ' . implode(', ', $orphanedGaps)
                . ' - renamed upstream, or already retired; either way the entry defers nothing.',
        );
    }

    #[DataProvider('corpusProvider')]
    public function testCorpus(string $slug, string $crv, string $html): void
    {
        if (isset(self::KNOWN_GAPS[$slug])) {
            $this->markTestIncomplete(self::KNOWN_GAPS[$slug]);
        }

        // A corpus category that is neither IMPLEMENTED nor an explicit
        // KNOWN_GAP is a real gap: fail rather than silently skip. A new
        // category arriving via a corpus bump therefore turns CI red, which
        // opens the downstream bump PR as a draft (carve bump-downstream
        // workflow). Defer a category by listing it in KNOWN_GAPS; promote it
        // into IMPLEMENTED once the parser/renderer work lands.
        $this->assertTrue(
            self::isImplemented($slug),
            'Corpus category not implemented (add to IMPLEMENTED, or KNOWN_GAPS to defer): ' . $slug,
        );

        $actual = $this->converter->convert($crv);

        if (isset(self::AHEAD_OF_PIN[$slug])) {
            $ahead = self::AHEAD_OF_PIN[$slug];
            $this->assertSame(
                $this->normalize($ahead['html']),
                $this->normalize($actual),
                $ahead['reason'] . ' (ahead of the pinned corpus): ' . $slug,
            );
            $this->assertNotSame(
                $this->normalize($html),
                $this->normalize($actual),
                $slug . ' now matches the pinned corpus: delete its AHEAD_OF_PIN entry',
            );

            return;
        }

        $this->assertSame(
            $this->normalize($html),
            $this->normalize($actual),
            'Corpus mismatch for ' . $slug,
        );
    }

    protected function normalize(string $s): string
    {
        $s = (string)preg_replace('/[ \t]+$/m', '', $s);

        return rtrim($s, "\n");
    }
}
