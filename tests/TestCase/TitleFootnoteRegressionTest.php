<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

final class TitleFootnoteRegressionTest extends TestCase
{
    public function testInlineFootnoteInAdmonitionTitleIsRenderedOnce(): void
    {
        $html = (new CarveConverter())->convert("::: note \"^[x]\"\n:::\n");
        self::assertSame(1, substr_count($html, 'id="fn1"'));
        self::assertSame(1, substr_count($html, 'id="fnref1"'));
        self::assertStringNotContainsString('fn2', $html);
    }

}
