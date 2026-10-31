<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A marker after a paragraph that closed a task item's marker-line sublist opens a new list, not a sibling of the closed one (carve-php#4327).
 */
class ATaskSublistSiblingAfterAParagraphOpensAListTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function documents(): array
    {
        return [
            'refused plus at the child column' => [
                "- [ ] - x\n\n      +\n      - y\n",
                "<ul class=\"task-list\">\n  <li><input type=\"checkbox\" disabled> \n    <ul>\n      <li>x</li>\n    </ul>\n    <p>+</p>\n    <ul>\n      <li>y</li>\n    </ul>\n  </li>\n</ul>\n",
            ],
            'paragraph at the content column' => [
                "- [ ] - x\n\n  p\n  - y\n",
                "<ul class=\"task-list\">\n  <li><input type=\"checkbox\" disabled> \n    <ul>\n      <li>x</li>\n    </ul>\n    <p>p</p>\n    <ul>\n      <li>y</li>\n    </ul>\n  </li>\n</ul>\n",
            ],
            'checked box, ordered child' => [
                "- [x] 1. x\n\n  p\n  1. y\n",
                "<ul class=\"task-list\">\n  <li data-task-state=\"x\"><input type=\"checkbox\" checked disabled> \n    <ol>\n      <li>x</li>\n    </ol>\n    <p>p</p>\n    <ol>\n      <li>y</li>\n    </ol>\n  </li>\n</ul>\n",
            ],
            'comment after the blank keeps the sibling' => [
                "- [ ] - x\n\n  %% c\n  - y\n",
                "<ul class=\"task-list\">\n  <li><input type=\"checkbox\" disabled> \n    <ul>\n      <li><p>x</p></li>\n      <li><p>y</p></li>\n    </ul>\n  </li>\n</ul>\n",
            ],
            'lazy line keeps the sibling' => [
                "- [ ] - x\n  p\n  - y\n",
                "<ul class=\"task-list\">\n  <li><input type=\"checkbox\" disabled> \n    <ul>\n      <li>x\np</li>\n      <li>y</li>\n    </ul>\n  </li>\n</ul>\n",
            ],
            'quoted' => [
                "> - [ ] - x\n>\n>   p\n>   - y\n",
                "<blockquote>\n  <ul class=\"task-list\">\n    <li><input type=\"checkbox\" disabled> \n      <ul>\n        <li>x</li>\n      </ul>\n      <p>p</p>\n      <ul>\n        <li>y</li>\n      </ul>\n    </li>\n  </ul>\n</blockquote>\n",
            ],
        ];
    }

    #[DataProvider('documents')]
    public function testRendersAsTheSpecModel(string $source, string $expected): void
    {
        $this->assertSame(trim($expected), trim((new CarveConverter())->convert($source)));
    }
}
