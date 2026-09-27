<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\TestCase;

class EmptyBlockAttributesImportTest extends TestCase
{
    public function testEmptyParagraphKeepsItsAttributes(): void
    {
        $result = (new HtmlToCarve())->convertWithReport(
            '<p>a</p><p class="mw-empty-elt" id="x"></p><p>b</p>',
        );

        self::assertSame("a\n\n{#x .mw-empty-elt}\n\nb", trim($result->value));
        self::assertSame([], $result->diagnostics);
    }

    public function testThematicBreakKeepsItsAttributes(): void
    {
        $result = (new HtmlToCarve())->convertWithReport('<hr id="h">');

        self::assertSame("{#h}\n---", trim($result->value));
        self::assertSame([], $result->diagnostics);
    }

    public function testUnattributedEmptyParagraphStillWritesNothing(): void
    {
        $result = (new HtmlToCarve())->convertWithReport('<p>a</p><p></p><p>b</p>');

        self::assertSame("a\n\nb", trim($result->value));
        self::assertSame([], $result->diagnostics);
    }
}
