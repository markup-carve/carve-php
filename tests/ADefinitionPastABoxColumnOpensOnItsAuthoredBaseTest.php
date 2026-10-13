<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

class ADefinitionPastABoxColumnOpensOnItsAuthoredBaseTest extends TestCase
{
    public function testLinkDefinitionEscapesTheBox(): void
    {
        $html = CarveConverter::create()->convert("- ::: box\n    [r]: /u\n  :::\n\nsee [x][r]\n");

        self::assertStringNotContainsString('<p>[r]: /u</p>', $html);
        self::assertStringContainsString('<p>see <a href="/u">x</a></p>', $html);
    }

    public function testAttributeLineStillAppliesInsideTheBox(): void
    {
        $html = CarveConverter::create()->convert("- ::: box\n    {.k}\n    para\n  :::\n");

        self::assertStringContainsString('<p class="k">para</p>', $html);
    }
}
