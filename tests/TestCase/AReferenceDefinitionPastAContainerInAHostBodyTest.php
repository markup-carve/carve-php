<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * markup-carve/carve-php#1908.
 *
 * A definition in a quote's lazy run is classified before block ownership and
 * therefore escapes a list item. The opposite top-level shape remains an open
 * quoted paragraph: a div-shaped lazy run, including its closer, stays text.
 */
class AReferenceDefinitionPastAContainerInAHostBodyTest extends TestCase
{
    #[DataProvider('movingProvider')]
    public function testTheDefinitionAndItsContainerTakeTheirSpecifiedOwners(string $source, string $expected): void
    {
        $this->assertSame($expected, rtrim((new CarveConverter())->convert($source), "\n"));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function movingProvider(): array
    {
        return [
            'quote in item releases definition' => ["- a\n  > q\n   [r]: /url\n\n[r][]\n", "<ul>\n  <li>a\n    <blockquote><p>q</p></blockquote>\n  </li>\n</ul>\n<p><a href=\"/url\">r</a></p>"],
            'quote prose in item releases definition' => ["- a\n  > q\n   more\n   [r]: /url\n\n[r][]\n", "<ul>\n  <li>a\n    <blockquote><p>q\nmore</p></blockquote>\n  </li>\n</ul>\n<p><a href=\"/url\">r</a></p>"],
            'top quote keeps div-shaped lazy run' => ["> a\n  ::: note\n   [r]: /url\n  :::\n\n[r][]\n", "<blockquote><p>a\n::: note\n[r]: /url\n:::</p></blockquote>\n<p>[r][]</p>"],
        ];
    }

    #[DataProvider('holdingProvider')]
    public function testTheNeighbouringOwnershipRulesDoNotMove(string $source, string $expected): void
    {
        $this->assertSame($expected, rtrim((new CarveConverter())->convert($source), "\n"));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function holdingProvider(): array
    {
        return [
            'definition at quote column in item' => ["- a\n  > q\n  [r]: /url\n\n[r][]\n", "<ul>\n  <li>a\n    <blockquote><p>q</p></blockquote>\n  </li>\n</ul>\n<p><a href=\"/url\">r</a></p>"],
            'div in item keeps definition' => ["- a\n  ::: n\n   [r]: /url\n  :::\n\n[r][]\n", "<ul>\n  <li>a\n    <div class=\"n\">\n      <p>[r]: /url</p>\n    </div>\n  </li>\n</ul>\n<p>[r][]</p>"],
            'quote in item keeps heading payload' => ["- a\n  > q\n   # h\n\nx\n", "<ul>\n  <li>a\n    <blockquote><p>q\n# h</p></blockquote>\n  </li>\n</ul>\n<p>x</p>"],
            'quote in description consumes definition' => [":: t\n: a\n  > q\n   [r]: /url\n\n[r][]\n", "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>a</p>\n    <blockquote><p>q</p></blockquote>\n  </dd>\n</dl>\n<p><a href=\"/url\">r</a></p>"],
            'top quote keeps div run without definition' => ["> a\n  ::: note\n  :::\n\n[r][]\n", "<blockquote><p>a\n::: note\n:::</p></blockquote>\n<p>[r][]</p>"],
            'top quote keeps nested quote and definition' => ["> a\n  > q\n   [r]: /url\n\n[r][]\n", "<blockquote><p>a\n&gt; q\n[r]: /url</p></blockquote>\n<p>[r][]</p>"],
            'top quote keeps plain lazy definition' => ["> a\n  [r]: /url\n\n[r][]\n", "<blockquote><p>a\n[r]: /url</p></blockquote>\n<p>[r][]</p>"],
            'top-level definition is consumed' => ["[r]: /url\n\n[r][]\n", '<p><a href="/url">r</a></p>'],
        ];
    }
}
