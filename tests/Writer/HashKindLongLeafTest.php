<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Json\TreeEncoder;
use MarkupCarve\Carve\Json\TreeEquality;
use PHPUnit\Framework\TestCase;

final class HashKindLongLeafTest extends TestCase
{
    public function testRepeatedSiblingTagsKeepTheSecondBraceBare(): void
    {
        $source = rtrim(str_repeat('{\\#a#} {#z} ', 200));
        $expected = str_repeat('\\{\\#a\\#} \\{\\#z} ', 199) . '\\{\\#a\\#} {\\#z}' . "\n";

        $written = Carve::format($source);
        self::assertSame($expected, $written);
        self::assertSame($written, Carve::format($written));
        self::assertTrue(TreeEquality::equal(
            TreeEncoder::decodedForWriter(Carve::parse($source)),
            TreeEncoder::decodedForWriter(Carve::parse($written)),
        ));
    }
}
