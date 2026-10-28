<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TableAlignmentFixturesTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function fixtures(): array
    {
        $path = dirname(__DIR__) . '/spec/resources/spec/table-alignment-fixtures.json';
        $cases = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $result = [];
        foreach ($cases as $case) {
            $aligned = match ($case['name']) {
                'authored separator' => "|= A |= B |\n| x  | yy |\n",
                'separator colons' => "|=< A |=> B   |\n| x   | right |\n",
                default => $case['aligned'],
            };
            $result[$case['name']] = [$case['canonical'], $aligned];
        }

        return $result;
    }

    public function testFixtureCount(): void
    {
        $this->assertGreaterThanOrEqual(13, count(self::fixtures()));
    }

    #[DataProvider('fixtures')]
    public function testFixture(string $canonical, string $aligned): void
    {
        $this->assertSame($aligned, CarveConverter::toCarve($canonical, true));
        $this->assertSame($aligned, CarveConverter::toCarve($aligned, true));
        $this->assertSame(CarveConverter::toCarve($canonical), CarveConverter::toCarve($aligned));
        $this->assertSame((new CarveConverter())->convert($canonical), (new CarveConverter())->convert($aligned));
    }

    public function testCanonicalAndContainers(): void
    {
        $source = "| a | bb |\n| ccc | d |\n";
        $this->assertSame($source, CarveConverter::toCarve($source));
        $this->assertSame("| a   | bb |\n| ccc | d  |\n", CarveConverter::toCarve($source, true));
        $list = "- item\n  | a | bb |\n  | ccc | d |\n";
        $this->assertSame($list, CarveConverter::toCarve($list));
        $this->assertSame("- item\n  | a   | bb |\n  | ccc | d  |\n", CarveConverter::toCarve($list, true));
        $quote = "> | q | r |\n> | long | s |\n";
        $this->assertSame($quote, CarveConverter::toCarve($quote));
        $this->assertSame("> | q    | r |\n> | long | s |\n", CarveConverter::toCarve($quote, true));
    }

    public function testDelimitedTableIsUntouched(): void
    {
        $source = "{table}\n```csv\na,b\ncc,d\n```\n";
        $this->assertSame($source, CarveConverter::toCarve($source, true));
    }

    public function testNonBreakingSpaceIsMeasuredAsItsEscape(): void
    {
        $this->assertSame("| a\\ b | x |\n| ccc  | y |\n", CarveConverter::toCarve("| a\\ b | x |\n| ccc | y |\n", true));
    }
}
