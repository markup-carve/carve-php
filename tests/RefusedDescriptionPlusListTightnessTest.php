<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RefusedDescriptionPlusListTightnessTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function siblings(): iterable
    {
        foreach (['-' => 'ul', '*' => 'ul', '1.' => 'ol'] as $marker => $tag) {
            yield $marker => [
                ":: t\n: +\n{$marker} li\n+\n{$marker} sib\n",
                "<dl>\n  <dt>t</dt>\n  <dd>\n    <{$tag}>\n      <li>li</li>\n      <li>sib</li>\n    </{$tag}>\n  </dd>\n</dl>",
            ];
        }
    }

    #[DataProvider('siblings')]
    public function testSiblingItemsStayTight(string $source, string $expected): void
    {
        self::assertSame($expected, (new CarveConverter())->convert($source));
    }
}
