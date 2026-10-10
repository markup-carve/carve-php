<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\DjotToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DjotPlaceholderPrefixesTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function placeholders(): array
    {
        $rows = [];
        foreach (["\0DJOTSTRONG", "\0DJOTWORD", "\0DJOTORPHAN\0", "\0DJOTEMPTYTERM\0", "\0DJOTALT\0"] as $base) {
            $tokens = [
                'overlap' => $base . "0\0" . substr($base, 1) . "1\0",
                'leading-zero' => $base . "00\0",
                'long-run' => $base . str_repeat("\0", 32768),
                'reserved-series' => implode('', array_map(static fn (int $n): string => $base . $n . "\0", range(0, 127))),
            ];
            foreach ($tokens as $mode => $token) {
                $rows[str_replace("\0", '', $base) . '-' . $mode] = [$token];
            }
        }

        return $rows;
    }

    #[DataProvider('placeholders')]
    public function testUserPlaceholdersArePreserved(string $token): void
    {
        $source = $token . " w{x}{.c} ![*alt*](u)\n\na {.o} b\n\n{.orphan}\n\n: ```\n  payload\n  ```\n";
        $converted = (new DjotToCarve())->convert($source);
        self::assertStringContainsString($token, $converted);
        self::assertStringNotContainsString("\0DJOT", str_replace($token, '', $converted));
        $html = (new CarveConverter())->convert($converted);
        self::assertStringContainsString('class="c"', $html);
        self::assertStringContainsString('alt="alt"', $html);
        self::assertStringContainsString('<dd>', $html);
        self::assertStringContainsString('a  b', $html);
    }

    public function testFrontmatterKeepsUserPlaceholderText(): void
    {
        $prefix = "---\nlabel: \0DJOTWORD0\0\n---\n\n";
        $converted = (new DjotToCarve())->convert($prefix . "\0DJOTWORD0\0 w{x}{.c}");
        self::assertStringStartsWith($prefix, $converted);
        self::assertStringContainsString("\0DJOTWORD0\0", $converted);
        self::assertStringContainsString('class="c"', (new CarveConverter())->convert($converted));
    }
}
