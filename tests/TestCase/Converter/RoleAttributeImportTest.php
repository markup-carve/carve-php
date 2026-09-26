<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\TestCase;

class RoleAttributeImportTest extends TestCase
{
    public function testRoleSurvivesOnBlockContainer(): void
    {
        $result = (new HtmlToCarve())->convertWithReport('<div role="search" class="b"><p>x</p></div>');

        self::assertSame("{role=search}\n::: b\nx\n:::", trim($result->value));
        self::assertSame([], $result->diagnostics);
    }

    public function testRoleSurvivesOnInlineSpan(): void
    {
        $result = (new HtmlToCarve())->convertWithReport('<p><span role="img" aria-label="c">x</span></p>');

        self::assertSame('[x]{role=img aria-label=c}', trim($result->value));
        self::assertSame([], $result->diagnostics);
    }
}
