<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve#6484: the brace glued before a bare emphasis or strong takes the backslash of C2-W-125 case one, which gives
 * no C2-W-088 kind, so the other braces of the paragraph stay bare and the local write needs no read-back rounds.
 */
final class GluedBracePairsTest extends TestCase
{
    /**
     * @var string
     */
    private const UNIT = '{*{  *}{{*x*} {/{ /}{{/x/} {*{ *} {{*{x*} a *b* ';

    /**
     * @var string
     */
    private const WRITTEN = '{*{  *}\\{*x* {/{ /}\\{/x/ {*{ *} \\{*{x* a *b* ';

    /**
     * @return array<string, array{int}>
     */
    public static function unitCounts(): array
    {
        return ['one unit' => [1], 'three units' => [3], 'below the old switch' => [66], 'from the old switch' => [67]];
    }

    #[DataProvider('unitCounts')]
    public function testEveryUnitKeepsItsOtherBracesBare(int $units): void
    {
        $result = Carve::formatWithDiagnostics(rtrim(str_repeat(self::UNIT, $units)) . "\n");

        self::assertSame(rtrim(str_repeat(self::WRITTEN, $units)) . "\n", $result->source);
        self::assertSame([], $result->diagnostics);
    }

    public function testAnUnclosedBracedOpenerAtTheEndStaysBare(): void
    {
        $result = Carve::formatWithDiagnostics(self::UNIT . "{*{ *\n");

        self::assertSame(self::WRITTEN . "{*{ *\n", $result->source);
    }
}
