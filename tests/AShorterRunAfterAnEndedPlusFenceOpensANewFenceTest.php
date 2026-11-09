<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AShorterRunAfterAnEndedPlusFenceOpensANewFenceTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function hosts(): array
    {
        return [
            'item' => ["- +\n````\n\nx\n ```\n"],
            'ordered item' => ["1. +\n````\n\nx\n ```\n"],
            'task item' => ["- [ ] +\n````\n\nx\n ```\n"],
        ];
    }

    #[DataProvider('hosts')]
    public function testShorterRunOpensNewFence(string $source): void
    {
        $html = (new CarveConverter())->convert($source);
        self::assertStringContainsString("<p>x</p>\n<pre><code></code></pre>", $html);
    }
}
