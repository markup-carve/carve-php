<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A block at a `: +` description's content column ends the attached paragraph, and the description
 * goes on only with lines at its column (carve#5331).
 */
final class ABlockAfterAnAttachedDescriptionParagraphEndsTheAttachmentTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function documents(): iterable
    {
        yield 'a line below the column after a heading leaves the description' => [
            ":: t\n: +\nh\n  # H\nz\n",
            "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>h</p>\n    <h1 id=\"H\">H</h1>\n  </dd>\n</dl>\n<p>z</p>",
        ];

        yield 'a line below the column after a thematic break leaves the description' => [
            ":: t\n: +\nh\n  ---\nz\n",
            "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>h</p>\n    <hr>\n  </dd>\n</dl>\n<p>z</p>",
        ];

        yield 'an attribute at the column applies to the next block in the description' => [
            ":: t\n: +\nh\n  {.c}\n  k\n",
            "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>h</p>\n    <p class=\"c\">k</p>\n  </dd>\n</dl>",
        ];

        yield 'a fence at the column opens in the description and a line below it leaves' => [
            ":: t\n: +\nh\n  ```\nz\n",
            "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>h</p>\n    <pre><code></code></pre>\n  </dd>\n</dl>\n<p>z</p>",
        ];

        yield 'a div at the column opens in the description and a line below it leaves' => [
            ":: t\n: +\nh\n  ::: note\nz\n",
            "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>h</p>\n    <aside class=\"admonition note\" aria-label=\"Note\">\n\n    </aside>\n  </dd>\n</dl>\n<p>z</p>",
        ];

        yield 'a heading in a loose item and a line at the item column after it' => [
            "- item\n\n  :: t\n  : +\n  h\n    # H\n  z\n",
            "<ul>\n  <li><p>item</p>\n    <dl>\n      <dt>t</dt>\n      <dd>\n        <p>h</p>\n        <h1 id=\"H\">H</h1>\n      </dd>\n    </dl>\n    <p>z</p>\n  </li>\n</ul>",
        ];

        yield 'a heading in a nested item and a line at the item column after it' => [
            "- item\n  - sub\n    :: t\n    : +\n    h\n      # H\n    z\n",
            "<ul>\n  <li>item\n    <ul>\n      <li>sub\n        <dl>\n          <dt>t</dt>\n          <dd>\n            <p>h</p>\n            <h1 id=\"H\">H</h1>\n          </dd>\n        </dl>\n        z\n      </li>\n    </ul>\n  </li>\n</ul>",
        ];
    }

    #[DataProvider('documents')]
    public function testDocument(string $source, string $html): void
    {
        self::assertSame($html, (new CarveConverter())->convert($source));
    }
}
