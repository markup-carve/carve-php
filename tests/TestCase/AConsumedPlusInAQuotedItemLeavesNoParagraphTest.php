<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A consumed `+` that opens a quote in an item or description body leaves no paragraph, so a
 * column-0 line closes the item and a later quote line opens its own quote (carve#5013, PART 0 S4).
 */
class AConsumedPlusInAQuotedItemLeavesNoParagraphTest extends TestCase
{
    #[DataProvider('cases')]
    public function testTheLineAfterTheConsumedPlus(string $source, string $expected): void
    {
        $this->assertSame($expected, rtrim((new CarveConverter())->convert($source), "\n"));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function cases(): array
    {
        return [
            'quote in a bullet item' => [
                "- > +\np\n  > q\n",
                "<ul>\n  <li>\n    <blockquote>\n\n    </blockquote>\n  </li>\n</ul>\n<p>p</p>\n<blockquote><p>q</p></blockquote>",
            ],
            'quote in an ordered item' => [
                "1. > +\np\n   > q\n",
                "<ol>\n  <li>\n    <blockquote>\n\n    </blockquote>\n  </li>\n</ol>\n<p>p</p>\n<blockquote><p>q</p></blockquote>",
            ],
            'quote in a quote in an item' => [
                "- > > +\np\n  > q\n",
                "<ul>\n  <li>\n    <blockquote>\n      <blockquote>\n\n      </blockquote>\n    </blockquote>\n  </li>\n</ul>\n<p>p</p>\n<blockquote><p>q</p></blockquote>",
            ],
            'quote in a description body' => [
                ":: t\n: > +\np\n  > q\n",
                "<dl>\n  <dt>t</dt>\n  <dd>\n    <blockquote>\n\n    </blockquote>\n  </dd>\n</dl>\n<p>p</p>\n<blockquote><p>q</p></blockquote>",
            ],
            'quote in a task item' => [
                "- [ ] > +\np\n  > q\n",
                "<ul class=\"task-list\">\n  <li><input type=\"checkbox\" disabled> \n    <blockquote>\n\n    </blockquote>\n  </li>\n</ul>\n<p>p</p>\n<blockquote><p>q</p></blockquote>",
            ],
            'quote in a nested item' => [
                "- - > +\np\n  > q\n",
                "<ul>\n  <li>\n    <ul>\n      <li>\n        <blockquote>\n\n        </blockquote>\n      </li>\n    </ul>\n  </li>\n</ul>\n<p>p</p>\n<blockquote><p>q</p></blockquote>",
            ],
            'fence at the item column' => [
                "- > +\np\n  ::: d\n  x\n  :::\n",
                "<ul>\n  <li>\n    <blockquote>\n\n    </blockquote>\n  </li>\n</ul>\n<p>p</p>\n<div class=\"d\">\n  <p>x</p>\n</div>",
            ],
            'top-level quote keeps the paragraph' => [
                "> +\np\n  > q\n",
                "<blockquote>\n  <p>p</p>\n  <p>q</p>\n</blockquote>",
            ],
            'quote in a note keeps the paragraph' => [
                "[^n]: > +\np\n  > q\n",
                '',
            ],
        ];
    }
}
