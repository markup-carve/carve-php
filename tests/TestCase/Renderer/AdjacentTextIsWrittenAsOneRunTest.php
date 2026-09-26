<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PART 11 section 2: adjacent text nodes are written as one run, so where a
 * tree splits text cannot decide which character carries an escape. The same
 * shapes are pinned in carve-js and carve-rs.
 */
class AdjacentTextIsWrittenAsOneRunTest extends TestCase
{
    private static function written(string $inlines): string
    {
        $json = '{"type":"document","children":[{"type":"paragraph","children":[' . $inlines . ']}],"srcByteLength":0}';
        $document = (new AstCodec())->decode(json_decode($json, true, 512, JSON_THROW_ON_ERROR));

        return (new CarveRenderer())->render($document);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function splitProvider(): array
    {
        return [
            'split replacement' => ['{"type":"text","value":"x (r"},{"type":"text","value":") y"}', "x \\(r) y\n"],
            'one node per character' => [
                '{"type":"text","value":"x "},{"type":"text","value":"("},{"type":"text","value":"r"},{"type":"text","value":")"},{"type":"text","value":" y"}',
                "x \\(r) y\n",
            ],
            'split dash run' => ['{"type":"text","value":"x -"},{"type":"text","value":"- y"}', "x \\-\\- y\n"],
        ];
    }

    #[DataProvider('splitProvider')]
    public function testASplitOpenerIsEscapedAtItsOpener(string $inlines, string $expected): void
    {
        $this->assertSame($expected, self::written($inlines));
        $whole = str_replace('"},{"type":"text","value":"', '', $inlines);
        $this->assertSame($expected, self::written($whole));
    }

    public function testAFlattenedRubyIsWrittenAsTheTextItFlattensTo(): void
    {
        $ruby = '{"type":"ruby","pairs":[{"base":[{"type":"text","value":"["}],"annotation":[{"type":"text","value":"r"}]}]}';
        $this->assertSame(
            "[\\[\\(r)]{.c}\n",
            self::written('{"type":"span","attrs":{"classes":["c"]},"children":[' . $ruby . ']}'),
        );
        $this->assertSame(
            "[/\\[\\(r)/]{.c}\n",
            self::written('{"type":"span","attrs":{"classes":["c"]},"children":[{"type":"emphasis","children":[' . $ruby . ']}]}'),
        );
    }

    /**
     * An importer's render hint names a character by its place in its own
     * node, so a hinted node is not merged into its neighbors.
     */
    public function testAHintedTextNodeKeepsItsHint(): void
    {
        $this->assertSame(
            "[\\^ax(b)]{.c}\n",
            (new HtmlToCarve())->convert('<p><span class=c>^a<ruby>x<rt>b</rt></ruby></span></p>'),
        );
    }
}
