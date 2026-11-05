<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ASeparatorAtAQuotedDescriptionsBodyColumnIsTextTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function shapes(): array
    {
        $quoted = "    <blockquote>\n      <dl>\n        <dt>t</dt>\n        <dd>x\n: w</dd>\n      </dl>\n    </blockquote>\n";

        return [
            'quote on a description marker line' => [
                ":: t\n: > :: t\n  > : x\n  >   : w\n",
                "<dl>\n  <dt>t</dt>\n  <dd>\n" . $quoted . "  </dd>\n</dl>",
            ],
            'quote on a list marker line' => [
                "- > :: t\n  > : x\n  >   : w\n",
                "<ul>\n  <li>\n" . $quoted . "  </li>\n</ul>",
            ],
            'after a fence' => [
                ":: t\n: > :: t\n  > : ```\n  >   code\n  >   ```\n  >   : w\n",
                "<dl>\n  <dt>t</dt>\n  <dd>\n    <blockquote>\n      <dl>\n        <dt>t</dt>\n        <dd>\n"
                . "          <pre><code>code\n</code></pre>\n          <p>: w</p>\n        </dd>\n      </dl>\n"
                . "    </blockquote>\n  </dd>\n</dl>",
            ],
            'at the term column it is a sibling' => [
                ":: t\n: > :: t\n  > : x\n  > : w\n",
                "<dl>\n  <dt>t</dt>\n  <dd>\n    <blockquote>\n      <dl>\n        <dt>t</dt>\n        <dd>x</dd>\n"
                . "        <dd>w</dd>\n      </dl>\n    </blockquote>\n  </dd>\n</dl>",
            ],
        ];
    }

    #[DataProvider('shapes')]
    public function testTheSeparatorIsMeasuredPastTheQuotePrefix(string $source, string $expected): void
    {
        // carve-php#4672: an indented quote prefix was left out of the separator's column.
        self::assertSame($expected, rtrim((new CarveConverter())->convert($source), "\n"));
    }
}
