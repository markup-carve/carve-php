<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Include;

use InvalidArgumentException;
use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * C2-N-037 to C2-N-041: the region lookup an editor host runs on an included file, as `@region:name` selects it.
 */
final class SelectIncludeRegionTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: array<string, mixed>}>
     */
    public static function regions(): array
    {
        $invalid = ['ok' => false, 'rule' => 'include-region-invalid'];
        $ok = static fn (int $start, int $end): array => ['ok' => true, 'start' => $start, 'end' => $end];

        return [
            'carve lines between the markers' => [
                "a\n%% #region r\nx\ny\n%% #endregion r\nb\n", 'r', 'carve', $ok(3, 4),
            ],
            'an empty region ends before it starts' => ["%% #region r\n%% #endregion\n", 'r', 'carve', $ok(2, 1)],
            'nested regions stay selected source' => [
                "%% #region o\n%% #region i\nx\n%% #endregion\n%% #endregion o\n", 'o', 'carve', $ok(2, 4),
            ],
            'a leading U+FEFF hides no line-1 marker' => ["\u{FEFF}%% #region r\nx\n%% #endregion\n", 'r', 'carve', $ok(2, 2)],
            'CRLF lines' => ["%% #region r\r\nx\r\n%% #endregion\r\n", 'r', 'carve', $ok(2, 2)],
            'a code marker in code mode' => ["/* #region r */\nx\n/* #endregion */\n", 'r', 'code', $ok(2, 2)],
            'a code marker is text in carve mode' => ["// #region r\nx\n// #endregion\n", 'r', 'carve', $invalid],
            'a name the file does not open' => ["%% #region r\nx\n%% #endregion\n", 'q', 'carve', $invalid],
            'an unclosed region' => ["%% #region r\nx\n", 'r', 'carve', $invalid],
            'a mismatched closer' => ["%% #region r\nx\n%% #endregion q\n", 'r', 'carve', $invalid],
            'a repeated name' => ["%% #region r\n%% #endregion\n%% #region r\n%% #endregion\n", 'r', 'carve', $invalid],
            'a malformed marker elsewhere' => ["%% #region-r\n%% #region r\nx\n%% #endregion\n", 'r', 'carve', $invalid],
            'a block comment without its closer' => ["/* #region r\nx\n/* #endregion */\n", 'r', 'code', $invalid],
        ];
    }

    /**
     * @param string $source
     * @param string $name
     * @param string $mode
     * @param array<string, mixed> $expected
     */
    #[DataProvider('regions')]
    public function testRegion(string $source, string $name, string $mode, array $expected): void
    {
        $this->assertSame($expected, Carve::selectIncludeRegion($source, $name, $mode));
    }

    public function testAnotherModeIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Carve::selectIncludeRegion("%% #region r\n%% #endregion\n", 'r', 'html');
    }
}
