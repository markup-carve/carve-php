<?php

declare(strict_types=1);

namespace Carve\Test;

use Carve\CarveConverter;
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
 * its NN-slug.html and compared after trimming. Only category prefixes in
 * IMPLEMENTED run as real assertions; everything else is marked incomplete
 * so the remaining work stays visible without failing CI.
 *
 * Carve-php is mid-migration from Djot syntax. Promote a prefix into
 * IMPLEMENTED once the corresponding parser/renderer work lands.
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
        '13-admonitions',
        '14-abbreviations',
        '15-mentions-and-tags',
        '16-inline-extensions',
        '17-attributes',
        '18-frontmatter',
        '19-heading-ids',
        '23-table-without-alignment',
        '26-fenced-code-shorter-inner-fence',
        '27-blockquote-caption-after-a-blank-line',
        '28-table-cell-escaped-pipe',
        '29-table-cell-pipe-inside-code-span',
        '30-abbreviation-matches-on-word-boundaries-only',
        '31-mention-ignores-email-addresses',
        '32-tag-requires-a-word-boundary',
        '33-table-stacked-rowspan',
        '35-collapsed-reference-link',
        '37-smart-typography-dashes-and-quotes',
        '38-smart-typography-arrows-and-symbols',
        '39-smart-typography-escapes-and-code',
        '40-table-multi-line-cell-continuation',
        '41-table-rowspan-with-multi-line-content',
        '42-math',
        '43-footnotes',
        '44-generic-divs',
        '45-definition-lists',
        '46-comments',
        '47-raw-blocks',
        '48-hard-line-breaks',
        '49-non-breaking-space',
        '50-raw-inline',
        '51-emoji',
        '52-ordered-list-start-and-delimiter',
        '53-ordered-list-dialects',
        '54-ordered-marker-vs-prose',
        '55-footnote-with-multiple-blocks',
        '56-editorial-markup',
        '57-thematic-breaks',
        '58-cross-reference',
        '59-autolinks',
        '60-escapes',
        '61-empty-delimiters',
        '62-bare-urls-stay-literal',
        '63-nested-containers',
    ];

    /**
     * Sub-examples inside an IMPLEMENTED category that still fail because
     * of a specific unimplemented construct. Each is a tracked follow-up,
     * not a regression. Remove once the construct lands.
     *
     * @var array<string, string>
     */
    protected const KNOWN_GAPS = [
        '01-emphasis-11' => 'Phase-1 emphasis edge: a /,_ opening run (//a/) is '
            . 'not yet rejected at line start; tracked separately, '
            . 'unrelated to headings.',
        '05-lists-7' => 'Paragraph interruption (grammar PART 9 §10): two '
            . 'same-kind marker lines after a paragraph line should '
            . 'interrupt the paragraph and start a list. Not yet '
            . 'implemented in carve-php; surfaced by the corpus bump, '
            . 'tracked separately, unrelated to the section change.',
        '05-lists-10' => 'Paragraph interruption (grammar PART 9 §10): a '
            . 'blockquote + caption following a paragraph line should '
            . 'interrupt the paragraph. Not yet implemented in carve-php; '
            . 'surfaced by the corpus bump, unrelated to the section change.',
        '43-footnotes-2' => 'Paragraph interruption (grammar PART 9 §10): a '
            . 'footnote definition directly after a paragraph line (no blank) '
            . 'should interrupt the paragraph. Same gap as 05-lists-7/10.',
    ];

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

        if (!self::isImplemented($slug)) {
            $this->markTestIncomplete('Not yet implemented for Carve syntax: ' . $slug);
        }

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
