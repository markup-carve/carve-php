<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A lazy line in a quoted item's stanza keeps the item open, so a following list marker
 * misses it and closes it with the quote (markup-carve/carve#5037). Expected HTML is the
 * spec model's at carve 55a7113d.
 */
final class AListMarkerAfterALazyVerseLineClosesTheQuotedItemTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function cases(): array
    {
        $path = __DIR__ . '/../../fixtures/a-list-marker-after-a-lazy-verse-line-closes-the-quoted-item.json';
        /** @var array<array{source: string, html: string}> $rows */
        $rows = json_decode((string)file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $cases = [];
        foreach ($rows as $row) {
            $cases[json_encode($row['source'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)] = [$row['source'], $row['html']];
        }

        return $cases;
    }

    #[DataProvider('cases')]
    public function testRendersTheModelHtml(string $source, string $html): void
    {
        $actual = trim((string)preg_replace('/>\s+</', '><', (new CarveConverter())->convert($source)));
        $this->assertSame($html, $actual);
    }
}
