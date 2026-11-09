<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AStackedPlusKeepsItsAttachedBlockTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function cases(): iterable
    {
        yield 'closed fence with an interior blank under a stacked plus' => [
            "- - +\n  ```\n\n   ```\n",
            "<ul>\n  <li>\n    <ul>\n      <li>\n        <pre><code>\n</code></pre>\n      </li>\n    </ul>\n  </li>\n</ul>",
        ];

        yield 'column-0 fence closed one column right past a blank' => [
            "- +\n```\n\n ```\n",
            "<ul>\n  <li>\n    <pre><code>\n</code></pre>\n  </li>\n</ul>",
        ];

        yield 'payload before the blank keeps the closer' => [
            "- - +\n  ```\n  x\n\n   ```\n",
            "<ul>\n  <li>\n    <ul>\n      <li>\n        <pre><code>x\n\n</code></pre>\n      </li>\n    </ul>\n  </li>\n</ul>",
        ];

        yield 'a shifted closer without a blank stays payload' => [
            "- +\n```\nx\n ```\n",
            "<ul>\n  <li>\n    <pre><code>x\n ```\n</code></pre>\n  </li>\n</ul>",
        ];

        yield 'a payload line past the blank in a stacked item ends the fence' => [
            "- - +\n  ```\n\n  x\n   ```\n",
            "<ul>\n  <li>\n    <ul>\n      <li>\n        <pre><code></code></pre>\n      </li>\n    </ul>\n    <p>x\n<code></code></p>\n  </li>\n</ul>",
        ];

        yield 'task box head with an unclosed colon' => [
            "- [ ] - +\n      ::: note\n      y\n\n      q\n",
            "<ul class=\"task-list\">\n  <li><input type=\"checkbox\" disabled> \n    <ul>\n      <li>\n        <aside class=\"admonition note\" aria-label=\"Note\">\n          <p>y</p>\n        </aside>\n      </li>\n    </ul>\n    <p>q</p>\n  </li>\n</ul>",
        ];

        yield 'task box head with a two-line unclosed colon' => [
            "- [ ] - +\n      ::: note\n      y\n      z\n\n      q\n",
            "<ul class=\"task-list\">\n  <li><input type=\"checkbox\" disabled> \n    <ul>\n      <li>\n        <aside class=\"admonition note\" aria-label=\"Note\">\n          <p>y\nz</p>\n        </aside>\n      </li>\n    </ul>\n    <p>q</p>\n  </li>\n</ul>",
        ];

        yield 'task box head with an unclosed line block' => [
            "- [ ] - +\n      ::: |\n      y\n\n      q\n",
            "<ul class=\"task-list\">\n  <li><input type=\"checkbox\" disabled> \n    <ul>\n      <li>\n        <div class=\"line-block\">\n          <p>y</p>\n        </div>\n      </li>\n    </ul>\n    <p>q</p>\n  </li>\n</ul>",
        ];

        yield 'task box head with a closed colon and a sibling' => [
            "- [ ] - +\n      ::: note\n      y\n\n      w\n      :::\n      - s\n",
            "<ul class=\"task-list\">\n  <li><input type=\"checkbox\" disabled> \n    <ul>\n      <li>\n        <aside class=\"admonition note\" aria-label=\"Note\">\n          <p>y</p>\n          <p>w</p>\n        </aside>\n      </li>\n      <li>s</li>\n    </ul>\n  </li>\n</ul>",
        ];

        yield 'task box head with an unclosed fence holding a marker' => [
            "* [ ] 1. +\n      ```\n      y\n      - s\n",
            "<ul class=\"task-list\">\n  <li><input type=\"checkbox\" disabled> \n    <ol>\n      <li>\n        <pre><code>y\n- s\n</code></pre>\n      </li>\n    </ol>\n  </li>\n</ul>",
        ];

        yield 'task box head with a fence closed past a blank' => [
            "- [ ] - +\n      ```\n\n       ```\n",
            "<ul class=\"task-list\">\n  <li><input type=\"checkbox\" disabled> \n    <ul>\n      <li>\n        <pre><code>\n</code></pre>\n      </li>\n    </ul>\n  </li>\n</ul>",
        ];

        yield 'task box head with a table' => [
            "- [ ] - +\n      | a |\n      | b |\n\n      q\n",
            "<ul class=\"task-list\">\n  <li><input type=\"checkbox\" disabled> \n    <ul>\n      <li>\n        <table>\n          <tbody>\n            <tr><td>a</td></tr>\n            <tr><td>b</td></tr>\n          </tbody>\n        </table>\n      </li>\n    </ul>\n    <p>q</p>\n  </li>\n</ul>",
        ];

        yield 'task box head with indented fence payload' => [
            "- [ ] - +\n      ```\n      x\n       y\n",
            "<ul class=\"task-list\">\n  <li><input type=\"checkbox\" disabled> \n    <ul>\n      <li>\n        <pre><code>x\n y\n</code></pre>\n      </li>\n    </ul>\n  </li>\n</ul>",
        ];

        yield 'task box head with indented payload in a closed fence' => [
            "- [ ] - +\n      ```\n      x\n        y\n      ```\n",
            "<ul class=\"task-list\">\n  <li><input type=\"checkbox\" disabled> \n    <ul>\n      <li>\n        <pre><code>x\n  y\n</code></pre>\n      </li>\n    </ul>\n  </li>\n</ul>",
        ];

        yield 'task box head with a fence the collector already placed' => [
            "- [ ] - +\n      ```\n      y\n\n  q\n",
            "<ul class=\"task-list\">\n  <li><input type=\"checkbox\" disabled> \n    <ul>\n      <li>\n        <pre><code>y\n</code></pre>\n      </li>\n    </ul>\n    <p>q</p>\n  </li>\n</ul>",
        ];
    }

    #[DataProvider('cases')]
    public function testTheStackedPlusKeepsItsBlock(string $source, string $html): void
    {
        self::assertSame($html, trim((new CarveConverter())->convert($source)));
    }
}
