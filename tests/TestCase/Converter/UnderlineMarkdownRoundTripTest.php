<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\TestCase;

final class UnderlineMarkdownRoundTripTest extends TestCase
{
    public function testUnderlineBeforeLiteralSlashSurvivesMarkdown(): void
    {
        $source = "_z_/y/\n";
        $markdown = CarveConverter::markdown()->convert($source);
        $imported = (new MarkdownToCarve())->convert($markdown);
        $codec = new AstCodec();

        self::assertSame("<u>z</u>/y/\n", $markdown);
        self::assertSame(
            $codec->encode(CarveConverter::create()->parse($source))['children'],
            $codec->encode(CarveConverter::create()->parse($imported))['children'],
        );
    }

    public function testStrayClosingUnderlineTagDoesNotExposeSlashEmphasis(): void
    {
        $source = '</u>/abc/ x';
        $imported = (new MarkdownToCarve())->convert($source);

        self::assertSame("<p></u>/abc/ x</p>\n", CarveConverter::create()->convert($imported));
        self::assertStringContainsString('\\/abc/', $imported);
    }
}
