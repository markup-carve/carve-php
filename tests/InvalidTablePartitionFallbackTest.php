<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InvalidTablePartitionFallbackTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function partitions(): iterable
    {
        yield 'invalid footer count' => ["{footer-rows=>}\n|= A |\n", true];
        yield 'invalid header count' => ["{header-rows=>}\n|= A |\n", true];
        yield 'overlapping counts' => ["{header-rows=1 footer-rows=1}\n|= A |\n", true];
        yield 'invalid body counts retain implicit head' => ["{body-rows=>}\n|= A |\n", false];
        yield 'invalid body headers retain implicit head' => ["{body-header-rows=>}\n|= A |\n", false];
        yield 'no partition metadata' => ["|= A |\n", false];
    }

    #[DataProvider('partitions')]
    public function testInvalidCountsDoNotRestoreAHeaderSection(string $source, bool $fallback): void
    {
        $html = (new CarveConverter())->convert($source);

        if ($fallback) {
            self::assertStringContainsString('<tbody>', $html);
            self::assertStringContainsString('<th scope="row">A</th>', $html);
            self::assertStringNotContainsString('<thead>', $html);
        } else {
            self::assertStringContainsString('<thead>', $html);
            self::assertStringContainsString('<th scope="col">A</th>', $html);
        }
    }
}
