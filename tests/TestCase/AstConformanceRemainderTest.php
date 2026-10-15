<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use MarkupCarve\Carve\Parser\Utility\BracketScanner;
use PHPUnit\Framework\TestCase;

final class AstConformanceRemainderTest extends TestCase
{
    public function testAnAutolinkBodyDoesNotCloseItsOuterLink(): void
    {
        $source = '[a <https://x]> b]';
        self::assertSame([0 => 17], BracketScanner::balancedBracketEnds($source, 0));
        self::assertSame([0 => true, 17 => true], BracketScanner::structuralBracketOffsets($source));
        self::assertSame(
            '<p><a href="u">a https://x] b</a></p>',
            trim((new CarveConverter())->convert('[a <https://x]> b](u)')),
        );
    }

    public function testBareCommonmarkReferenceHeadStaysParagraphText(): void
    {
        $converted = (new MarkdownToCarve())->convert("[foo]:\n\n[foo]\n");
        self::assertSame("\\[foo]:\n\n[foo]\n", $converted);
        self::assertSame('<p>[foo]:</p>' . "\n" . '<p>[foo]</p>', trim((new CarveConverter())->convert($converted)));
    }

    public function testBareHeadUsesALaterComplexDefinition(): void
    {
        $source = "[a\\*b]:\n\n[a\\*b]: /u\n  \"t\nx\"\n";
        $converted = (new MarkdownToCarve())->convert($source);
        self::assertSame('<p><a href="/u" title="t' . "\n" . 'x">a*b</a>:</p>', trim((new CarveConverter())->convert($converted)));
    }

    public function testBareHeadDoesNotUseDifferentEscapedDefinition(): void
    {
        $source = "[a\\*b]:\n\n[a\\\\*b]: /u\n";
        $converted = (new MarkdownToCarve())->convert($source);
        self::assertStringStartsWith('\\[a\\*b]:', $converted);
    }
}
