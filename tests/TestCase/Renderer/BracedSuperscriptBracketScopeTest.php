<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\TestCase;

class BracedSuperscriptBracketScopeTest extends TestCase
{
    public function testACloserOutsideTheBracketRunNeedsNoEscape(): void
    {
        $source = '[{^a]^}';
        $converter = new CarveConverter(renderer: new CarveRenderer());
        self::assertSame($source, trim($converter->convert($source)));
        self::assertSame('<p>[{^a]^}</p>', trim((new CarveConverter())->convert($converter->convert($source))));
    }
}
