<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A description-shaped line below a list item's content column opens nothing in
 * the surviving context, so lazy continuation folds it into the open paragraph
 * (CARVE-P2-017, markup-carve/carve#3547, corpus 657). Expectations were taken
 * from the spec model at markup-carve/carve e861e4b6.
 */
class ADescriptionSeparatorLeftOfItsItemColumnFoldsIntoTheBodyTest extends TestCase
{
    use RuleATodo;

    /**
     * @return array<string, array{string, string}>
     */
    public static function documents(): array
    {
        return [
            'loose item, separator at column 1' => [
                "- item\n\n  :: Term\n  :  Def\n :  under\n",
                "<ul>\n  <li>item\n    <dl>\n      <dt>Term</dt>\n      <dd>Def\n:  under</dd>\n    </dl>\n  </li>\n</ul>",
            ],
            'tight item, separator at column 1' => [
                "- item\n  :: Term\n  :  Def\n :  under\n",
                "<ul>\n  <li>item\n    <dl>\n      <dt>Term</dt>\n      <dd>Def\n:  under</dd>\n    </dl>\n  </li>\n</ul>",
            ],
            'separator at column 0 ends the item' => [
                "- item\n\n  :: Term\n  :  Def\n:  under\n",
                "<ul>\n  <li>item\n    <dl>\n      <dt>Term</dt>\n      <dd>Def</dd>\n    </dl>\n  </li>\n</ul>\n<p>:  under</p>",
            ],
            'blank before the column-1 separator ends the list' => [
                "- item\n\n  :: Term\n  :  Def\n\n :  under\n",
                "<ul>\n  <li>item\n    <dl>\n      <dt>Term</dt>\n      <dd>Def</dd>\n    </dl>\n  </li>\n</ul>\n<p>:  under</p>",
            ],
            'one-space separator' => [
                "- item\n\n  :: Term\n  :  Def\n : under\n",
                "<ul>\n  <li>item\n    <dl>\n      <dt>Term</dt>\n      <dd>Def\n: under</dd>\n    </dl>\n  </li>\n</ul>",
            ],
            'term line folds into a tight body' => [
                "- item\n\n  :: Term\n  :  Def\n :: under\n",
                "<ul>\n  <li>item\n    <dl>\n      <dt>Term</dt>\n      <dd>Def\n:: under</dd>\n    </dl>\n  </li>\n</ul>",
            ],
            'separator folds into an open term' => [
                "- item\n\n  :: Term\n :  under\n",
                "<ul>\n  <li>item\n    <dl>\n      <dt>Term\n:  under</dt>\n    </dl>\n  </li>\n</ul>",
            ],
            'body continued by a plain line' => [
                "- item\n\n  :: Term\n  :  Def\n  more\n :  under\n",
                "<ul>\n  <li>item\n    <dl>\n      <dt>Term</dt>\n      <dd>Def\nmore\n:  under</dd>\n    </dl>\n  </li>\n</ul>",
            ],
            'paragraph and blank before the list' => [
                "- item\n\n  x\n\n  :: Term\n  :  Def\n :  under\n",
                "<ul>\n  <li><p>item</p>\n    <p>x</p>\n    <dl>\n      <dt>Term</dt>\n      <dd>Def\n:  under</dd>\n    </dl>\n  </li>\n</ul>",
            ],
            'nested item, separator below both columns' => [
                "- a\n\n  - item\n\n    :: Term\n    :  Def\n :  under\n",
                "<ul>\n  <li>a\n    <ul>\n      <li>item\n        <dl>\n          <dt>Term</dt>\n          <dd>Def\n:  under</dd>\n        </dl>\n      </li>\n    </ul>\n  </li>\n</ul>",
            ],
            'nested item, separator between the columns' => [
                "- a\n\n  - item\n\n    :: Term\n    :  Def\n   :  under\n",
                "<ul>\n  <li>a\n    <ul>\n      <li>item\n        <dl>\n          <dt>Term</dt>\n          <dd>Def\n:  under</dd>\n        </dl>\n      </li>\n    </ul>\n  </li>\n</ul>",
            ],
            'ordered item' => [
                "10. item\n\n    :: Term\n    :  Def\n  :  under\n",
                "<ol start=\"10\">\n  <li>item\n    <dl>\n      <dt>Term</dt>\n      <dd>Def\n:  under</dd>\n    </dl>\n  </li>\n</ol>",
            ],
            'separator after an unclosed fence line in a loose item' => [
                "- item\n\n  :: A\n  :  first\n  ```\n :  next\n",
                "<ul>\n  <li>item\n    <dl>\n      <dt>A</dt>\n      <dd>first\n<code>\n:  next</code></dd>\n    </dl>\n  </li>\n</ul>",
            ],
            'separator after an unclosed fence line in a tight item' => [
                "- item\n  :: A\n  :  first\n  ```\n :  next\n",
                "<ul>\n  <li>item\n    <dl>\n      <dt>A</dt>\n      <dd>first</dd>\n    </dl>\n    <pre><code></code></pre>\n  </li>\n</ul>\n<p>:  next</p>",
            ],
        ];
    }

    #[DataProvider('documents')]
    public function testRendersLikeTheModel(string $source, string $expected): void
    {
        $this->assertSame($expected, trim((string)CarveConverter::create()->convert($source)));
    }
}
