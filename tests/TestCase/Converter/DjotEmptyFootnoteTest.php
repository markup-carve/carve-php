<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\DjotToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DjotEmptyFootnoteTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function noteProvider(): iterable
    {
        yield 'missing definition' => ["ref[^missing]\n"];
        yield 'empty definition' => ["ref[^n]\n\n[^n]:\n"];
        yield 'code-only definition' => ["ref[^n]\n\n[^n]:\n  ```\n  code\n  ```\n"];
    }

    #[DataProvider('noteProvider')]
    public function testGeneratedMarkerIsInvisible(string $source): void
    {
        $html = (new CarveConverter())->convert((new DjotToCarve())->convert($source));
        self::assertStringContainsString('role="doc-noteref"', $html);
        self::assertStringContainsString('<li id="fn1">', $html);
        self::assertStringNotContainsString('%%%%', $html);
        if (str_contains($source, 'code')) {
            self::assertStringContainsString("<pre><code>code\n</code></pre>", $html);
        }
    }

    public function testAuthoredPercentRunStaysLiteral(): void
    {
        $html = (new CarveConverter())->convert((new DjotToCarve())->convert("ref[^n]\n\n[^n]: %%%%\n"));
        self::assertStringContainsString('<p>%%%%', $html);
    }
}
