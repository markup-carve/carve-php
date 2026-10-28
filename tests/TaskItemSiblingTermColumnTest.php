<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

final class TaskItemSiblingTermColumnTest extends TestCase
{
    public function testTheLaterTermLeavesTheNestedListAtItsAuthoredColumn(): void
    {
        $html = (new CarveConverter())->convert("* [ ] - :: t\n       : a\n       :: sibling\n       tail\n");

        self::assertStringContainsString("</ul>\n    <dl>\n      <dt>sibling", $html);
        self::assertStringNotContainsString(':: sibling', $html);
    }

    public function testTheTermAtTheNestedContentColumnStaysNested(): void
    {
        $html = (new CarveConverter())->convert("* [ ] - :: t\n       : a\n        :: nested\n        tail\n");

        self::assertStringContainsString(':: nested', $html);
        self::assertStringNotContainsString("</ul>\n    <dl>", $html);
    }
}
