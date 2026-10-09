<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\ListBlock;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class OrderedListDialectTieBreakTest extends TestCase
{
    /**
     * @return array<string, array{string, string, int}>
     */
    public static function cases(): array
    {
        return [
            'lone i' => ["i) one\n", 'i', 1],
            'lone I' => ["I) one\n", 'I', 1],
            'lone v' => ["v) one\n", 'a', 22],
            'repeated x' => ["x. one\nx. two\nx. three\n", 'a', 24],
            'repeated X' => ["X. one\nX. two\nX. three\n", 'A', 24],
            'lone x' => ["x) one\n", 'a', 24],
            'lone l' => ["l) one\n", 'a', 12],
            'lone c' => ["c) one\n", 'a', 3],
            'lone d' => ["d) one\n", 'a', 4],
            'lone m' => ["m) one\n", 'a', 13],
            'lone C' => ["C) one\n", 'A', 3],
            'i followed by j' => ["i) one\nj) two\n", 'a', 9],
            'hard boundary' => ["v) one\n\n\n\nvi) two\n", 'a', 22],
            'consecutive letters' => ["c) one\nd) two\n", 'a', 3],
            'consecutive roman numerals' => ["x) one\nxi) two\n", 'i', 10],
            'indented body before roman sibling' => ["x) one\n\n   body\n\nxi) two\n", 'i', 10],
            'three blanks within the item body' => ["x) one\n\n\n\n   body\n\nxi) two\n", 'i', 10],
            'nested marker is not a sibling' => ["c) one\n   ci) nested\n", 'a', 3],
            'nonconsecutive roman sibling' => ["c) one\nii) two\n", 'a', 3],
            'different case' => ["c) one\nCI) two\n", 'a', 3],
            'indented list' => ["  c) one\n  d) two\n", 'a', 3],
        ];
    }

    #[DataProvider('cases')]
    public function testTheFirstMarkerFollowsTheSharedTieBreak(string $source, string $style, int $start): void
    {
        $list = (new CarveConverter())->parse($source)->getChildren()[0];
        $this->assertInstanceOf(ListBlock::class, $list);
        $this->assertSame($style, $list->getStyle());
        $this->assertSame($start, $list->getStart());
    }
}
