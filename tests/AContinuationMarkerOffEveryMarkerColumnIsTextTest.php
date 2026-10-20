<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AContinuationMarkerOffEveryMarkerColumnIsTextTest extends TestCase
{
    private CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = CarveConverter::create();
    }

    public function testAOneSpaceMarkerBelowASublistReachesTheOutput(): void
    {
        $html = $this->converter->convert("- x\n  - L\n +\n");

        $this->assertSame(
            "<ul>\n  <li>x\n    <ul>\n      <li>L\n+</li>\n    </ul>\n  </li>\n</ul>\n",
            $html,
        );
    }

    /**
     * @return array<string, array{int, bool}>
     */
    public static function markerColumnProvider(): array
    {
        return [
            'document column 0 is the outer list\'s marker column' => [0, false],
            'document column 1 names no container' => [1, true],
            'document column 2 is ordinary text' => [2, true],
            'document column 3 is inside the sublist item' => [3, true],
            'document column 4 is inside the sublist item' => [4, true],
        ];
    }

    #[DataProvider('markerColumnProvider')]
    public function testTheMarkerReachesTheOutputOnlyOffAMarkerColumn(int $column, bool $reaches): void
    {
        $pad = str_repeat(' ', $column);
        $html = $this->converter->convert("- x\n  - L\n{$pad}+\n");

        $this->assertSame($reaches, str_contains($html, '+'), $html);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function columnProvider(): array
    {
        $rows = [];
        foreach (self::markerColumnProvider() as $name => $row) {
            $rows[$name] = [$row[0]];
        }

        return $rows;
    }

    #[DataProvider('columnProvider')]
    public function testOrdinaryTextInTheMarkerSSlotSurvivesEveryColumn(int $column): void
    {
        $pad = str_repeat(' ', $column);
        $html = $this->converter->convert("- x\n  - L\n{$pad}ZZZ\n");

        $this->assertStringContainsString('ZZZ', $html);
    }

    /**
     * Without the sublist the one-space `+` already survived, so the sublist
     * above is part of what reached the defect and stays part of the evidence.
     */
    public function testAOneSpaceMarkerWithNoSublistAboveStillReachesTheOutput(): void
    {
        $html = $this->converter->convert("- x\n +\n");

        $this->assertSame("<ul>\n  <li>x\n+</li>\n</ul>\n", $html);
    }
}
