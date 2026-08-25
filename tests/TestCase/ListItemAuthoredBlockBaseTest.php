<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ListItemAuthoredBlockBaseTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function blockCases(): iterable
    {
        yield 'heading' => ['# h', '<h1 id="h">h</h1>'];
        yield 'quote with lazy continuation' => ["> q\n   lazy", "<blockquote><p>q\nlazy</p></blockquote>"];
        yield 'code fence' => ["```\n     c\n   ```", "<pre><code>  c\n</code></pre>"];
        yield 'raw fence' => ["```=html\n     <b>x</b>\n   ```", '<b>x</b>'];
        yield 'comment fence' => ["%%%\n     hidden\n   %%%", '<li><p>x</p></li>'];
        yield 'colon fence' => ["::: note\n   body\n   :::", '<aside class="admonition note"'];
        yield 'table' => ["| A |\n   | b |", '<table>'];
        yield 'definition list' => [":: term\n   :  def", '<dl>'];
        yield 'attributes and target' => ["{.c}\n   # h", '<h1 class="c" id="h">h</h1>'];
        yield 'block image' => ['![a](u)', '<img src="u" alt="a">'];
    }

    #[DataProvider('blockCases')]
    public function testAnOverIndentedBlockUsesItsAuthoredBase(string $body, string $expected): void
    {
        $html = (new CarveConverter())->convert("- x\n\n   {$body}\n");
        self::assertStringContainsString($expected, $html);
        self::assertStringNotContainsString('hidden', $html);
    }

    public function testBelowTheMinimumStillOpensNothing(): void
    {
        $html = (new CarveConverter())->convert("1. x\n > q\n");
        self::assertStringNotContainsString('<blockquote>', $html);
    }

    public function testOverIndentedDefinitionsRegister(): void
    {
        $html = (new CarveConverter())->convert("- x\n\n   [r]: /u\n   [^n]: note\n\nSee [r][] and [^n].\n");
        self::assertStringContainsString('<a href="/u">r</a>', $html);
        self::assertStringContainsString('role="doc-noteref"', $html);
    }

    public function testTheDescendantItemKeepsOwnership(): void
    {
        $html = (new CarveConverter())->convert("- - item\n\n    # exact\n");
        self::assertMatchesRegularExpression('/<ul>.*<ul>.*<li>item\s*<h1/s', $html);
    }

    public function testABlockBelowTheDescendantReturnsToTheParent(): void
    {
        $html = (new CarveConverter())->convert("- a\n  - b\n\n   > q\n");
        self::assertMatchesRegularExpression('/<\/ul>\s*<blockquote><p>q<\/p><\/blockquote>/', $html);
    }
}
