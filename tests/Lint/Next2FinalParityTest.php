<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Lint;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class Next2FinalParityTest extends TestCase
{
    /**
     * @return array<string, array{string, array<string>}>
     */
    public static function cases(): array
    {
        return [
            'two-marker heading' => ["## H\n x", ['deprecated-indented-line-under-heading']],
            'long heading and tab' => ["#### H\n\tmore", ['deprecated-indented-line-under-heading']],
            'indented heading' => ["  ## H\n   x", ['block-opener-overindented', 'deprecated-indented-line-under-heading']],
            'line at heading marker column' => ["  ## H\n  x", ['block-opener-overindented']],
            'lazy quoted item and underline' => [
                "> - item\n  # H\n> ---", ['block-opener-without-blank-line', 'setext-heading-underline'],
            ],
            'new quote still opens' => [
                "base\n> ---", ['block-opener-without-blank-line', 'setext-heading-underline'],
            ],
        ];
    }

    /**
     * @param string $source
     * @param array<string> $expected
     */
    #[DataProvider('cases')]
    public function testCoreLint(string $source, array $expected): void
    {
        self::assertSame($expected, array_map(static fn ($d): string => $d->code, Carve::lint($source, ['upgrade' => true])));
    }
}
