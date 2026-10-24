<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AQuotedParagraphKeepsABelowColumnSeparatorTest extends TestCase
{
    /**
     * @return array<string, array{int}>
     */
    public static function termColumns(): array
    {
        return [
            'three columns' => [3],
            'four columns' => [4],
            'five columns' => [5],
            'six columns' => [6],
        ];
    }

    #[DataProvider('termColumns')]
    public function testTheSeparatorStaysInTheQuotedParagraph(int $termColumn): void
    {
        $source = "r[^1]\n\n[^1]: > x\n" . str_repeat(' ', $termColumn) . ":: t\n  : zq\n";
        $html = (new CarveConverter())->convert($source);

        self::assertStringContainsString("<blockquote><p>x\n:: t\n: zq</p></blockquote>", $html);
    }

    public function testTheSeparatorAfterAnOrdinaryQuotedTermStaysOutsideTheQuote(): void
    {
        $html = (new CarveConverter())->convert("> :: t\n: zq\n");

        self::assertStringContainsString("</blockquote>\n<p>: zq</p>", $html);
    }

    public function testALazyTermLookingLineKeepsTheNextSeparatorInAnOrdinaryQuote(): void
    {
        $html = (new CarveConverter())->convert("> x\n   :: t\n: zq\n");

        self::assertStringContainsString("<blockquote><p>x\n:: t\n: zq</p></blockquote>", $html);
    }

    public function testTheSeparatorAfterAQuotedFootnoteTermStaysOutsideTheQuote(): void
    {
        $html = (new CarveConverter())->convert("r[^1]\n\n[^1]: > :: t\n  : zq\n");

        self::assertMatchesRegularExpression('~</blockquote>\s*<p>: zq~', $html);
    }
}
