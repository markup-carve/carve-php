<?php

declare(strict_types=1);

namespace MarkupCarve\Carve2\Test\Block;

use MarkupCarve\Carve2\Carve2;
use PHPUnit\Framework\TestCase;

final class ImageScannerTest extends TestCase
{
    public function testC2B197ImageScannerKeepsCommentToLineEnd(): void
    {
        self::assertSame('<p>![<strong>%%]</strong>]()</p>', Carve2::toHtml('![{*%%]*}]()'));
    }
}
