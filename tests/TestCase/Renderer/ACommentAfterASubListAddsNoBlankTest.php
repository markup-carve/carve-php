<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A comment the author wrote right below a sub-list is written at the item's
 * content column with no blank above it: a blank followed only by invisible
 * lines and then a sibling marker loosens the list (PART 9 §17 L1c).
 * carve#5129 erases a quoted `%%` line with its `>`, so the quoted row reads like the others.
 */
final class ACommentAfterASubListAddsNoBlankTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function cases(): iterable
    {
        yield 'bullet sub-list' => [
            "- a\n  * b\n   n\n   zz\n%% c\n- s\n",
            "- a\n  * b\n    n\n    zz\n  %% c\n- s\n",
            "<ul>\n  <li>a\n    <ul>\n      <li>b\nn\nzz</li>\n    </ul>\n  </li>\n  <li>s</li>\n</ul>",
        ];

        yield 'ordered sub-list' => [
            "- a\n  1. b\n%% c\n- s\n",
            "- a\n  1. b\n  %% c\n- s\n",
            "<ul>\n  <li>a\n    <ol>\n      <li>b</li>\n    </ol>\n  </li>\n  <li>s</li>\n</ul>",
        ];

        yield 'definition list' => [
            "- a\n  :: t\n  : d\n%% c\n- s\n",
            "- a\n  :: t\n  : d\n  %% c\n- s\n",
            "<ul>\n  <li>a\n    <dl>\n      <dt>t</dt>\n      <dd>d</dd>\n    </dl>\n  </li>\n  <li>s</li>\n</ul>",
        ];

        yield 'quoted list' => [
            "> - a\n>   * b\n> %% c\n> - s\n",
            "> - a\n>   * b\n>   %% c\n> - s\n",
            "<blockquote>\n  <ul>\n    <li>a\n      <ul>\n        <li>b</li>\n      </ul>\n    </li>\n    <li>s</li>\n  </ul>\n</blockquote>",
        ];

        yield 'quoted list, comment fence' => [
            "> - a\n>   * b\n> %%%\n> c\n> %%%\n> - s\n",
            "> - a\n>   * b\n>   %%%\n>   c\n>   %%%\n> - s\n",
            "<blockquote>\n  <ul>\n    <li>a\n      <ul>\n        <li>b</li>\n      </ul>\n    </li>\n    <li>s</li>\n  </ul>\n</blockquote>",
        ];
    }

    #[DataProvider('cases')]
    public function testTheCommentTakesNoBlank(string $source, string $formatted, string $html): void
    {
        self::assertSame($formatted, CarveConverter::toCarve($source));
        self::assertSame($formatted, CarveConverter::toCarve($formatted));
    }

    #[DataProvider('cases')]
    public function testTheListStaysTight(string $source, string $formatted, string $html): void
    {
        $converter = new CarveConverter();
        self::assertSame($html, trim($converter->convert($source)));
        self::assertSame($html, trim($converter->convert($formatted)));
    }
}
