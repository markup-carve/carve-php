<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use ErrorException;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Parser\ContinuationMarkerClassifier;
use PHPUnit\Framework\TestCase;

final class ContinuationMarkerColumnBoundTest extends TestCase
{
    public function testColumnPastEndStopsAtLastByte(): void
    {
        self::assertSame(5, ContinuationMarkerClassifier::columnAt("a\tb", 3));
        self::assertSame(5, ContinuationMarkerClassifier::columnAt("a\tb", 8));
    }

    public function testNestedFootnoteListEmitsNoOffsetWarning(): void
    {
        set_error_handler(static function (int $severity, string $message): never {
            throw new ErrorException($message, 0, $severity);
        });
        try {
            self::assertSame('', trim((new CarveConverter())->convert("[^n]: - a\n  - +\n")));
        } finally {
            restore_error_handler();
        }
    }
}
