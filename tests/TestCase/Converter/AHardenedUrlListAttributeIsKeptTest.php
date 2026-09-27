<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\TestCase;

/**
 * A URL-list attribute is kept in the source and only hardened by the
 * renderer, so a denied token in it is not a dropped attribute
 * (markup-carve/carve-rs#2036).
 */
class AHardenedUrlListAttributeIsKeptTest extends TestCase
{
    public function testADeniedTokenDoesNotReportTheAttributeDropped(): void
    {
        $result = (new HtmlToCarve())->convertWithReport(
            '<p>see <img src="a.png" alt="a" srcset="a.png 1x, javascript:alert(1) 2x"> here</p>',
        );

        $this->assertSame('see ![a](a.png){srcset="a.png 1x, javascript:alert(1) 2x"} here', trim($result->value));
        $this->assertSame([], $result->diagnostics);
    }
}
