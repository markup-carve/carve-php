<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\TestCase;

class LineBlockPaddingRoundtripTest extends TestCase
{
    public function testLeadingPaddingKeepsItsNodeKind(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__, 2) . '/spec/tests/corpus/561-a-line-block-payload-span-pairs-past-the-content-column-3.crv');
        $html = (new CarveConverter(roundTripMode: true))->convert($source);
        $back = (new HtmlToCarve())->convert($html);
        $codec = new AstCodec();
        $parse = static fn (string $value): array => $codec->encode((new BlockParser(false, false, false, true))->parse($value));
        $before = $parse($source);
        $after = $parse($back);
        $path = static fn (array $tree): array => $tree['children'][0]['items'][0]['children'][0]['children'][0]['children'];
        self::assertSame(array_column($path($before), 'type'), array_column($path($after), 'type'));
        self::assertSame(['non_breaking_space', 'non_breaking_space', 'code'], array_column($path($after), 'type'));
        self::assertSame(
            preg_replace('/\s+/', ' ', (new CarveConverter())->convert($source)),
            preg_replace('/\s+/', ' ', (new CarveConverter())->convert($back)),
        );
    }
}
