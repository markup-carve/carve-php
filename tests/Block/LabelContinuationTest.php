<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Block;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve#6508, C2-B-234: a caption and a term continue only on owned lines indented past their marker.
 */
final class LabelContinuationTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function cases(): array
    {
        return [
            'term in a quote' => [
                "> :: t\n>    more\n> next\n",
                "<blockquote>\n  <dl>\n    <dt>t\nmore</dt>\n  </dl>\n  <p>next</p>\n</blockquote>",
            ],
            'term continued by a tab' => [
                ":: t\n\tmore\n",
                "<dl>\n  <dt>t\nmore</dt>\n</dl>",
            ],
            'term in an item' => [
                "- :: t\n     u\n  v\n",
                "<ul>\n  <li>\n    <dl>\n      <dt>t\nu</dt>\n    </dl>\n    <p>v</p>\n  </li>\n</ul>",
            ],
            'caption in an item' => [
                "- | a |\n  ^ cap\n    more\n  next\n",
                "<ul>\n  <li>\n    <table>\n      <caption>cap\nmore</caption>\n      <tbody>\n"
                . "        <tr><td>a</td></tr>\n      </tbody>\n    </table>\n    <p>next</p>\n  </li>\n</ul>",
            ],
            'second caret line in a quote' => [
                "> | a |\n> ^ cap\n>   more\n> ^ two\n",
                "<blockquote>\n  <table>\n    <caption>cap\nmore</caption>\n    <tbody>\n"
                . "      <tr><td>a</td></tr>\n    </tbody>\n  </table>\n  <p>^ two</p>\n</blockquote>",
            ],
        ];
    }

    /**
     * @param string $input
     * @param string $html
     *
     * @return void
     */
    #[DataProvider('cases')]
    public function testLabelContinuation(string $input, string $html): void
    {
        $this->assertSame($html, Carve::toHtml($input));
    }
}
