<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

final class APartialQuotePrefixKeepsPlusLazyTest extends TestCase
{
    public function testPlusContinuesTheDeepestParagraph(): void
    {
        $source = "> +\n> > q\n> +\n";
        $expected = "<blockquote>\n  <blockquote>\n    <blockquote><p>q\n+</p></blockquote>\n  </blockquote>\n</blockquote>";

        self::assertSame($expected, trim((new CarveConverter())->convert($source)));
    }
}
