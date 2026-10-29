<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Test\RuleATodo;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ADescriptionAtANoteBodyColumnDescribesADeeperTermTest extends TestCase
{
    use RuleATodo;

    /**
     * @return array<string, array{string}>
     */
    public static function describedShapes(): array
    {
        return [
            'note at column 0' => ["r[^1]\n\n[^1]: x\n   :: t\n  : zq\n"],
            'note at column 0 after a blank' => ["r[^1]\n\n[^1]: x\n\n    :: t\n  : zq\n"],
            'note after a comment line' => ["r[^1]\n\n[^1]: x\n%% c\n   :: t\n  : zq\n"],
            'note in a quote body' => ["r[^1]\n\n[^1]: > x\n%% c\n   :: t\n  : zq\n"],
            'note on a list marker' => ["r[^1]\n\n- [^1]: x\n      :: t\n    : zq\n"],
            'note on a list marker, tab before the description' => ["r[^1]\n\n- [^1]: x\n      :: t\n\t: zq\n"],
            'note on a list marker, tabs before the term' => ["r[^1]\n\n- [^1]: x\n\t\t:: t\n    : zq\n"],
        ];
    }

    #[DataProvider('describedShapes')]
    public function testTheSeparatorDescribesTheTerm(string $source): void
    {
        $html = (new CarveConverter())->convert($source);

        self::assertMatchesRegularExpression('#<dt>t</dt>\s*<dd>zq</dd>#', $html);
    }

    public function testAListItemKeepsTheSeparatorAsText(): void
    {
        $html = (new CarveConverter())->convert("- x\n   :: t\n  : zq\n");

        self::assertStringNotContainsString('<dd>', $html);
        self::assertStringContainsString(': zq', $html);
    }

    public function testASeparatorBetweenTheNoteColumnAndTheTermStaysOutOfTheTerm(): void
    {
        $html = (new CarveConverter())->convert("r[^1]\n\n[^1]: x\n     :: t\n   : zq\n");

        self::assertStringNotContainsString('<dd>', $html);
    }
}
