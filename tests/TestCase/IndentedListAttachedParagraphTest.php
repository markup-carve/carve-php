<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

final class IndentedListAttachedParagraphTest extends TestCase
{
    /** @var string */
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
}
