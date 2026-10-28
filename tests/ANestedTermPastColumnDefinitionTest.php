<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

final class ANestedTermPastColumnDefinitionTest extends TestCase
{
    public function testPastColumnFootnoteAndItsContinuationStayInTerm(): void
    {
        $html = CarveConverter::create()->convert("- a\n  :: outer\n  :  detail\n       :: inner\n      :  tail\n        [^n]: note\n         q\n");
        self::assertStringContainsString("<dt>inner\n :  tail\n [^n]: note\n  q</dt>", $html);
        self::assertStringNotContainsString('<p>q</p>', $html);
    }

    public function testPastColumnLinkDefinitionStaysLiteralAndUnregistered(): void
    {
        $html = CarveConverter::create()->convert("[x][r]\n\n:: outer\n:  detail\n     :: inner\n    [r]: /u\n     q\n");
        self::assertStringContainsString('<p>[x][r]</p>', $html);
        self::assertStringContainsString("<dt>inner\n [r]: /u\n  q</dt>", $html);
    }

    public function testDefinitionAtDescriptionColumnEndsTermAndRegisters(): void
    {
        $html = CarveConverter::create()->convert("[x][r]\n\n:: outer\n:  detail\n     :: inner\n   [r]: /u\n");
        self::assertStringContainsString('<a href="/u">x</a>', $html);
        self::assertStringContainsString('<dt>inner</dt>', $html);
        self::assertStringNotContainsString('[r]: /u</dt>', $html);
    }

    public function testBelowColumnFootnoteStaysWithOpenNestedTerm(): void
    {
        $html = CarveConverter::create()->convert(":: outer\n:  detail\n     :: inner\n  [^n]: note\n   q\n");
        self::assertStringContainsString("<dt>inner\n[^n]: note\nq</dt>", $html);
        self::assertStringNotContainsString('<p>q</p>', $html);
    }

    public function testBelowColumnFootnoteStaysWithOpenNestedDescription(): void
    {
        $html = CarveConverter::create()->convert("> :: outer\n> :  detail\n>     :: inner\n>     :  tail\n>   [^n]: note\n>    q\n");
        self::assertStringContainsString("<dd>tail\n[^n]: note\nq</dd>", $html);
        self::assertStringNotContainsString('<p>q</p>', $html);
    }
}
