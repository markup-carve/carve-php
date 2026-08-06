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
        'a-backslash-in-a-link-destination-is-a-literal-character',
        'a-bare-attribute-block-on-its-own-line-is-literal',
        'a-continuation-row-needs-a-body-row',
        'a-marker-separator-is-a-space-never-a-tab',
        'a-tab-as-the-first-character-of-a-definition-term',
        'a-link-definition-written-before-a-footnote-stays-before-it',
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
    ];

    /**
     * Sub-examples inside an IMPLEMENTED category that still fail because
     * of a specific unimplemented construct. Each is a tracked follow-up,
     * not a regression. Remove once the construct lands.
     *
     * @var array<string, string>
     */
    protected const KNOWN_GAPS = [];

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
        $named = preg_replace('/^\d+-/', '', $slug);
        $base = preg_replace('/-\d+$/', '', (string)$named);
        foreach (self::IMPLEMENTED as $category) {
            if ($named === $category || $base === $category) {
                return true;
            }
        }

        return false;
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
