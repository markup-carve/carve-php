<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Inline;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\TestCase;

final class ImageAltTest extends TestCase
{
    public function testC2I074AltUnescapesOnlyAsciiPunctuation(): void
    {
        self::assertSame('<p>x <img src="/i.png" alt="5 * 3 [draft]"></p>', Carve::toHtml('x ![5 \* 3 \[draft\]](/i.png)'));
        self::assertSame('<p>x <img src="/i.png" alt="a\\q"></p>', Carve::toHtml('x ![a\q](/i.png)'));
    }
}
