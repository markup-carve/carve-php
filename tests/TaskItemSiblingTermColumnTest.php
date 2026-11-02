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

        self::assertStringContainsString("</ul>\n    : a\n    <dl>\n      <dt>sibling", $html);
        self::assertStringNotContainsString(':: sibling', $html);
    }

    public function testATermPastTheFoldedSeparatorStaysInTheTaskItem(): void
    {
        $html = (new CarveConverter())->convert("* [ ] - :: t\n       : a\n        :: nested\n        tail\n");

        // A term is one line (carve#4861), so the separator below it is task-item text.
        self::assertStringContainsString("<dt>t</dt>\n        </dl>\n      </li>\n    </ul>\n    : a\n    <dl>\n      <dt>nested</dt>", $html);
    }
}
