<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\TestCase;

final class CanonicalMinimumDefinitionColumnTest extends TestCase
{
    public function testARecognizedDefinitionAtOrPastTheItemColumnWritesAtTheMinimum(): void
    {
        $source = "- intro\n\n   :: term\n   :  definition\n\n      > quote\n";
        $expected = "- intro\n  :: term\n  : definition\n\n    > quote\n";
        $document = (new CarveConverter())->parse($source);

        self::assertSame($expected, (new CarveRenderer())->render($document));
    }
}
