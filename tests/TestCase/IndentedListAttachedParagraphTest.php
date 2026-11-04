<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Test\RuleATodo;
use PHPUnit\Framework\TestCase;

final class IndentedListAttachedParagraphTest extends TestCase
{
    use RuleATodo;

    /**
     * Expected HTML for the table above the list.
     *
     * @var string
     */
    private const TABLE = "<table>\n  <tbody>\n    <tr><td>a</td><td>b</td></tr>\n  </tbody>\n</table>\n";

    public function testFlushLeftLineAttachesToEmptyItem(): void
    {
        self::assertSame(
            preg_replace('/\n\z/', '', self::TABLE . "<ul>\n  <li></li>\n</ul>\n<p>line two</p>\n"),
            CarveConverter::create()->convert("| a | b |\n  - +\nline two\n"),
        );
    }

    public function testIndentedLineDoesNotAttach(): void
    {
        self::assertSame(
            preg_replace('/\n\z/', '', self::TABLE . "<ul>\n  <li>line two</li>\n</ul>\n"),
            CarveConverter::create()->convert("| a | b |\n  - +\n  line two\n"),
        );
    }

    public function testFlushLeftListMarkersEndTheAttachedParagraph(): void
    {
        self::assertSame(
            preg_replace('/\n\z/', '', "<ul>\n  <li></li>\n</ul>\n<p>para\n1. c</p>\n"),
            CarveConverter::create()->convert("  - +\npara\n1. c\n"),
        );
        self::assertSame(
            preg_replace('/\n\z/', '', "<ul>\n  <li></li>\n</ul>\n<p>para\n- c</p>\n"),
            CarveConverter::create()->convert("  - +\npara\n- c\n"),
        );
    }
}
