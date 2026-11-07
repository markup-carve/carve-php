<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ABlankEndsAttachedQuoteCaptionScopeTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function documents(): iterable
    {
        yield 'blank then caption in item' => [
            "- +\n> q\n\n  ^ cap\n",
            "<ul>\n  <li>\n    <blockquote><p>q</p></blockquote>\n    <p>^ cap</p>\n  </li>\n</ul>",
        ];

        yield 'blank then heading in item' => [
            "- +\n> q\n\n  # g\n",
            "<ul>\n  <li>\n    <blockquote><p>q</p></blockquote>\n    <h1 id=\"g\">g</h1>\n  </li>\n</ul>",
        ];

        yield 'adjacent caption still attaches' => [
            "- +\n> q\n  ^ cap\n",
            "<ul>\n  <li>\n    <figure>\n      <blockquote><p>q</p></blockquote>\n      <figcaption>cap</figcaption>\n    </figure>\n  </li>\n</ul>",
        ];

        yield 'ordinary quote permits one blank before caption' => [
            "> q\n\n^ cap\n",
            "<figure>\n  <blockquote><p>q</p></blockquote>\n  <figcaption>cap</figcaption>\n</figure>",
        ];
    }

    #[DataProvider('documents')]
    public function testRendersAsTheSpecModel(string $source, string $expected): void
    {
        self::assertSame($expected, (new CarveConverter())->convert($source));
    }
}
