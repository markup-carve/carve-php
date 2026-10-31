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
            preg_replace('/\n\z/', '', self::TABLE . "<ul>\n  <li>+\nline two</li>\n</ul>\n"),
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
            preg_replace('/\n\z/', '', "<ul>\n  <li>para</li>\n</ul>\n<ol>\n  <li>c</li>\n</ol>\n"),
            CarveConverter::create()->convert("  - +\npara\n1. c\n"),
        );
        self::assertSame(
            preg_replace('/\n\z/', '', "<ul>\n  <li>para</li>\n</ul>\n<ul>\n  <li>c</li>\n</ul>\n"),
            CarveConverter::create()->convert("  - +\npara\n- c\n"),
        );
    }
}
