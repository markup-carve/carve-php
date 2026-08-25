<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class BareColonFenceTrailingWhitespaceTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function trailingWhitespaceProvider(): array
    {
        return [
            'space' => [' '],
            'tab' => ["\t"],
            'mixed run' => [" \t "],
        ];
    }

    #[DataProvider('trailingWhitespaceProvider')]
    public function testTrailingWhitespaceLeavesABareFenceBare(string $whitespace): void
    {
        $source = ":::{$whitespace}\ntext\n:::\n";

        $this->assertSame("<div>\n  <p>text</p>\n</div>\n", (new CarveConverter())->convert($source));
    }

    public function testATabBeforeATypeTokenStillDoesNotOpenAContainer(): void
    {
        $source = ":::\tnote\ntext\n:::\n";

        $this->assertStringStartsWith('<p>:::', (new CarveConverter())->convert($source));
    }
}
