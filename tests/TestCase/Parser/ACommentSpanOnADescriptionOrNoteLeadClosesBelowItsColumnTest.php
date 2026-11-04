<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A `%%%` span on a `: ` description or `[^n]: ` note lead belongs to that body (PART 9 §28, PART 0 S1).
 *
 * A closer below the body column closes it, a blank inside it is payload, and a line that misses
 * the body ends it and finds no paragraph (markup-carve/carve#4996, markup-carve/carve#4998,
 * carve-php#4508). Outputs are the spec model's at markup-carve/carve fe346397.
 */
class ACommentSpanOnADescriptionOrNoteLeadClosesBelowItsColumnTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function leadSpans(): array
    {
        return [
            'a description in an item, closer at column 0' => [
                "- a\n  :: u\n  : %%%\n%%%\nc\n",
                "<ul>\n  <li>a\n    <dl>\n      <dt>u</dt>\n      <dd></dd>\n    </dl>\n  </li>\n</ul>\n<p>c</p>",
            ],
            'a note in an item, closer at column 0' => [
                "- a\n\n  [^n]: %%%\n%%%\nc\n",
                "<ul>\n  <li>a</li>\n</ul>\n<p>c</p>",
            ],
            'a blank inside a top-level description span' => [
                ":: u\n: %%%\n    n\n\n%%%\nc\n",
                "<dl>\n  <dt>u</dt>\n  <dd></dd>\n</dl>\n<p>c</p>",
            ],
            'a blank inside the span, follower at the item column' => [
                "- a\n  :: u\n  : %%%\n\n%%%\n  c\n",
                "<ul>\n  <li>a\n    <dl>\n      <dt>u</dt>\n      <dd></dd>\n    </dl>\n    c\n  </li>\n</ul>",
            ],
            'a blank inside a top-level note span' => [
                "[^n]: %%%\n\n%%%\nc\n",
                '<p>c</p>',
            ],
            'a description in a nested item, blank inside the span' => [
                "- a\n  - b\n    :: u\n    : %%%\n\n%%%\n  c\n",
                "<ul>\n  <li>a\n    <ul>\n      <li>b\n        <dl>\n          <dt>u</dt>\n          <dd></dd>\n        </dl>\n      </li>\n    </ul>\n    c\n  </li>\n</ul>",
            ],
            'a description after a blank in the item' => [
                "- a\n\n  :: u\n  : %%%\n%%%\nc\n",
                "<ul>\n  <li><p>a</p>\n    <dl>\n      <dt>u</dt>\n      <dd></dd>\n    </dl>\n  </li>\n</ul>\n<p>c</p>",
            ],
            'a closed pair after the item stays one document comment' => [
                "- a\n  :: u\n  : %%%\n    n\n    %%%\n  zz\n\n%%%\nc\n%%%\n",
                "<ul>\n  <li>a\n    <dl>\n      <dt>u</dt>\n      <dd></dd>\n    </dl>\n    zz\n  </li>\n</ul>",
            ],
            'payload, then a line that misses the item' => [
                "- a\n  :: u\n  : %%%\n    n\nzz\n",
                "<ul>\n  <li>a\n    <dl>\n      <dt>u</dt>\n      <dd></dd>\n    </dl>\n  </li>\n</ul>\n<p>zz</p>",
            ],
            'a list-shaped payload, then a line that misses the item' => [
                "- a\n  :: u\n  : %%%\n    - n\nzz\n",
                "<ul>\n  <li>a\n    <dl>\n      <dt>u</dt>\n      <dd></dd>\n    </dl>\n  </li>\n</ul>\n<p>zz</p>",
            ],
            'a quoted item, payload, then an unmarked line' => [
                "> - a\n>   :: u\n>   : %%%\n>     n\nzz\n",
                "<blockquote>\n  <ul>\n    <li>a\n      <dl>\n        <dt>u</dt>\n        <dd></dd>\n      </dl>\n    </li>\n  </ul>\n</blockquote>\n<p>zz</p>",
            ],
            'a quoted description, then an unmarked line' => [
                "> :: u\n> : %%%\nc\n",
                "<blockquote>\n  <dl>\n    <dt>u</dt>\n    <dd></dd>\n  </dl>\n</blockquote>\n<p>c</p>",
            ],
            'a comment line in a nested span, then a line that misses the item' => [
                "- a\n  - b\n    :: u\n    : %%%\n      %% c\nc\n",
                "<ul>\n  <li>a\n    <ul>\n      <li>b\n        <dl>\n          <dt>u</dt>\n          <dd></dd>\n        </dl>\n      </li>\n    </ul>\n  </li>\n</ul>\n<p>c</p>",
            ],
            'a line below the note body ends the span, the next line folds' => [
                "- a\n  [^n]: %%%\n  zz\nc\n",
                "<ul>\n  <li>a\n    zz\nc\n  </li>\n</ul>",
            ],
            'a blank right before the closer is interior too' => [
                "- a\n  :: u\n  : %%%\n\n  %%%\n- c\n",
                "<ul>\n  <li>a\n    <dl>\n      <dt>u</dt>\n      <dd></dd>\n    </dl>\n  </li>\n  <li>c</li>\n</ul>",
            ],
            'a blank before payload inside the span does not' => [
                "- a\n  :: u\n  : %%%\n\n    n\n  %%%\n- c\n",
                "<ul>\n  <li>a\n    <dl>\n      <dt>u</dt>\n      <dd></dd>\n    </dl>\n  </li>\n  <li>c</li>\n</ul>",
            ],
            'control: a colon line with no entry above stays paragraph text' => [
                "> a\n> : %%%\nc\n",
                "<blockquote><p>a\n:\nc</p></blockquote>",
            ],
            'control: a span inside a code fence opens nothing' => [
                "- head\n\n    ```\n    %%%\n    ```\n%%%\ntail\n",
                "<ul>\n  <li><p>head</p>\n    <pre><code>%%%\n</code></pre>\n  </li>\n</ul>",
            ],
        ];
    }

    #[DataProvider('leadSpans')]
    public function testTheSpanBelongsToItsBody(string $source, string $html): void
    {
        $this->assertSame($html, rtrim((new CarveConverter())->convert($source), "\n"));
    }
}
