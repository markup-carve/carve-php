<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\TestCase;

/**
 * A link whose content imports to nothing keeps an empty label. Writing its
 * destination there would add visible text the HTML never had.
 */
class ALinkWithNoContentKeepsAnEmptyLabelTest extends TestCase
{
    public function testTheLabelStaysEmpty(): void
    {
        $this->assertSame(
            "a [](/){.l} b [](/x)\n",
            (new HtmlToCarve())->convert('<p>a <a href="/" class="l"><svg><path d="M0"/></svg></a> b <a href="/x"></a></p>'),
        );
    }

    public function testAnEmptyReferenceLabelWritesNoDefinition(): void
    {
        $this->assertSame(
            "[](/x)\n",
            (new HtmlToCarve())->convert('<p><a href="/x" data-djot-ref=""></a></p>'),
        );
    }
}
