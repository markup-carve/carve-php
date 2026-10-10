<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

class ANestedMarkerCommentKeepsItsOwnOwnershipTest extends TestCase
{
    public function testCorpus515AndCloserColumns(): void
    {
        $cases = json_decode(<<<'JSON'
[
  [
    "515-a-nested-marker-comment-keeps-its-own-ownership-2.crv",
    "- a\n  - %%%\n    hidden\n    %%%\ntail\n",
    "<ul>\n  <li>a\n    <ul>\n      <li></li>\n    </ul>\n  </li>\n</ul>\n<p>tail</p>\n"
  ],
  [
    "515-a-nested-marker-comment-keeps-its-own-ownership-3.crv",
    "- a\n  - %% hidden\ntail\n",
    "<ul>\n  <li>a\n    <ul>\n      <li></li>\n    </ul>\n  </li>\n</ul>\n<p>tail</p>\n"
  ],
  [
    "515-a-nested-marker-comment-keeps-its-own-ownership-4.crv",
    "1. a\n   1. %%%\n      hidden\n%%%\ntail\n",
    "<ol>\n  <li>a\n    <ol>\n      <li></li>\n    </ol>\n  </li>\n</ol>\n<p>tail</p>\n"
  ],
  [
    "515-a-nested-marker-comment-keeps-its-own-ownership-5.crv",
    "1. a\n   1. %%%\n      hidden\n      %%%\ntail\n",
    "<ol>\n  <li>a\n    <ol>\n      <li></li>\n    </ol>\n  </li>\n</ol>\n<p>tail</p>\n"
  ],
  [
    "515-a-nested-marker-comment-keeps-its-own-ownership.crv",
    "- a\n  - %%%\n    hidden\n%%%\ntail\n",
    "<ul>\n  <li>a\n    <ul>\n      <li></li>\n    </ul>\n  </li>\n</ul>\n<p>tail</p>\n"
  ],
  [
    "bullet closer 0",
    "- a\n  - %%%\n    hidden\n%%%\ntail\n",
    "<ul>\n  <li>a\n    <ul>\n      <li></li>\n    </ul>\n  </li>\n</ul>\n<p>tail</p>\n"
  ],
  [
    "bullet closer 1",
    "- a\n  - %%%\n    hidden\n %%%\ntail\n",
    "<ul>\n  <li>a\n    <ul>\n      <li></li>\n    </ul>\n  </li>\n</ul>\n<p>tail</p>\n"
  ],
  [
    "bullet closer 4",
    "- a\n  - %%%\n    hidden\n    %%%\ntail\n",
    "<ul>\n  <li>a\n    <ul>\n      <li></li>\n    </ul>\n  </li>\n</ul>\n<p>tail</p>\n"
  ],
  [
    "bullet closer 6",
    "- a\n  - %%%\n    hidden\n      %%%\ntail\n",
    "<ul>\n  <li>a\n    <ul>\n      <li></li>\n    </ul>\n  </li>\n</ul>\n<p>tail</p>\n"
  ],
  [
    "ordered closer 0",
    "1. a\n   1. %%%\n      hidden\n%%%\ntail\n",
    "<ol>\n  <li>a\n    <ol>\n      <li></li>\n    </ol>\n  </li>\n</ol>\n<p>tail</p>\n"
  ],
  [
    "ordered closer 1",
    "1. a\n   1. %%%\n      hidden\n %%%\ntail\n",
    "<ol>\n  <li>a\n    <ol>\n      <li></li>\n    </ol>\n  </li>\n</ol>\n<p>tail</p>\n"
  ],
  [
    "ordered closer 6",
    "1. a\n   1. %%%\n      hidden\n      %%%\ntail\n",
    "<ol>\n  <li>a\n    <ol>\n      <li></li>\n    </ol>\n  </li>\n</ol>\n<p>tail</p>\n"
  ],
  [
    "ordered closer 8",
    "1. a\n   1. %%%\n      hidden\n        %%%\ntail\n",
    "<ol>\n  <li>a\n    <ol>\n      <li></li>\n    </ol>\n  </li>\n</ol>\n<p>tail</p>\n"
  ],
  [
    "task closer 0",
    "- a\n  - [x] %%%\n    hidden\n%%%\ntail\n",
    "<ul>\n  <li>a\n    <ul class=\"task-list\">\n      <li data-task-state=\"x\"><input type=\"checkbox\" checked disabled> </li>\n    </ul>\n  </li>\n</ul>\n<p>tail</p>\n"
  ],
  [
    "task closer 1",
    "- a\n  - [x] %%%\n    hidden\n %%%\ntail\n",
    "<ul>\n  <li>a\n    <ul class=\"task-list\">\n      <li data-task-state=\"x\"><input type=\"checkbox\" checked disabled> </li>\n    </ul>\n  </li>\n</ul>\n<p>tail</p>\n"
  ],
  [
    "task closer 4",
    "- a\n  - [x] %%%\n    hidden\n    %%%\ntail\n",
    "<ul>\n  <li>a\n    <ul class=\"task-list\">\n      <li data-task-state=\"x\"><input type=\"checkbox\" checked disabled> </li>\n    </ul>\n  </li>\n</ul>\n<p>tail</p>\n"
  ],
  [
    "task closer 6",
    "- a\n  - [x] %%%\n    hidden\n      %%%\ntail\n",
    "<ul>\n  <li>a\n    <ul class=\"task-list\">\n      <li data-task-state=\"x\"><input type=\"checkbox\" checked disabled> </li>\n    </ul>\n  </li>\n</ul>\n<p>tail</p>\n"
  ],
  [
    "code payload",
    "- a\n  ```\n  - %%%\n  hidden\n  ```\n%%%\ntail\n",
    "<ul>\n  <li>a\n    <pre><code>- %%%\nhidden\n</code></pre>\n  </li>\n</ul>\n<p>tail</p>\n"
  ],
  [
    "unclosed comment",
    "- a\n  - %%%\n    visible\ntail\n",
    "<ul>\n  <li>a\n    <ul>\n      <li>visible\ntail</li>\n    </ul>\n  </li>\n</ul>\n"
  ],
  [
    "below-column payload",
    "- a\n  - %%%\n  visible\n%%%\ntail\n",
    "<ul>\n  <li>a\n    <ul>\n      <li></li>\n    </ul>\n    visible\n    tail\n  </li>\n</ul>\n"
  ]
]
JSON
, true, flags: JSON_THROW_ON_ERROR);
        $converter = new CarveConverter();
        foreach ($cases as [$name, $source, $expected]) {
            $this->assertSame(rtrim($expected), rtrim($converter->convert($source)), $name);
        }
    }
}
