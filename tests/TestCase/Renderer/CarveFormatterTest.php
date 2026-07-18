<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use function basename;
use function file_get_contents;
use function glob;
use function preg_replace;

#[Group('corpus')]
class CarveFormatterTest extends TestCase
{
    /**
     * @throws \RuntimeException
     *
     * @return array<string, array{slug: string, crv: string}>
     */
    public static function corpusProvider(): array
    {
        $dir = dirname(__DIR__, 2) . '/spec/tests/corpus';
        $crvFiles = glob($dir . '/*.crv') ?: [];
        if ($crvFiles === []) {
            throw new RuntimeException('Carve spec corpus not found at ' . $dir);
        }

        $cases = [];
        foreach ($crvFiles as $crvPath) {
            $slug = basename($crvPath, '.crv');
            $cases[$slug] = [
                'slug' => $slug,
                'crv' => (string)file_get_contents($crvPath),
            ];
        }

        return $cases;
    }

    #[DataProvider('corpusProvider')]
    public function testCorpusFormatsSemanticallyAndIdempotently(string $slug, string $crv): void
    {
        $formatted = CarveConverter::toCarve($crv);
        $reformatted = CarveConverter::toCarve($formatted);

        $converter = new CarveConverter();
        $this->assertSame(
            $this->normalizeHtml($converter->convert($crv)),
            $this->normalizeHtml($converter->convert($formatted)),
            'Formatted output changed rendered HTML for ' . $slug,
        );
        $this->assertSame($formatted, $reformatted, 'Formatter is not idempotent for ' . $slug);

        $converter->parse($formatted);
        $this->addToAssertionCount(1);
    }

    public function testTargetedFormattingRules(): void
    {
        $nbsp = "\u{00A0}";
        $cases = [
            "a\n\n\nb\n" => "a\n\nb\n",
            "+ item\n" => "\\+ item\n",
            "```\na ``` b\n```\n" => "````\na ``` b\n````\n",
            "{k=v .cls #id}\n# H\n" => "{k=v .cls #id}\n# H\n",
            "a  \n{$nbsp}\t \n" => "a\n{$nbsp}\n",
            "{.line-block}\n:::\na\nb\n:::\n" => "{.line-block}\n:::\na\nb\n:::\n",
            // `^sup^` / `,sub,` are literal text (no bare sup/sub delimiter):
            // the comma needs no escape; the caret keeps one (footnote/caption
            // channels). Braced forms round-trip unchanged.
            "a /em/ *strong* _u_ ~s~ {^sup^} {,sub,} =mark= `code`\n" =>
                "a /em/ *strong* _u_ ~s~ {^sup^} {,sub,} =mark= `code`\n",
            "a ^sup^ ,sub, stays literal\n" =>
                "a \\^sup\\^ ,sub, stays literal\n",
            // An unresolved reference image round-trips VERBATIM (the leading
            // `!` is not escaped), matching an unresolved reference link and
            // carve-js / carve-rs - not the escaped `\![a][nope]` form.
            "![a][nope]\n" => "![a][nope]\n",
            "foo![a][nope]\n" => "foo![a][nope]\n",
            "![a][nope] and [t][nope]\n" => "![a][nope] and [t][nope]\n",
            // A bare `!` (no reference following) is still escaped.
            "![a]\n" => "\\!\\[a\\]\n",
            "!important\n" => "\\!important\n",
            "{title=\"attr title\"}\n::: note \"opener title\"\nBody.\n:::\n" =>
                "{title=\"attr title\"}\n::: note \"opener title\"\nBody\\.\n:::\n",
            // Extra attributes on a typed opener survive as a preceding
            // attribute line (byte-identical to carve-js).
            "{#x data-k=\"v\"}\n::: tip\nBody.\n:::\n" =>
                "{#x data-k=v}\n::: tip\nBody\\.\n:::\n",
            // A non-identifier id falls back to the quoted key=value form.
            "{id=\"a b\"}\n::: tip\nBody.\n:::\n" =>
                "{id=\"a b\"}\n::: tip\nBody\\.\n:::\n",
            // Multi-class admonitions leave the typed-div fast path but keep
            // header, label, and the sibling class on the attribute line.
            "{.callout}\n::: note \"T\" [L]\nBody.\n:::\n" =>
                "{.callout}\n::: note \"T\" [L]\nBody\\.\n:::\n",
        ];

        foreach ($cases as $input => $expected) {
            $this->assertSame($expected, CarveConverter::toCarve($input));
        }
    }

    /**
     * The list marker is semantic (section 11): a sibling with a different
     * bullet char or ordered delimiter starts a NEW list, so fmt preserves
     * the authored marker (carve issue 286) - normalizing would merge
     * adjacent sibling lists on re-parse.
     */
    public function testPreservesAuthoredListMarkers(): void
    {
        $cases = [
            "1) a\n2) b\n",
            "1. a\n2. b\n",
            "* a\n* b\n",
            "- a\n- b\n",
            "* [x] done\n* [ ] todo\n",
        ];
        foreach ($cases as $src) {
            $this->assertSame($src, CarveConverter::toCarve($src));
        }
    }

    /**
     * The invariant cases: adjacent sibling lists separated only by their
     * marker must stay separate through fmt (carve issue 286).
     */
    public function testAdjacentListsSeparatedByMarkerStaySeparate(): void
    {
        $converter = new CarveConverter();
        $cases = [
            "1. a\n1) b",
            "1. a\n\n1) b",
            "- a\n* b",
            "- a\n\n* b",
        ];
        foreach ($cases as $src) {
            $f1 = CarveConverter::toCarve($src);
            $this->assertSame($f1, CarveConverter::toCarve($f1));
            $this->assertSame(
                $converter->convert($src),
                $converter->convert($f1),
            );
        }
    }

    /**
     * Verbatim content survives document normalization (carve-js issue 340):
     * trailing whitespace and blank-line runs inside code blocks, raw blocks,
     * frontmatter, and block comments are byte-exact after fmt.
     */
    public function testVerbatimContentSurvivesNormalization(): void
    {
        $cases = [
            "```\ntrailing   \nalso\t\t\n```\n",
            "```\na\n\n\n\nb\n```\n",
            "```=html\n<pre>x   \n\n\n\ny</pre>\n```\n",
            "---\ntitle: X\n\n\n\nnote: kept\n---\n\n%%%\nc   \n\n\n\nd\n%%%\n\nbody\n",
        ];
        $converter = new CarveConverter();
        foreach ($cases as $src) {
            $formatted = CarveConverter::toCarve($src);
            $this->assertSame($src, $formatted);
            $this->assertSame(
                $converter->convert($src),
                $converter->convert($formatted),
            );
        }
    }

    /**
     * Same content nested in a blockquote and a list item: fmt stays
     * idempotent and semantics-preserving.
     */
    public function testVerbatimContentStableInsideContainers(): void
    {
        $cases = [
            "> ```\n> a   \n>\n>\n>\n> b\n> ```\n",
            "- item\n\n  ```\n  a   \n\n\n\n  b\n  ```\n",
        ];
        $converter = new CarveConverter();
        foreach ($cases as $src) {
            $f1 = CarveConverter::toCarve($src);
            $f2 = CarveConverter::toCarve($f1);
            $this->assertSame($f1, $f2);
            $this->assertSame(
                $converter->convert($src),
                $converter->convert($f1),
            );
        }
    }

    protected function normalizeHtml(string $html): string
    {
        $html = (string)preg_replace('/[ \t]+$/m', '', $html);

        return rtrim($html, "\n");
    }
}
