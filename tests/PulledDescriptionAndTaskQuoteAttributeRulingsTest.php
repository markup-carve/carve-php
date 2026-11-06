<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PulledDescriptionAndTaskQuoteAttributeRulingsTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function documents(): iterable
    {
        yield 'pulled paragraph followed by a heading' => [
            ":: t\n: +\nh\n  # H\n",
            "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>h</p>\n    <h1 id=\"H\">H</h1>\n  </dd>\n</dl>",
        ];

        yield 'pulled paragraph followed by a heading in an item' => [
            "- item\n  :: t\n  : +\n  h\n    # H\n",
            "<ul>\n  <li>item\n    <dl>\n      <dt>t</dt>\n      <dd>\n        <p>h</p>\n        <h1 id=\"H\">H</h1>\n      </dd>\n    </dl>\n  </li>\n</ul>",
        ];

        yield 'attribute at task quote column' => [
            "- [ ] > +\n      {.c}\n      p\n",
            "<ul class=\"task-list\">\n  <li><input type=\"checkbox\" disabled> \n    <blockquote><p class=\"c\">p</p></blockquote>\n  </li>\n</ul>",
        ];

        yield 'id at checked task quote column' => [
            "- [x] > +\n      {#i}\n      q\n",
            "<ul class=\"task-list\">\n  <li data-task-state=\"x\"><input type=\"checkbox\" checked disabled> \n    <blockquote><p id=\"i\">q</p></blockquote>\n  </li>\n</ul>",
        ];
    }

    #[DataProvider('documents')]
    public function testDocument(string $source, string $html): void
    {
        self::assertSame($html, (new CarveConverter())->convert($source));
    }
}
