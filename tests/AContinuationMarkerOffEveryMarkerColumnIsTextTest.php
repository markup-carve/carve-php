<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * AN UNSATISFIED CONTINUATION MARKER IS ORDINARY TEXT (`CARVE-P9-031`,
 * markup-carve/carve#2322).
 *
 * The clause used to illustrate the unsatisfied marker as behaving "exactly as
 * if the `+` line had been a comment", and a comment produces no output, so
 * read literally the line vanished. It now says the line reaches the output as
 * text, and `AND FLUSH-LEFT MEANS COLUMN 0` (markup-carve/carve#1436) still
 * decides the column the marker attaches at.
 *
 * Below a bullet and its two-column sublist the marker columns in play are
 * document 0 and 2. At either the `+` is consumed as the marker it spells. Document
 * column 1 names no container, so the line is text - and that is the single
 * column where the character was dropped (markup-carve/carve-php#2461).
 *
 * THE POSITION ROWS CARRY THEIR OWN CONTROL. `ZZZ` in the marker's slot is text
 * at every one of these columns, so a row that loses the `+` and keeps `ZZZ`
 * is about the marker rather than about the position.
 */
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
            'document column 2 is the sublist\'s marker column' => [2, false],
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
