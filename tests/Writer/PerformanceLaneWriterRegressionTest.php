<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\TestCase;

final class PerformanceLaneWriterRegressionTest extends TestCase
{
    public function testPipeInsideQuotedRowAttributeIsWritable(): void
    {
        self::assertSame(
            '| a | b |{.c a="x|y|z"}' . "\n",
            Carve::format("| a | b |{a='x|y|z' .c}\n"),
        );
    }

    public function testDigitFirstFrontmatterFormatIsWritable(): void
    {
        $source = "---1\nx\n---\n\ntext\n";
        self::assertSame($source, Carve::format($source));
    }
}
