<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Import;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\TestCase;

final class ImageSrcUnknownTest extends TestCase
{
    public function testADeniedSrcStillGivesTheAltText(): void
    {
        $imported = Carve::importHtml('<p><img src="javascript:x" alt="A"></p>');
        $this->assertSame("A\n", $imported->source);
        $this->assertSame(['import-destination-denied'], $imported->codes());
    }
}
