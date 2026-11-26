<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\ProseMirror;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Export\AnsiExporter;
use MarkupCarve\Carve\Export\MarkdownExporter;
use MarkupCarve\Carve\Export\PlainTextExporter;
use MarkupCarve\Carve\Json\TreeEquality;
use MarkupCarve\Carve\ProseMirror\Kit;
use MarkupCarve\Carve\ProseMirror\ProseMirrorRenderer;
use MarkupCarve\Carve\ProseMirror\ProseMirrorToCarve;
use PHPUnit\Framework\TestCase;

/**
 * Shapes a stock Tiptap editor saves that the kit never loads itself (carve-php#5440 to #5446).
 */
final class StockTiptapTest extends TestCase
{
    /**
     * @param string $type
     * @param string $text
     * @param array<string, mixed> $attrs
     *
     * @return array<string, mixed>
     */
    private static function cell(string $type, string $text, array $attrs = []): array
    {
        $paragraph = ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]];

        return ['type' => $type, 'attrs' => $attrs, 'content' => [$paragraph]];
    }

    /**
     * @param array<array<array<string, mixed>>> $rows
     *
     * @return array<string, mixed>
     */
    private static function table(array $rows): array
    {
        return [

            'type' => 'doc',
            'content' => [
                [

                    'type' => 'table',
                    'content' => array_map(
                        static fn (array $cells): array => ['type' => 'tableRow', 'content' => $cells],
                        $rows,
                    ),
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $doc
     * @param string $label
     */
    private function assertSavesAsItsSourceParses(array $doc, string $label = ''): string
    {
        $tree = (new ProseMirrorToCarve())->toTree($doc);
        $source = Carve::write($tree);
        $parsed = Kit::decode(Carve::encodeTree(Carve::parse($source)));
        $this->assertTrue(TreeEquality::equal(self::arrays($parsed), self::arrays($tree)), $label . "\n" . $source);

        return $source;
    }

    public function testACellSpanSavesAsTheCountsAndTheirMarks(): void
    {
        $doc = self::table([
            [self::cell('tableHeader', 'a'), self::cell('tableHeader', 'b')],
            [self::cell('tableCell', 'merged', ['colspan' => 2, 'rowspan' => 1])],
        ]);

        $this->assertSame("|= a |= b |\n| merged | < |\n", $this->assertSavesAsItsSourceParses($doc));
        $this->assertStringContainsString('<td colspan="2">merged</td>', Carve::render((new ProseMirrorToCarve())->toTree($doc)));
    }

    public function testARowSpanStopsAtTheEndOfItsGroup(): void
    {
        $doc = self::table([
            [self::cell('tableHeader', 'h', ['rowspan' => 3]), self::cell('tableHeader', 'i')],
            [self::cell('tableCell', 'a'), self::cell('tableCell', 'b')],
        ]);

        $this->assertSame("|= h |= i |\n| a | b |\n", $this->assertSavesAsItsSourceParses($doc));
    }

    public function testACellSpanningBothWaysKeepsItsColumnsAndReportsItsRows(): void
    {
        $doc = self::table([
            [self::cell('tableCell', 'a', ['colspan' => 2, 'rowspan' => 2])],
            [self::cell('tableCell', 'b'), self::cell('tableCell', 'c')],
        ]);
        $bridge = new ProseMirrorToCarve();

        $this->assertSame("| a | < |\n| b | c |\n", $bridge->convert($doc));
        $this->assertSame(
            [['path' => '/content/0/content/0/content/0', 'type' => 'tableCell', 'attribute' => 'rowspan', 'value' => 2]],
            $bridge->ignoredAttributes(),
        );
    }

    public function testSpansLoadAsTheEditorAttributesWithoutMarkCells(): void
    {
        $rows = (new ProseMirrorRenderer())->renderSource("| a | < |\n| b | c |\n| ^ | d |\n")['content'][0]['content'];

        $this->assertSame(
            [[[2, 1]], [[1, 2], [1, 1]], [[1, 1]]],
            array_map(static fn (array $row): array => array_map(
                static fn (array $cell): array => [$cell['attrs']['colspan'] ?? 1, $cell['attrs']['rowspan'] ?? 1],
                $row['content'],
            ), $rows),
        );
    }

    /**
     * Every span grid of up to three rows and three columns saves as the source that parses to it.
     */
    public function testEverySmallSpanGridSavesAsTheSourceThatParsesToIt(): void
    {
        mt_srand(5440);
        $grids = 0;
        for ($seed = 0; $seed < 400; $seed++) {
            $height = mt_rand(1, 3);
            $width = mt_rand(1, 3);
            $taken = array_fill(0, $height, array_fill(0, $width, false));
            $rows = [];
            for ($r = 0; $r < $height; $r++) {
                $cells = [];
                for ($c = 0; $c < $width; $c++) {
                    if ($taken[$r][$c]) {
                        continue;
                    }
                    $colspan = 1;
                    while ($c + $colspan < $width && !$taken[$r][$c + $colspan] && mt_rand(0, 2) === 0) {
                        $colspan++;
                    }
                    $rowspan = mt_rand(0, 2) === 0 ? mt_rand(1, $height - $r) : 1;
                    for ($y = $r; $y < $r + $rowspan; $y++) {
                        for ($x = $c; $x < $c + $colspan; $x++) {
                            $taken[$y][$x] = true;
                        }
                    }
                    $type = $r === 0 && $seed % 2 === 0 ? 'tableHeader' : 'tableCell';
                    $cells[] = self::cell($type, "c$r$c", ['colspan' => $colspan, 'rowspan' => $rowspan]);
                }
                $rows[] = $cells;
            }
            if (in_array([], $rows, true)) {
                continue;
            }
            $this->assertSavesAsItsSourceParses(self::table($rows), "grid $seed");
            $grids++;
        }
        $this->assertGreaterThan(300, $grids);
    }

    public function testALeadingHeaderRowIsTheHead(): void
    {
        $doc = self::table([
            [self::cell('tableHeader', 'a'), self::cell('tableHeader', 'b')],
            [self::cell('tableCell', '1'), self::cell('tableCell', '2')],
        ]);
        $tree = (new ProseMirrorToCarve())->toTree($doc);

        $this->assertSame(['table_head', 'table_body'], array_column($tree['children'][0]['children'], 'type'));
        $this->assertSavesAsItsSourceParses($doc);
    }

    public function testABlockImageIsAnImageParagraph(): void
    {
        $image = ['type' => 'image', 'attrs' => ['src' => 'a.png', 'alt' => 'A']];
        $bridge = new ProseMirrorToCarve();

        $this->assertSame(
            ['type' => 'paragraph', 'role' => 'image', 'children' => [['type' => 'image', 'src' => 'a.png', 'alt' => 'A']]],
            $bridge->toTree(['type' => 'doc', 'content' => [$image]])['children'][0],
        );
        $quoted = ['type' => 'doc', 'content' => [['type' => 'blockquote', 'content' => [$image]]]];
        $this->assertSame("> ![A](a.png)\n", $bridge->convert($quoted));
        $this->assertSame("| ![A](a.png) |\n", $bridge->convert(self::table([[['type' => 'tableCell', 'content' => [$image]]]])));
    }

    public function testTheRendererResolvesTheTreeItIsGiven(): void
    {
        $tree = Carve::importDocument("| a | b |\n|---|---:|\n| 1 | 2 |\n", ['from' => 'gfm'])->tree;
        $before = Carve::encodeTree($tree);
        $columns = (new ProseMirrorRenderer())->render($tree)['content'][0]['attrs']['carve']['columns'];

        $this->assertSame([['type' => 'table_column'], ['resolvedAlign' => 'right', 'type' => 'table_column']], $columns);
        $this->assertSame($before, Carve::encodeTree($tree));
    }

    public function testTheExportersTakeADecodedTree(): void
    {
        $tree = (new ProseMirrorToCarve())->toTree([

            'type' => 'doc',
            'content' => [
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'hi', 'marks' => [['type' => 'bold']]]]],
            ],
        ]);

        $this->assertSame("**hi**\n", (new MarkdownExporter())->write($tree));
        $this->assertSame("hi\n", (new PlainTextExporter())->export($tree)->output);
        $this->assertSame((new AnsiExporter())->write(Carve::parse("*hi*\n")), (new AnsiExporter())->write($tree));
    }

    public function testAttributesTheSaveDoesNotReadAreReported(): void
    {
        $doc = [

            'type' => 'doc',
            'content' => [
                [

                    'type' => 'paragraph',
                    'attrs' => [
                        'textAlign' => 'center',
                    ],
                    'content' => [
                        [

                            'type' => 'text',
                            'text' => 'x',
                            'marks' => [
                                [

                                    'type' => 'link',
                                    'attrs' => [
                                        'href' => 'https://a',
                                        'target' => '_self',
                                        'rel' => 'noopener noreferrer nofollow',
                                        'class' => null,
                                    ],
                                ],
                                ['type' => 'highlight', 'attrs' => ['color' => '#ff0']],
                            ],
                        ],
                        ['type' => 'image', 'attrs' => ['src' => 'i.png', 'alt' => null, 'title' => null, 'width' => 120]],
                    ],
                ],
            ],
        ];
        $bridge = new ProseMirrorToCarve();

        $this->assertSame("[{=x=}](https://a)![](i.png)\n", $bridge->convert($doc));
        $this->assertSame([
            ['path' => '/content/0', 'type' => 'paragraph', 'attribute' => 'textAlign', 'value' => 'center'],
            ['path' => '/content/0/content/0/marks/0', 'type' => 'link', 'attribute' => 'target', 'value' => '_self'],
            ['path' => '/content/0/content/0/marks/1', 'type' => 'highlight', 'attribute' => 'color', 'value' => '#ff0'],
            ['path' => '/content/0/content/1', 'type' => 'image', 'attribute' => 'width', 'value' => 120],
        ], $bridge->ignoredAttributes());

        $bridge->toTree(['type' => 'doc', 'attrs' => ['lang' => 'de'], 'content' => []]);
        $this->assertSame([['path' => '', 'type' => 'doc', 'attribute' => 'lang', 'value' => 'de']], $bridge->ignoredAttributes());

        $bridge->toTree((new ProseMirrorRenderer())->renderSource("|= a | b |\n| [x](https://a) | ![i](i.png) |\n"));
        $this->assertSame([], $bridge->ignoredAttributes());
    }

    public function testTheKitNamesArePublic(): void
    {
        $this->assertSame('table_cell', ProseMirrorToCarve::carveTypeFor('tableHeader'));
        $this->assertSame('div', ProseMirrorToCarve::carveTypeFor('carveDiv'));
        $this->assertSame('strong', ProseMirrorToCarve::carveTypeFor('bold'));
        $this->assertNull(ProseMirrorToCarve::carveTypeFor('carveBlock'));
        $this->assertNull(ProseMirrorToCarve::carveTypeFor('mention'));
        foreach (array_keys(ProseMirrorToCarve::proseMirrorNames()) as $name) {
            $this->assertNotNull(ProseMirrorToCarve::carveTypeFor($name), $name);
        }
    }

    private static function arrays(mixed $value): mixed
    {
        return json_decode(Kit::encode(['v' => $value]), true)['v'];
    }
}
