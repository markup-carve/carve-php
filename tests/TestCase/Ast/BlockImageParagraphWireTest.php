<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BlockImageParagraphWireTest extends TestCase
{
    /**
     * @return array<string, array{string, list<int|string>}>
     */
    public static function sources(): array
    {
        return [
            'direct image' => ["![x](x)\n", ['children', 0]],
            'resolved reference' => ["![x][r]\n\n[r]: x\n", ['children', 0]],
            'task item' => ["- [ ] ![x](x)\n", ['children', 0, 'items', 0, 'children', 0]],
        ];
    }

    /**
     * @param string $source
     * @param list<int|string> $path
     */
    #[DataProvider('sources')]
    public function testPublishedBlockImageKeepsItsParagraph(string $source, array $path): void
    {
        $wire = (new AstCodec())->encode((new CarveConverter())->parse($source));
        foreach ($path as $key) {
            $wire = $wire[$key];
        }

        self::assertSame('paragraph', $wire['type']);
        self::assertTrue($wire['blockImage']);
        self::assertSame('image', $wire['children'][0]['type']);
    }

    public function testLegacyDirectImageDecodesWithoutLosingItsFields(): void
    {
        $codec = new AstCodec();
        $wire = $codec->encode((new CarveConverter())->parse("![x](x)\n"));
        $wire['children'][0] = $wire['children'][0]['children'][0];

        $decoded = $codec->decode($wire);
        $canonical = $codec->encode($decoded);

        self::assertSame('paragraph', $canonical['children'][0]['type']);
        self::assertSame('x', $canonical['children'][0]['children'][0]['src']);
    }

    public function testHtmlImporterPublishesItsDirectImageAsAParagraph(): void
    {
        $wire = (new HtmlToCarve())->convertToAst('<img src="/x.png" alt="x">');

        self::assertSame('paragraph', $wire['children'][0]['type']);
        self::assertTrue($wire['children'][0]['blockImage']);
        self::assertSame('/x.png', $wire['children'][0]['children'][0]['src']);
    }
}
