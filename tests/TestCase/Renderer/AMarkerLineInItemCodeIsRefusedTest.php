<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Exception\SourceUnspellableException;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A code value line that opens a list item in its host has no spelling (carve-php#4616).
 */
final class AMarkerLineInItemCodeIsRefusedTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function markerLines(): iterable
    {
        foreach (['-', '*', '.', '1.', '1)', '10.', 'a)', 'A)', 'i)', 'I)', 'iv)'] as $marker) {
            yield $marker . ' attributed' => [$marker . '{.k} b'];
        }
        foreach (['a)', 'A)', 'i)', 'I)', 'iv)'] as $marker) {
            yield $marker . ' spaced' => [$marker . ' b'];
        }
        foreach (['a.', 'A.', 'I.'] as $marker) {
            yield $marker . ' two spaces' => [$marker . '  b'];
        }
    }

    #[DataProvider('markerLines')]
    public function testTheWriterRefusesTheValue(string $line): void
    {
        $this->expectException(SourceUnspellableException::class);
        (new CarveRenderer())->render(self::itemCode("a\n" . $line));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function textLines(): iterable
    {
        yield 'single-space letter period' => ['a. b'];
        yield 'bare attribute' => ['-{.k}'];
        yield 'plus' => ['+ b'];
    }

    #[DataProvider('textLines')]
    public function testALineThatStaysTextRoundTrips(string $line): void
    {
        $source = (new CarveRenderer())->render(self::itemCode("a\n" . $line));
        $tree = (new AstCodec())->encode((new CarveConverter())->parse($source));
        $this->assertSame("a\n" . $line, $tree['children'][0]['items'][0]['children'][0]['children'][1]['value']);
    }

    private static function itemCode(string $value): Document
    {
        return (new AstCodec())->decode([
            'type' => 'document',
            'srcByteLength' => 0,
            'children' => [
                [
                    'type' => 'list',
                    'ordered' => false,
                    'tight' => true,
                    'items' => [
                        [
                            'type' => 'list_item',
                            'children' => [['type' => 'paragraph', 'children' => [['type' => 'text', 'value' => 'x '], ['type' => 'code', 'value' => $value]]]],
                        ],
                    ],
                ],
            ],
        ]);
    }
}
