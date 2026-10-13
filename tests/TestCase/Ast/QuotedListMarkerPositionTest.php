<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class QuotedListMarkerPositionTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function quotedLists(): array
    {
        return [
            'nested fence' => [">   - a\n> - b\n>     - c\n>       :::\n1. lazy\n"],
            'sibling fence' => [">   - a\n> - b\n>     - c\n>   :::\n1. lazy\n"],
        ];
    }

    #[DataProvider('quotedLists')]
    public function testListAndItemStartAtTheirMarkers(string $source): void
    {
        $parser = new BlockParser();
        $parser->enablePositionTracking();
        $encoded = (new AstCodec())->encode($parser->parse($source));
        $quoted = $encoded['children'][0]['children'];

        self::assertSame(4, $quoted[0]['pos']['startOffset']);
        self::assertSame(4, $quoted[0]['items'][0]['pos']['startOffset']);
        self::assertSame(20, $quoted[1]['items'][0]['children'][1]['pos']['startOffset']);
        self::assertSame(20, $quoted[1]['items'][0]['children'][1]['items'][0]['pos']['startOffset']);
    }
}
