<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A hyphen or dot run that smart typography would read is escaped as the Carve
 * writer escapes it, and every other run stays bare
 * (markup-carve/carve-php#2101).
 */
class AHyphenOrDotRunInImportedTextIsEscapedTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function importProvider(): array
    {
        return [
            'the ticket' => ['<p>a -- b --- c ... d</p>', 'a \\-\\- b \\-\\-\\- c \\.\\.\\. d'],
            'a run split by a span' => ['<p>a-<span>-b</span></p>', 'a\\-\\-b'],
            'an ellipsis split by a span' => ['<p>a..<span>.b</span></p>', 'a\\.\\.\\.b'],
            'a run after a plus-minus' => ['<p>a+---</p>', 'a+\\-\\-\\-'],
            'a flag after an element' => ['<p><b>a</b>--x</p>', '*a*\\-\\-x'],
            'a flag before an element' => ['<p>a --<b>x</b></p>', 'a --*x*'],
            'a run ending an emphasis' => ['<p><em>a --</em>x</p>', '{/a \\-\\-/}x'],
            'a flag opening an emphasis' => ['<p><em>--x</em></p>', '/--x/'],
            'a backslash before a run' => ['<p>\\--x</p>', '\\\\\\-\\-x'],
            'a line-start rule' => ['<p>---</p>', '\\-\\-\\-'],
            'a list item' => ['<ul><li>-- x</li></ul>', '- \\-\\- x'],
            'a heading' => ['<h2>a -- b</h2>', '## a \\-\\- b'],
            'code' => ['<p><code>a -- b...</code></p>', '`a -- b...`'],
            'a link destination' => ['<p><a href="http://x/a--b...">a</a></p>', '[a](http://x/a--b...)'],
            'an image alt' => ['<p><img src="u" alt="a -- b..."></p>', '![a -- b...](u)'],
            'preformatted text' => ['<pre>a -- b...</pre>', "```\na -- b...\n```"],
        ];
    }

    #[DataProvider('importProvider')]
    public function testTheImportIsWritten(string $html, string $carve): void
    {
        $this->assertSame($carve . "\n", (new HtmlToCarve())->convert($html));
    }

    public function testTheRunIsTakenFromTheParsedSpan(): void
    {
        $importer = new class extends HtmlToCarve {
            public function escapeRuns(string $carve): string
            {
                return $this->escapeSmartTypographyRuns($carve);
            }
        };

        $this->assertSame("x \\-\\-\\-\n", $importer->escapeRuns("x \\---\n"));
        $this->assertSame("a{\\-\\-}b\n", $importer->escapeRuns("a{--}b\n"));
        $this->assertSame(
            "x[^1]\n\n[^1]: a \\-\\- b\n\nc \\-\\- d\n",
            $importer->escapeRuns("x[^1]\n\n[^1]: a -- b\n\nc -- d\n"),
        );
    }

    /**
     * @return array<string, array{string, string, array<string, mixed>}>
     */
    public static function sweepProvider(): array
    {
        $shapes = ['see http://a.b/c-d.e', '../a/b.c', 'a-b-c', 'e.g. x', 'a. b'];
        foreach (['-', '.'] as $char) {
            for ($length = 1; $length <= 5; $length++) {
                $run = str_repeat($char, $length);
                array_push(
                    $shapes,
                    $run,
                    "a {$run} b",
                    "{$run} b",
                    "{$run}b",
                    "a {$run}",
                    "a{$run}",
                    "a{$run}b",
                    "({$run})",
                    "a, {$run}!",
                    "{$run} {$run}",
                );
            }
        }

        $contexts = [
            'paragraph' => ['<p>%s</p>', fn (array $text): array => $text],
            'emphasis' => ['<p><em>%s</em></p>', fn (array $text): array => ['type' => 'emphasis', 'children' => [$text]]],
            'link label' => ['<p><a href="http://u">%s</a></p>', fn (array $text): array => ['type' => 'link', 'href' => 'http://u', 'children' => [$text]]],
        ];

        // Not a smart-typography question: a lone `.` opening a line and a
        // `/` inside an emphasis are block-opener and delimiter escapes.
        $unrelated = ['paragraph: . b', 'paragraph: . .', 'emphasis: see http://a.b/c-d.e'];

        $cases = [];
        foreach ($shapes as $shape) {
            $text = ['type' => 'text', 'value' => $shape];
            foreach ($contexts as $name => [$html, $wrap]) {
                if (in_array($name . ': ' . $shape, $unrelated, true)) {
                    continue;
                }
                $cases[$name . ': ' . $shape] = [
                    sprintf($html, htmlspecialchars($shape, ENT_NOQUOTES)),
                    $shape,
                    ['type' => 'paragraph', 'children' => [$wrap($text)]],
                ];
            }
            $cases['table cell: ' . $shape] = [
                '<table><tr><td>' . htmlspecialchars($shape, ENT_NOQUOTES) . '</td></tr></table>',
                $shape,
                ['type' => 'table', 'rows' => [['type' => 'table_row', 'cells' => [['type' => 'table_cell', 'header' => false, 'children' => [$text]]]]]],
            ];
        }

        return $cases;
    }

    /**
     * The writer spells the same tree, so its bytes are the expectation.
     *
     * @param string $html
     * @param string $text
     * @param array<string, mixed> $block
     */
    #[DataProvider('sweepProvider')]
    public function testTheImportMatchesTheWriter(string $html, string $text, array $block): void
    {
        $tree = (new AstCodec())->decode(['type' => 'document', 'srcByteLength' => 0, 'children' => [$block]]);
        $expected = (new CarveRenderer())->render($tree);

        $imported = (new HtmlToCarve())->convert($html);

        $this->assertSame($expected, $imported);
        $this->assertStringContainsString(
            htmlspecialchars($text, ENT_NOQUOTES),
            (new CarveConverter())->convert($imported),
        );
    }
}
