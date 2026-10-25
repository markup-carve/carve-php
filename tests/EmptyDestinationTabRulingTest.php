<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

final class EmptyDestinationTabRulingTest extends TestCase
{
    public function testSpacesPadEmptyDestinations(): void
    {
        $converter = new CarveConverter();
        self::assertSame('<p><a href="">t</a></p>' . "\n", $converter->convert('[t](  )'));
        self::assertSame('<img src="" alt="a">' . "\n", $converter->convert('![a](  )'));
    }

    public function testTabIsContentForLinksAndImages(): void
    {
        $converter = new CarveConverter();
        self::assertSame("<p>[t](\t)</p>\n", $converter->convert("[t](\t)"));
        self::assertSame("<p>![a](\t)</p>\n", $converter->convert("![a](\t)"));
    }
}
