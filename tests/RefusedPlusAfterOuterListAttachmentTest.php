<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RefusedPlusAfterOuterListAttachmentTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function documents(): iterable
    {
        foreach (['-' => 'ul', '*' => 'ul', '1.' => 'ol'] as $marker => $tag) {
            $tail = $marker === '-' ? "  <li>a</li>\n  <li>b</li>\n</ul>"
                : "</{$tag}>\n<ul>\n  <li>a</li>\n  <li>b</li>\n</ul>";

            yield $marker => [
                "{$marker} host\n+\n:: t\n: +\n- a\n+\n- b\n",
                "<{$tag}>\n  <li>host\n    <dl>\n      <dt>t</dt>\n      <dd></dd>\n    </dl>\n"
                    . "  </li>\n" . $tail,
            ];
        }
    }

    #[DataProvider('documents')]
    public function testRefusedMarkerIsNotItemText(string $source, string $expected): void
    {
        self::assertSame($expected, (new CarveConverter())->convert($source));
    }
}
