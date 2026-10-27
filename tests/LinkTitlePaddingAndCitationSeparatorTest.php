<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Extension\CitationsExtension;
use PHPUnit\Framework\TestCase;

final class LinkTitlePaddingAndCitationSeparatorTest extends TestCase
{
    public function testAsciiSpacesAfterEitherDestinationTitle(): void
    {
        $converter = new CarveConverter();
        self::assertSame(preg_replace('/\n\z/', '', '<p><a href="" title="T">t</a> and <a href="/u" title="T">t</a></p>' . "\n"), $converter->convert('[t]( "T"  ) and [t](/u "T" )'));
        self::assertSame(preg_replace('/\n\z/', '', '<p><a href="/u" title="T">t</a> and <img src="/img" alt="t" title="T"></p>' . "\n"), $converter->convert("[t](/u 'T'   ) and ![t](/img \"T\"  )"));
    }

    public function testTabIsNotTitlePadding(): void
    {
        $converter = new CarveConverter();
        self::assertStringNotContainsString('<a ', $converter->convert("[t](/u \"T\"\t)"));
    }

    public function testCitationMarkerTakesAsciiSpaceRun(): void
    {
        $converter = new CarveConverter();
        $converter->addExtension(new CitationsExtension());
        self::assertStringContainsString('<li id="ref-a">Alpha.</li>', $converter->convert("[@a]\n\n[@a]:  Alpha."));
        self::assertStringNotContainsString('<li id="ref-a">', $converter->convert("[@a]:\tAlpha."));
    }
}
