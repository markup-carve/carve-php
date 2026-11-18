<?php

declare(strict_types=1);

namespace MarkupCarve\Carve2\Test\Inline;

use MarkupCarve\Carve2\Carve2;
use PHPUnit\Framework\TestCase;

final class ImageAltTest extends TestCase
{
    public function testC2I074AltUnescapesOnlyAsciiPunctuation(): void
    {
        self::assertSame('<p>x <img src="/i.png" alt="5 * 3 [draft]"></p>', Carve2::toHtml('x ![5 \* 3 \[draft\]](/i.png)'));
        self::assertSame('<p>x <img src="/i.png" alt="a\\q"></p>', Carve2::toHtml('x ![a\q](/i.png)'));
    }
}
