<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\TestCase;

class AnUnspellableFenceClassStaysGenericTest extends TestCase
{
    public function testImporterKeepsDigitLeadingClassOnGenericDiv(): void
    {
        self::assertSame(
            "{.2col}\n:::\ny\n:::",
            trim((new HtmlToCarve())->convert('<div class="2col"><p>y</p></div>')),
        );
    }

    public function testImporterStillConsumesSpellableFenceClass(): void
    {
        self::assertSame(
            "::: col2\ny\n:::",
            trim((new HtmlToCarve())->convert('<div class="col2"><p>y</p></div>')),
        );
    }
}
