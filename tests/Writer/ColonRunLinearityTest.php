<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Test\Support\Linearity;
use MarkupCarve\Carve\Writer\Writer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve#6414: past the block cap the fence lines of a nested colon fence are paragraph text.
 */
final class ColonRunLinearityTest extends TestCase
{
    public function testNestedColonFencesPastTheCapScaleLinearly(): void
    {
        // About 63 KB and 490 KB: n = 250 holds few text lines, n = 700 mostly text.
        $small = self::fences(250);
        $large = self::fences(700);
        $excess = Linearity::excess(
            static fn () => Writer::formatWithDiagnostics($small),
            static fn () => Writer::formatWithDiagnostics($large),
            (int)round(strlen($large) / strlen($small)),
            2,
        );

        $this->assertLessThanOrEqual(Linearity::LIMIT, $excess);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function colonRuns(): array
    {
        return [
            'runs before escaped colons' => ["a ::\\:x: b :::\\:y: c\n", "a \\::\\:x\\: b \\:::\\:y\\: c\n"],
            'a run on a paragraph line' => ["x\n:::\\::\n", "x\n\\:::::\n"],
            'a run before an extension name' => ["a:::\\:ext[b]\n", "a\\:::\\:ext[b]\n"],
        ];
    }

    #[DataProvider('colonRuns')]
    public function testColonRunsKeepTheirSpelling(string $source, string $expected): void
    {
        $this->assertSame($expected, Writer::format($source));
    }

    private static function fences(int $n): string
    {
        $openers = [];
        for ($k = 0; $k < $n; $k++) {
            $openers[] = str_repeat(':', $n + 3 - $k);
        }

        return implode("\n", [...$openers, '+', '', ...array_reverse($openers)]) . "\n";
    }
}
