<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Render;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Html\CoreLayout;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The direct writer's headings and sections, quotes, fences, `---`, definitions and links render exactly what the full
 * pipeline renders, and every boundary that needs more context falls back.
 */
final class CoreLayoutBlocksTest extends TestCase
{
    /**
     * @var string
     */
    private const SECTION = "## Section 1\n\nParagraph 1 has *strong*, /emphasis/, `inline code`, and a [link][site].\n\n"
        . "- first list item\n- second list item\n\n  - nested item with *strong*\n  - another nested item\n\n"
        . "> A block quote with /emphasis/ and a [direct link](https://example.com/path).\n\n"
        . "```js\nfunction section1(value) {\n  return value + 1;\n}\n```\n\n---\n";

    public function testBenchSectionRendersDirectly(): void
    {
        $source = "# Shared JavaScript core benchmark\n\n[site]: https://example.com\n\n" . self::SECTION;
        $expected = implode("\n", [
            '<section id="Shared-JavaScript-core-benchmark">',
            '  <h1>Shared JavaScript core benchmark</h1>',
            '  <section id="Section-1">',
            '    <h2>Section 1</h2>',
            '    <p>Paragraph 1 has <strong>strong</strong>, <em>emphasis</em>, <code>inline code</code>, and a'
                . ' <a href="https://example.com">link</a>.</p>',
            '    <ul>',
            '      <li>first list item</li>',
            '      <li>second list item',
            '        <ul>',
            '          <li>nested item with <strong>strong</strong></li>',
            '          <li>another nested item</li>',
            '        </ul>',
            '      </li>',
            '    </ul>',
            '    <blockquote><p>A block quote with <em>emphasis</em> and a'
                . ' <a href="https://example.com/path">direct link</a>.</p></blockquote>',
            '    <pre><code class="language-js">function section1(value) {',
            '  return value + 1;',
            '}',
            '</code></pre>',
            '    <p>—</p>',
            '  </section>',
            '</section>',
        ]);
        self::assertSame($expected, CoreLayout::render($source));
        self::assertSame($expected, Carve::render($source, ['strictLosses' => false]));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function acceptedProvider(): array
    {
        return [
            'nested sections' => ["# A b\n\npara\n\n## S 1\n\ntext\n\n### T\n\n## S 2\n\n# C"],
            'duplicate heading ids' => ["# A\n\n## A\n\n# A\n\n## A 2"],
            'heading starting with a digit' => ["## 2024 notes\n\nx"],
            'level jump' => ["# A\n\n### B\n\n## C"],
            'multi-line quote' => ["> q one\n> q two\n\nafter"],
            'fence without language' => ["a\n\n```\nx < y && \"z\" 'q'\n  ind\n\n  b\n```"],
            'fence in a section' => ["# H\n\n```php\n<?php echo 1;\n```\n\ndone here."],
            'dash after a block' => ["a\n\n---\n\nb"],
            'inline link' => ['see [the docs](https://example.com/a-b/c.html) now, ok.'],
            'reference link before its definition' => ["see [x y][k], ok\n\n[k]: http://k.org/p"],
            'unused definition' => ["[k]: https://k.org\n\npara"],
            'emphasis' => ['a /b c/, d /e/.'],
            'list after quote in section' => ["# S\n\n> q\n\n- a\n- b\n\n  - c"],
        ];
    }

    #[DataProvider('acceptedProvider')]
    public function testAcceptedMatchesFullPipeline(string $source): void
    {
        $html = CoreLayout::render($source);
        self::assertNotNull($html);
        self::assertSame(Carve::render($source, ['strictLosses' => false]), $html);
        self::assertSame($html, Carve::render($source));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function fallbackProvider(): array
    {
        return [
            'heading with markup' => ['# A *b*'],
            'heading without space' => ['#A'],
            'heading level seven' => ['####### A'],
            'heading then paragraph line' => ["# A\nb"],
            'heading with a period word' => ['# x.y'],
            'leading dash line' => ['---'],
            'dash then text' => ["a\n\n---\nb"],
            'lazy quote line' => ["> a\nb"],
            'quote marker without space' => ['>a'],
            'empty quote' => ["> a\n>\n> b"],
            'unclosed fence' => ["```\nx"],
            'empty fence' => ["```\n```"],
            'long fence' => ["````\nx\n````"],
            'fence info with attributes' => ["```js {.x}\nx\n```"],
            'fence closer with text' => ["```\nx\n``` y\n```"],
            'fence then text' => ["```\nx\n```\ny"],
            'undefined key' => ['a [b][c]'],
            'shortcut reference' => ["a [b]\n\n[b]: https://b.org"],
            'collapsed reference' => ["a [b][]\n\n[b]: https://b.org"],
            'duplicate definition' => ["a [b][k]\n\n[k]: https://b.org\n\n[k]: https://c.org"],
            'definition with a relative url' => ["a [b][k]\n\n[k]: /path"],
            'definition then paragraph line' => ["[k]: https://b.org\na"],
            'definition line inside a fence' => ["```\n[k]: https://b.org\n```\n\nsee [x][k]"],
            'key equal to a heading' => ["# site\n\na [b][site]\n\n[site]: https://b.org"],
            'link with title' => ['a [b](https://b.org "t")'],
            'link with query' => ['a [b](https://b.org/?q=1)'],
            'javascript url' => ['a [b](javascript:x)'],
            'link with markup' => ['a [*b*](https://b.org)'],
            'intraword slash' => ['a and/or b'],
            'unclosed emphasis' => ['a /b'],
            'link then word' => ['a [b](https://b.org)c'],
            'second list after blank' => ["- a\n\n---"],
            'blank before second nested list' => ["- a\n  - b\n\n  - c"],
        ];
    }

    #[DataProvider('fallbackProvider')]
    public function testFallback(string $source): void
    {
        self::assertNull(CoreLayout::render($source));
    }

    public function testGeneratedVariantsMatchFullPipeline(): void
    {
        mt_srand(5832);
        $words = [
            'a', 'Ab', 'x1', 'e.g', 'end.', '*em*', '`c d`', '/em/', '/a b/',
            '[l](https://e.com)', '[l x][k]', '[t][site].', '[l][K]',
        ];
        $heads = ['A', 'Section 1', 'Ab cd', '2 x', 'site', 'A b', 'Section 1 2'];
        $edits = ['*', '`', '.', '-', ' ', "\n", "\n\n", '- ', '  - ', '/', '[', ']', '(', '#', '> ', ':', '```', '---', '<'];
        $pick = static fn (array $from): string => $from[mt_rand(0, count($from) - 1)];
        $text = static function () use ($pick, $words): string {
            $parts = ['alpha'];
            for ($w = mt_rand(0, 5); $w > 0; $w--) {
                $parts[] = $pick($words);
            }

            return implode(' ', $parts);
        };
        $accepted = 0;
        for ($d = 0; $d < 600; $d++) {
            $blocks = ["[site]: https://example.com\n\n[k]: https://k.org"];
            for ($b = mt_rand(1, 7); $b > 0; $b--) {
                $blocks[] = match (mt_rand(0, 6)) {
                    0, 1 => str_repeat('#', mt_rand(1, 3)) . ' ' . $pick($heads),
                    2 => '> ' . $text() . (mt_rand(0, 2) === 0 ? "\n> " . $text() : ''),
                    3 => '```' . $pick(['', 'js']) . "\n" . $pick(['x < y && "z"', "f(v) {\n  return v;\n}"]) . "\n```",
                    4 => '---',
                    5 => '- ' . $text() . "\n- " . $text() . (mt_rand(0, 1) === 1 ? "\n\n  - " . $text() . "\n  - b" : ''),
                    default => $text(),
                };
            }
            $source = implode("\n\n", $blocks) . "\n";
            if (mt_rand(0, 1) === 1) {
                $source = substr_replace($source, $pick($edits), mt_rand(0, strlen($source) - 1), 0);
            }
            $html = CoreLayout::render($source);
            if ($html === null) {
                continue;
            }
            $accepted++;
            self::assertSame(Carve::render($source, ['strictLosses' => false]), $html, json_encode($source) ?: '');
        }
        self::assertGreaterThan(100, $accepted);
    }
}
