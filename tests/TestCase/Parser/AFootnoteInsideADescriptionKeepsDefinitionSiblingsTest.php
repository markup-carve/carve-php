<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AFootnoteInsideADescriptionKeepsDefinitionSiblingsTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function documents(): iterable
    {
        yield 'footnote inside the inner description' => [
            ":: t\n: a\n  :: t\n  : a\n    [^n]: a\n  :: u\n",
            "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>a</p>\n    <dl>\n      <dt>t</dt>\n      <dd>a</dd>\n      <dt>u</dt>\n    </dl>\n  </dd>\n</dl>",
        ];

        yield 'footnote between terms still splits them' => [
            ":: t\n: a\n  :: t\n  : a\n  [^n]: a\n  :: u\n",
            "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>a</p>\n    <dl>\n      <dt>t</dt>\n      <dd>a</dd>\n    </dl>\n    <dl>\n      <dt>u</dt>\n    </dl>\n  </dd>\n</dl>",
        ];
    }

    #[DataProvider('documents')]
    public function testRender(string $source, string $expected): void
    {
        self::assertSame($expected, (new CarveConverter())->convert($source));
    }
}
