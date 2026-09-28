<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Extension\HeadingPermalinksExtension;
use MarkupCarve\Carve\Extension\TableOfContentsExtension;
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

    public function testLargeDocumentsUseBoundedChunks(): void
    {
        foreach (
            [
                rtrim(str_repeat('word & text ', 12000)),
                '# ' . str_repeat('heading', 12000),
                "```\n" . str_repeat('<&>', 30000) . "\n```\n",
                str_repeat("- item with *strong*\n", 4000),
                "| A | B |\n| --- | --- |\n" . str_repeat("| alpha | beta |\n", 4000),
            ] as $source
        ) {
            $converter = new CarveConverter();
            $output = '';
            $calls = 0;
            $outcome = $converter->tryRenderHtmlStreaming($source, static function (string $chunk) use (&$output, &$calls): void {
                self::assertLessThanOrEqual(4096, strlen($chunk));
                self::assertSame(1, preg_match('//u', $chunk));
                $output .= $chunk;
                $calls++;
            });
            self::assertSame('complete', $outcome);
            self::assertGreaterThan(10, $calls);
            self::assertSame($converter->render($converter->parse($source)), $output);
        }
    }

    public function testLateRejectionAndEmptyOutput(): void
    {
        $converter = new CarveConverter();
        $source = str_repeat("plain paragraph\n\n", 10000) . "{unsupported}\n";
        self::assertSame('needs-ast', $converter->tryRenderHtmlStreaming($source, static function (): void {
            self::fail('Rejected source reached the sink');
        }));
        $chunks = [];
        self::assertSame('complete', $converter->tryRenderHtmlStreaming('', static function (string $chunk) use (&$chunks): void {
            $chunks[] = $chunk;
        }));
        self::assertSame([''], $chunks);
    }

    public function testHeadingStateIsAvailableBeforeTheFirstCallback(): void
    {
        $toc = new TableOfContentsExtension();
        $converter = new CarveConverter();
        $converter->addExtensions([$toc, new HeadingPermalinksExtension()]);
        $source = "# A\n\n## B\n";
        $expected = $converter->convert($source);
        $expectedToc = $toc->getToc();
        $output = '';
        self::assertSame('complete', $converter->tryRenderHtmlStreaming($source, static function (string $chunk) use ($toc, $expectedToc, &$output): void {
            self::assertSame($expectedToc, $toc->getToc());
            $output .= $chunk;
        }));
        self::assertSame($expected, $output);
    }
}
