<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

final class TitleNestedLinkRegressionTest extends TestCase
{
    public function testNestedLinkInContainerTitleIsFlattened(): void
    {
        $html = (new CarveConverter())->convert("::: e \"[[x](/u)](/v)\"\n:::\n");
        self::assertStringContainsString('<a href="/v">x</a>', $html);
        self::assertStringNotContainsString('href="/u"', $html);
    }
}
