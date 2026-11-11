<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ALazyBlockCommentRunStaysVisibleTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function shapes(): array
    {
        return [
            'description' => [
                ":: t\n: pre\n{% b\n b %}\n",
                "<dl>\n  <dt>t</dt>\n  <dd>pre\n{% b\nb %}</dd>\n</dl>",
            ],
            'nested item' => [
                "- a\n  - b\n  {% b\n   b %}\n",
                "<ul>\n  <li>a\n    <ul>\n      <li>b\n{% b\nb %}</li>\n    </ul>\n  </li>\n</ul>",
            ],
            // The continuation reaches the nested item's column, so the run is held (corpus 1070-2, carve#5895).
            'nested item marker band' => [
                "- a\n  - b\n   {% b\n    b %}\n",
                "<ul>\n  <li>a\n    <ul>\n      <li>b\n</li>\n    </ul>\n  </li>\n</ul>",
            ],
            'description run with a reached continuation' => [
                ":: t\n: pre\n{% b\n  b %}\n",
                "<dl>\n  <dt>t</dt>\n  <dd>pre\n</dd>\n</dl>",
            ],
            'earlier list does not change a reached description run' => [
                "- old\n\n:: t\n: pre\n {% b\n  b %}\n",
                "<ul>\n  <li>old</li>\n</ul>\n<dl>\n  <dt>t</dt>\n  <dd>pre\n</dd>\n</dl>",
            ],
        ];
    }

    #[DataProvider('shapes')]
    public function testLazyBlockCommentRunIsText(string $source, string $expected): void
    {
        self::assertSame($expected, (new CarveConverter())->convert($source));
    }
}
