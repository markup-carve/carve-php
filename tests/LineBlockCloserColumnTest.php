<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

final class LineBlockCloserColumnTest extends TestCase
{
    public function testIndentedColonRunsRemainVerseLines(): void
    {
        $source = "::: |\na\n :::\n :::\n";
        $expected = "<div class=\"line-block\">\n  <p>a<br>\n&nbsp;:::<br>\n&nbsp;:::</p>\n</div>";

        self::assertSame($expected, (new CarveConverter())->convert($source));
    }
}
