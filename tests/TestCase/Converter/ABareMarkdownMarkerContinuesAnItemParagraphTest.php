<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ABareMarkdownMarkerContinuesAnItemParagraphTest extends TestCase
{
    /**
     * @return array<string, array{string, string, int}>
     */
    public static function markers(): array
    {
        return [
            'bullet' => ["- a\n  x\n  +", "- a\n  x\n  +", 1],
            'star with tab' => ["- a\n  x\n  *\t", "- a\n  x\n  *", 1],
            'ordered dot' => ["1. a\n   x\n   1.", "1. a\n   x\n   1.", 1],
            'ordered paren' => ["1) a\n   x\n   1)", "1) a\n   x\n   1)", 1],
            'later ordered number' => ["- a\n  x\n  10. ", "- a\n  x\n  10.", 1],
            'task item' => ["- [ ] a\n  x\n  +", "- [ ] a\n  x\n  +", 1],
            'nested item' => ["- a\n  - b\n    x\n    +", "- a\n  - b\n    x\n    +", 2],
            'blank closes paragraph' => ["- a\n\n  +", "{loose}\n- a\n\n  - {empty}", 2],
            'one column short' => ["- a\n  x\n +", "- a\n  x\n\n* {empty}", 2],
            'quoted paragraph' => ["> - a\n>   x\n>   +", "> - a\n>   x\n>   +", 1],
        ];
    }

    #[DataProvider('markers')]
    public function testBareMarkerKeepsItsCommonMarkRole(string $markdown, string $carve, int $items): void
    {
        $imported = rtrim((new MarkdownToCarve())->convert($markdown), "\n");
        self::assertSame($carve, $imported);
        self::assertSame($items, substr_count((new CarveConverter())->convert($imported), '<li'));
    }
}
