<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve#5638: inline pairing holds a brace line before block classification.
 */
final class AnAttributeLineInsideAnOpenCodeSpanStaysTextTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function documents(): iterable
    {
        yield 'one-line attribute' => ["p\n`open\n{.x}\nclose`\nz\n", "<p>p\n<code>open\n{.x}\nclose</code>\nz</p>"];
        yield 'longer code delimiter' => ["p\n``open\n{#id}\nclose``\nz\n", "<p>p\n<code>open\n{#id}\nclose</code>\nz</p>"];
        yield 'closed code before attribute' => ["p\n`open`\n{.x}\nz\n", "<p>p\n<code>open</code></p>\n<p class=\"x\">z</p>"];
    }

    #[DataProvider('documents')]
    public function testAttributeLineUsesTheOpenInlineSpan(string $source, string $expected): void
    {
        self::assertSame($expected, trim((new CarveConverter())->convert($source)));
    }

    public function testTheWriterCanSpellAnAttributeLineInsideCode(): void
    {
        $source = "``\n{:grc}\nΜῆνιν ἄειδε θεά\n";
        $written = CarveConverter::toCarve($source);

        self::assertSame(
            trim((new CarveConverter())->convert($source)),
            trim((new CarveConverter())->convert($written)),
        );
    }
}
