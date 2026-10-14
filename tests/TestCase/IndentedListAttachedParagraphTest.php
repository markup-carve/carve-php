<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

final class IndentedListAttachedParagraphTest extends TestCase
{
    /**
     * Expected HTML for the table above the list.
     *
     * @var string
     */
    private const TABLE = "<table>\n  <tbody>\n    <tr><td>a</td><td>b</td></tr>\n  </tbody>\n</table>\n";

    public function testFlushLeftLineAttachesToEmptyItem(): void
    {
        self::assertSame(
            self::TABLE . "<ul>\n  <li>line two</li>\n</ul>\n",
            CarveConverter::create()->convert("| a | b |\n  - +\nline two\n"),
        );
    }

    public function testIndentedLineDoesNotAttach(): void
    {
        self::assertSame(
            self::TABLE . "<ul>\n  <li></li>\n</ul>\n<p>line two</p>\n",
            CarveConverter::create()->convert("| a | b |\n  - +\n  line two\n"),
        );
    }

    public function testFlushLeftListMarkersEndTheAttachedParagraph(): void
    {
        self::assertSame(
            "<ul>\n  <li>para</li>\n</ul>\n<ol>\n  <li>c</li>\n</ol>\n",
            CarveConverter::create()->convert("  - +\npara\n1. c\n"),
        );
        self::assertSame(
            "<ul>\n  <li>para</li>\n</ul>\n<ul>\n  <li>c</li>\n</ul>\n",
            CarveConverter::create()->convert("  - +\npara\n- c\n"),
        );
    }
}
