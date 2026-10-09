<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class InlineImagePlacementTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function placements(): iterable
    {
        $cases = json_decode((string)file_get_contents(__DIR__ . '/../../fixtures/inline-image-placement.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($cases as $index => $case) {
            foreach ($case['outputs'] as $target => $expected) {
                yield $index . '-' . $target => [$case['source'], $target, $expected];
            }
        }
    }

    #[DataProvider('placements')]
    public function testAnInlineHostAddsNoImageSeparator(string $source, string $target, string $expected): void
    {
        $converter = match ($target) {
            'html' => new CarveConverter(),
            'markdown' => CarveConverter::markdown(),
            'plain' => CarveConverter::plainText(),
            'ansi' => CarveConverter::ansi(),
        };
        $this->assertSame($expected, $converter->convert($source));
    }

    public function testAnImageInAnIngestedBlockCellKeepsItsSeparator(): void
    {
        $document = (new AstCodec())->decode([
            'type' => 'document',
            'srcByteLength' => 0,
            'children' => [
                [
                    'type' => 'table',
                    'rows' => [
                        [
                            'type' => 'table_row',
                            'cells' => [
                                [
                                    'type' => 'table_cell',
                                    'header' => false,
                                    'blocks' => [
                                        ['type' => 'image', 'src' => 'u', 'alt' => 'a'],
                                        ['type' => 'paragraph', 'children' => [['type' => 'text', 'value' => 'after']]],
                                        ['type' => 'image', 'src' => 'u', 'alt' => 'a'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
        $html = (new CarveConverter())->render($document);
        $this->assertStringContainsString("<img src=\"u\" alt=\"a\">\n", $html);
        $this->assertStringNotContainsString('<img src="u" alt="a"><p>', $html);
        $this->assertSame("a after a\n", CarveConverter::plainText()->render($document));
        $this->assertSame("|  |\n| --- |\n| ![a](u) after ![a](u) |\n", CarveConverter::markdown()->render($document));
        $ansi = CarveConverter::ansi()->render($document);
        $unstyled = (string)preg_replace('/\\x1b\\[[0-9;]*m/', '', $ansi);
        $this->assertStringContainsString('[img: a] after [img: a]', $unstyled);
    }
}
