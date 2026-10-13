<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\TestCase;

class AClosingUnderlineTagSurvivesFollowingSlashesTest extends TestCase
{
    public function testMarkdownKeepsTheUnderlineAndLiteralSlashes(): void
    {
        $source = file_get_contents(__DIR__ . '/spec/tests/corpus/131-emphasis-span-closes-before-a-following-delimiter.crv');
        foreach ([false, true] as $rawHtml) {
            $markdown = CarveConverter::markdown()->convert($source);
            $back = (new MarkdownToCarve(convertRawHtml: $rawHtml))->convert($markdown);
            self::assertSame((new CarveConverter())->convert($source), (new CarveConverter())->convert($back));
            self::assertStringNotContainsString('{=html}', $back);
        }
    }
}
