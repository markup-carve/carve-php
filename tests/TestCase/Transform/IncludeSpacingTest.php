<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Transform;

use MarkupCarve\Carve\Transform\IncludeDirectiveSyntax;
use PHPUnit\Framework\TestCase;

class IncludeSpacingTest extends TestCase
{
    public function testPaddingUsesSpaces(): void
    {
        self::assertNull(IncludeDirectiveSyntax::parse("{{\tc.crv\t}}"));
        self::assertSame('c.crv', IncludeDirectiveSyntax::parse('{{   c.crv   }}')['path']);
    }

    public function testTabBelongsToPath(): void
    {
        self::assertSame("c.crv\t", IncludeDirectiveSyntax::parse("{{ c.crv\t#Alpha }}")['path']);
        self::assertSame("c.crv\t", IncludeDirectiveSyntax::parse("{{ c.crv\t@shift:1 }}")['path']);
    }
}
