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
        '11-fenced-code',
        '12-inline-code',
        '13-attributes',
        '14-frontmatter',
        '15-heading-ids',
        '16-reference-link',
        '17-collapsed-reference-link',
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
        '69-attribute-edge-cases',
        '70-escape-coverage',
        '71-parenthesized-ordered-marker',
        '72-emphasis-edge-cases',
        '73-list-nesting-and-looseness',
        '74-doubled-emphasis-delimiters',
        '75-nested-brackets-in-link-text',
        '76-reference-labels-are-case-sensitive',
        '77-two-char-delimiter-runs',
        '78-trailing-attribute-block-edge-cases',
        '79-paragraph-interruption',
        '80-blockquote-lazy-continuation',
        '81-fenced-code-language-with-punctuation',
        '82-multi-line-headings',
        '83-blockquote-lazy-continuation-stops-at-a-fenced-block',
        '84-list-lazy-continuation',
        '85-compact-list-blocks',
        '86-list-continuation-marker',
        '87-block-attribute-lines',
        '88-list-item-attributes',
        '89-mention-and-tag-name-boundaries',
        '90-superscript-in-a-table-cell',
        '91-nested-comment-fences',
        '92-strong-emphasis-starting-with-a-link',
        '93-abbreviation-definition-interrupts-a-paragraph',
        '94-literal-less-than-in-prose',
        '95-boolean-attributes',
        '96-table-span-marker-in-first-column',
        '97-table-cell-attributes',
        '98-table-row-attributes',
        '99-table-header-cell-rowspan',
        '100-block-quote-continuation-marker',
        '101-heading-marker-column-zero',
        '102-paragraph-trailing-whitespace',
        '103-marker-line-nested-lists',
        '104-blocked-span-marker-renders-as-empty-cell',
        '105-colspan-marker-scans-left-past-a-consumed-cell',
        '106-security-hardening',
        '107-link-destination-parentheses-balance',
        '108-empty-link-and-image-titles-are-preserved',
        '109-cross-references-resolve-inside-footnote-bodies',
        '110-unquoted-attribute-values-may-contain-dots-and-colons',
        '111-a-pipe-pair-with-no-cell-is-not-a-table',
        '112-adjacent-attribute-blocks-on-one-line-merge',
        '113-a-continuation-row-needs-a-body-row',
        '114-fence-opener-with-a-nested-list-body-inside-a-list-item',
        '115-footnote-definition-inside-a-container-is-collected',
        '116-cyclic-cross-reference-resolves-to-one-level',
        '117-trojan-source-heading-ids-are-nfc-normalized-and-strip-invisible-controls',
        '118-trojan-source-rendered-text-and-code-strip-bidi-override-controls',
        '119-scheme-probe-strips-unicode-whitespace',
        '120-footnotes-placement',
        '121-classes-are-deduplicated',
        '122-code-span-and-image-trailing-attributes-are-strict',
        '123-a-bare-attribute-block-on-its-own-line-is-literal',
        '124-a-backslash-in-a-link-destination-is-a-literal-character',
        '125-autolink-display-keeps-the-raw-content',
        '126-editorial-markup-takes-a-trailing-attribute',
        '127-emphasis-opener-slash-adjacency',
        '128-bold-italic-delimiter-needs-content',
        '129-emphasis-span-closes-before-a-following-delimiter',
        '130-thematic-break-requires-contiguous-markers',
        '131-sublist-marker-interrupts-a-continuation-paragraph',
        '132-footnote-definition-requires-an-inline-body',
        '133-footnote-definition-separator-must-be-a-space',
        '134-link-reference-definition-separator-must-be-a-space',
        '135-abbreviation-definition-separator-must-be-a-space',
        '136-unclaimed-openers-stay-literal',
        '137-inline-literal',
        '138-all-space-verbatim-content',
        '139-trailing-whitespace-boundaries',
        '140-table-row-closing-pipe',
        '141-post-blank-list-continuation-content-column-model',
        '142-nested-item-looseness-does-not-propagate-to-the-outer-item',
        '143-definition-list-as-a-first-class-block-opener',
        '144-table-as-a-block-opener-in-a-list-item',
        '145-adjacent-slash-and-underscore-emphasis-nest',
        '146-colon-fence-as-a-block-opener-in-a-list-item',
        '147-fence-folds-as-lazy-inline-code-above-the-content-column',
        '148-abbreviation-title-escapes-its-markup-characters',
        '149-indented-ordered-marker-content-column-includes-the-marker-indent',
        '150-leading-attribute-brace-before-an-inline-span-stays-literal',
        '151-attribute-block-after-a-mention-stays-literal',
        '152-under-indented-definition-attaches-over-indented-definition-folds',
        '153-image-trailing-attribute-is-strict-about-the-glue',
        '154-wrapped-definition-term-continuation-below-the-content-column-strips-leading-whitespace',
        '155-indented-attribute-line-stays-literal',
        '156-indented-image-and-caption-stay-literal',
        '157-indented-reference-and-footnote-definitions-stay-literal',
        '158-indented-colon-fence-blocks-stay-literal',
        '159-below-content-column-div-body-in-a-list-item-stays-literal',
        '160-outer-item-with-an-internal-blank-before-an-attached-block-is-loose',
        '161-unresolved-footnote-reference-with-a-trailing-attribute-stays-literal',
        '162-tight-list-item-keeps-trailing-text-after-a-block-bare',
        '163-quote-flanking-after-an-escaped-character',
        '164-comment-fence-with-trailing-text',
        '165-unterminated-comment-fence',
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
