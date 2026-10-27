<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

final class CaretDefinitionTermBoundaryTest extends TestCase
{
    public function testCaretStartsParagraphAfterTerm(): void
    {
        self::assertSame(
            preg_replace('/\n\z/', '', "<dl>\n  <dt>term</dt>\n</dl>\n<p>^ caption</p>\n"),
            CarveConverter::create()->convert(":: term\n^ caption\n"),
        );
    }

    public function testPlainLineStillFoldsIntoTerm(): void
    {
        self::assertSame(
            preg_replace('/\n\z/', '', "<dl>\n  <dt>term\nplain</dt>\n</dl>\n"),
            CarveConverter::create()->convert(":: term\nplain\n"),
        );
    }
}
