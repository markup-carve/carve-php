<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AQuotedItemMarkerLineRunNeedsQuotePrefixTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function shapes(): array
    {
        return [
            'two quote levels' => [
                "> > - {.x\n       .y}\nplain\n",
                "<blockquote>\n  <blockquote>\n    <ul>\n      <li>{.x\n.y}\nplain</li>\n    </ul>\n  </blockquote>\n</blockquote>",
            ],
            'quote inside item' => [
                "- >  - {.x\n       .y}\nplain\n",
                "<ul>\n  <li>\n    <blockquote>\n      <ul>\n        <li>{.x\n.y}\nplain</li>\n      </ul>\n    </blockquote>\n  </li>\n</ul>",
            ],
            'prefixed continuation still opens a run' => [
                "> > - {.x\n> >    .y}\nplain\n",
                "<blockquote>\n  <blockquote>\n    <ul>\n      <li></li>\n    </ul>\n  </blockquote>\n</blockquote>\n<p>plain</p>",
            ],
        ];
    }

    #[DataProvider('shapes')]
    public function testUnprefixedRunIsText(string $source, string $expected): void
    {
        self::assertSame($expected, (new CarveConverter())->convert($source));
    }
}
