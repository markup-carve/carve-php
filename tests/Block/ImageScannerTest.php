<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Block;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\TestCase;

final class ImageScannerTest extends TestCase
{
    public function testC2B197ImageScannerKeepsCommentToLineEnd(): void
    {
        self::assertSame('<p>![<strong>%%]</strong>]()</p>', Carve::toHtml('![{*%%]*}]()'));
    }
}
