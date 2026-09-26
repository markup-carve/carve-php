<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\TestCase;

/**
 * The HTML input stream turns CR LF and a lone CR into LF before tokenizing,
 * so a `<pre>` holds `\n` line ends (carve-php#2497).
 */
class AnImportedPreNormalizesCarriageReturnsTest extends TestCase
{
    public function testCarriageReturnsBecomeLineFeeds(): void
    {
        $carve = (new HtmlToCarve())->convert("<pre>a\r\nb\rc</pre>\r\n<p>d\r\ne</p>");

        $this->assertSame("```\na\nb\nc\n```\n\nd e\n", $carve);
        $this->assertSame($carve, CarveConverter::toCarve($carve));
    }
}
