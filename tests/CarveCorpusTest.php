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
     * Category prefixes the parser + renderer can produce byte-identical
     * HTML for. A prefix covers all its sub-examples (01-emphasis also
     * covers 01-emphasis-2 … 01-emphasis-7).
     *
     * @var array<string>
     */
    protected const IMPLEMENTED = [
    '01-emphasis',
    '02-headings',
    '03-links',
    '04-images',
    '05-lists',
    '06-task-lists',
    '07-blockquote-with-attribution',
    '08-image-with-caption',
    '09-tables',
    '10-tables-with-rowspan-and-colspan',
    '100-table-row-attributes',
    '101-table-header-cell-rowspan',
    '102-block-quote-continuation-marker',
    '103-heading-marker-column-zero',
    '104-paragraph-trailing-whitespace',
    '105-marker-line-nested-lists',
    '106-blocked-span-marker-renders-as-empty-cell',
    '107-colspan-marker-scans-left-past-a-consumed-cell',
    '108-security-hardening',
    '109-link-destination-parentheses-balance',
    '11-fenced-code',
    '110-empty-link-and-image-titles-are-preserved',
    '111-cross-references-resolve-inside-footnote-bodies',
    '112-unquoted-attribute-values-may-contain-dots-and-colons',
    '113-a-pipe-pair-with-no-cell-is-not-a-table',
    '114-adjacent-attribute-blocks-on-one-line-merge',
    '115-a-continuation-row-needs-a-body-row',
    '116-fence-opener-with-a-nested-list-body-inside-a-list-item',
    '117-footnote-definition-inside-a-container-is-collected',
    '118-cyclic-cross-reference-resolves-to-one-level',
    '119-trojan-source-heading-ids-are-nfc-normalized-and-strip-invisible-controls',
    '12-inline-code',
    '120-trojan-source-rendered-text-and-code-strip-bidi-override-controls',
    '121-scheme-probe-strips-unicode-whitespace',
    '122-footnotes-placement',
    '123-classes-are-deduplicated',
    '124-code-span-and-image-trailing-attributes-are-strict',
    '125-a-bare-attribute-block-on-its-own-line-is-literal',
    '126-a-backslash-in-a-link-destination-is-a-literal-character',
    '127-autolink-display-keeps-the-raw-content',
    '128-editorial-markup-takes-a-trailing-attribute',
    '129-emphasis-opener-slash-adjacency',
    '13-attributes',
    '130-bold-italic-delimiter-needs-content',
    '131-emphasis-span-closes-before-a-following-delimiter',
    '132-thematic-break-requires-contiguous-markers',
    '133-sublist-marker-interrupts-a-continuation-paragraph',
    '134-footnote-definition-requires-an-inline-body',
    '135-footnote-definition-separator-must-be-a-space',
    '136-link-reference-definition-separator-must-be-a-space',
    '137-abbreviation-definition-separator-must-be-a-space',
    '138-unclaimed-openers-stay-literal',
    '139-inline-literal',
    '14-frontmatter',
    '140-all-space-verbatim-content',
    '141-trailing-whitespace-boundaries',
    '142-table-row-closing-pipe',
    '143-post-blank-list-continuation-content-column-model',
    '144-nested-item-looseness-does-not-propagate-to-the-outer-item',
    '145-definition-list-as-a-first-class-block-opener',
    '146-table-as-a-block-opener-in-a-list-item',
    '147-adjacent-slash-and-underscore-emphasis-nest',
    '148-colon-fence-as-a-block-opener-in-a-list-item',
    '149-fence-folds-as-lazy-inline-code-above-the-content-column',
    '15-heading-ids',
    '150-abbreviation-title-escapes-its-markup-characters',
    '151-indented-ordered-marker-content-column-includes-the-marker-indent',
    '152-leading-attribute-brace-before-an-inline-span-stays-literal',
    '153-attribute-block-after-a-mention-stays-literal',
    '154-under-indented-definition-attaches-over-indented-definition-folds',
    '155-image-trailing-attribute-is-strict-about-the-glue',
    '156-wrapped-definition-term-continuation-below-the-content-column-strips-leading-whitespace',
    '157-indented-attribute-line-stays-literal',
    '158-indented-image-and-caption-stay-literal',
    '159-indented-reference-and-footnote-definitions-stay-literal',
    '16-reference-link',
    '160-indented-colon-fence-blocks-stay-literal',
    '161-below-content-column-div-body-in-a-list-item-stays-literal',
    '162-outer-item-with-an-internal-blank-before-an-attached-block-is-loose',
    '163-unresolved-footnote-reference-with-a-trailing-attribute-stays-literal',
    '164-tight-list-item-keeps-trailing-text-after-a-block-bare',
    '165-quote-flanking-after-an-escaped-character',
    '166-comment-fence-with-trailing-text',
    '167-unterminated-comment-fence',
    '168-widened-verbatim-fences',
    '169-only-the-id-hoists-to-the-section-wrapper',
    '17-collapsed-reference-link',
    '170-headings-inside-containers-are-not-wrapped',
    '171-attribute-order-on-an-unwrapped-heading',
    '172-attribute-braces-on-a-list-item-marker-line',
    '173-implicit-heading-references-with-no-definition',
    '174-bare-dot-ordered-markers',
    '18-unresolved-reference-link',
    '19-smart-typography-dashes-and-quotes',
    '20-smart-typography-arrows-and-symbols',
    '21-math',
    '22-footnotes',
    '23-inline-footnotes',
    '24-generic-divs',
    '25-definition-lists',
    '26-comments',
    '27-raw-blocks',
    '28-hard-line-breaks',
    '29-non-breaking-space',
    '30-raw-inline',
    '31-ordered-list-start-and-delimiter',
    '32-ordered-list-dialects',
    '33-editorial-markup',
    '34-thematic-breaks',
    '35-cross-reference',
    '36-autolinks',
    '37-escapes',
    '38-bare-urls-stay-literal',
    '39-inline-span',
    '40-superscript-and-subscript',
    '41-line-blocks',
    '42-admonitions',
    '43-abbreviations',
    '44-mentions-and-tags',
    '45-inline-extensions',
    '46-symbols',
    '47-numbered-cross-references',
    '48-table-column-alignment',
    '49-table-per-cell-alignment-override',
    '50-headerless-table-alignment',
    '51-table-without-alignment',
    '52-table-alignment-with-colspan',
    '53-table-doubled-alignment-marker',
    '54-fenced-code-shorter-inner-fence',
    '55-blockquote-caption-after-a-blank-line',
    '56-table-cell-escaped-pipe',
    '57-table-cell-pipe-inside-code-span',
    '58-abbreviation-matches-on-word-boundaries-only',
    '59-mention-ignores-email-addresses',
    '60-tag-requires-a-word-boundary',
    '61-table-stacked-rowspan',
    '62-smart-typography-escapes-and-code',
    '63-table-multi-line-cell-continuation',
    '64-table-rowspan-with-multi-line-content',
    '65-ordered-marker-vs-prose',
    '66-footnote-with-multiple-blocks',
    '67-empty-delimiters',
    '68-nested-containers',
    '69-opaque-spans-inside-a-container',
    '70-blocks-that-render-to-nothing',
    '71-attribute-edge-cases',
    '72-escape-coverage',
    '73-parenthesized-ordered-marker',
    '74-emphasis-edge-cases',
    '75-list-nesting-and-looseness',
    '76-doubled-emphasis-delimiters',
    '77-nested-brackets-in-link-text',
    '78-reference-labels-are-case-sensitive',
    '79-two-char-delimiter-runs',
    '80-trailing-attribute-block-edge-cases',
    '81-paragraph-interruption',
    '82-blockquote-lazy-continuation',
    '83-fenced-code-language-with-punctuation',
    '84-single-line-headings',
    '85-blockquote-lazy-continuation-stops-at-a-fenced-block',
    '86-list-lazy-continuation',
    '87-compact-list-blocks',
    '88-list-continuation-marker',
    '89-block-attribute-lines',
    '90-list-item-attributes',
    '91-mention-and-tag-name-boundaries',
    '92-superscript-in-a-table-cell',
    '93-nested-comment-fences',
    '94-strong-emphasis-starting-with-a-link',
    '95-abbreviation-definition-interrupts-a-paragraph',
    '96-literal-less-than-in-prose',
    '97-boolean-attributes',
    '98-table-span-marker-in-first-column',
    '99-table-cell-attributes',
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
        $base = preg_replace('/-\d+$/', '', $slug);
        foreach (self::IMPLEMENTED as $prefix) {
            if ($slug === $prefix || $base === $prefix) {
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
