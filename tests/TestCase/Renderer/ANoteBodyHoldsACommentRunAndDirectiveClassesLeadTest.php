<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * markup-carve/carve#4512 and #4514 (maintainer rulings, 2026-10-31).
 */
class ANoteBodyHoldsACommentRunAndDirectiveClassesLeadTest extends TestCase
{
    /**
     * @var string
     */
    private const EMPTY_NOTE = "<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n"
        . "    <li id=\"fn1\">\n      <p>%s<a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n"
        . "    </li>\n  </ol>\n</section>";

    #[DataProvider('commentRunProvider')]
    public function testACommentInANoteBodyEndsAtTheClosingBracket(string $source, string $body): void
    {
        $expected = "<p><a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a> d</p>\n"
            . sprintf(self::EMPTY_NOTE, $body);

        $this->assertSame($expected, trim(CarveConverter::create()->convert($source)));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function commentRunProvider(): iterable
    {
        yield 'comment alone' => ["^[%% c] d\n", ''];
        yield 'comment after text' => ["^[a %% c] d\n", 'a'];
    }

    #[DataProvider('heldRunProvider')]
    public function testAnExtensionAndALiteralBracketHoldTheRunToo(string $source, string $expected): void
    {
        $this->assertSame($expected, trim(CarveConverter::create()->convert($source)));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function heldRunProvider(): iterable
    {
        yield 'extension' => [":foo[%% c] d\n", '<p><span class="ext-foo"></span> d</p>'];
        yield 'literal bracket' => ["[a %% c] d\n", '<p>[a] d</p>'];
    }

    #[DataProvider('directiveClassProvider')]
    public function testATypedDivsDirectiveClassesLeadTheAuthoredOnes(string $source, string $expected): void
    {
        $this->assertSame($expected, trim(CarveConverter::create()->convert($source)));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function directiveClassProvider(): iterable
    {
        yield 'admonition' => [
            "{k=v .c}\n::: note\nx\n:::\n",
            "<aside class=\"admonition note c\" k=\"v\" aria-label=\"Note\">\n  <p>x</p>\n</aside>",
        ];

        yield 'admonition with id' => [
            "{.c #i}\n::: warning\nx\n:::\n",
            "<aside class=\"admonition warning c\" id=\"i\" aria-label=\"Warning\">\n  <p>x</p>\n</aside>",
        ];

        yield 'typed div' => [
            "{k=v .c}\n::: foo\nx\n:::\n",
            "<div class=\"foo c\" k=\"v\">\n  <p>x</p>\n</div>",
        ];

        yield 'class key' => [
            "{class=\"c\"}\n::: foo\nx\n:::\n",
            "<div class=\"foo c\">\n  <p>x</p>\n</div>",
        ];
    }
}
