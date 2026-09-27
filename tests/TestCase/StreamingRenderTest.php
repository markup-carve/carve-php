<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class StreamingRenderTest extends TestCase
{
    public function testAcceptedHtmlIsDeliveredInExactUtf8Chunks(): void
    {
        $source = "# Heading\n\nSecond paragraph.\n";
        $converter = new CarveConverter();
        $chunks = [];
        $outcome = $converter->tryRenderHtmlStreaming($source, static function (string $chunk) use (&$chunks): void {
            $chunks[] = $chunk;
        });
        $this->assertSame('complete', $outcome);
        $this->assertGreaterThan(1, count($chunks));
        $this->assertSame($converter->convert($source), implode('', $chunks));
        foreach ($chunks as $chunk) {
            $this->assertSame(1, preg_match('//u', $chunk));
        }
    }

    public function testRejectedInputDoesNotCallTheSink(): void
    {
        $outcome = (new CarveConverter())->tryRenderHtmlStreaming("[^note]: Body.\n\nText[^note].\n", static function (): void {
            self::fail('Rejected source reached the sink.');
        });
        $this->assertSame('needs-ast', $outcome);
    }

    public function testSinkExceptionsPropagate(): void
    {
        $this->expectException(RuntimeException::class);
        (new CarveConverter())->tryRenderHtmlStreaming('hello', static function (): void {
            throw new RuntimeException('sink failed');
        });
    }
}
