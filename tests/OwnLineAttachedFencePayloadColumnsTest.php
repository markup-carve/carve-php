<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OwnLineAttachedFencePayloadColumnsTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function hosts(): iterable
    {
        yield 'item' => ["- p\n+\n```\ncode\n\n  tail\n\nsee\n", '<pre><code>code' . "\n\n  tail\n\n" . '</code></pre>'];
        yield 'note' => ["[^a]: p\n+\n```\ncode\n\n  tail\n\nsee[^a]\n", '<pre><code>code' . "\n\n  tail\n\n" . '</code></pre>'];
        yield 'description' => [":: t\n: p\n+\n```\ncode\n\n  tail\n\nx\n", '<pre><code>code' . "\n\n  tail\n\n" . '</code></pre>'];
    }

    #[DataProvider('hosts')]
    public function testPayloadColumnsStayAtTheFenceOpener(string $source, string $fence): void
    {
        self::assertStringContainsString($fence, (new CarveConverter())->convert($source));
    }
}
