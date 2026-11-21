<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Test\Support\Corpus;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class StreamingRenderTest extends TestCase
{
    public function testEveryClauseCaseStreamsExactlyItsNormalHtml(): void
    {
        foreach (ClauseCasesTest::cases() as [$row]) {
            self::assertIsString($row['input'] ?? null);
            self::assertIsString($row['case'] ?? null);
            self::assertIsArray($row['options'] ?? null);
            $options = Corpus::parseOptions($row['options']);
            $chunks = [];
            $outcome = Carve::tryRenderHtmlStreaming($row['input'], static function (string $html) use (&$chunks): void {
                $chunks[] = $html;
            }, $options);
            self::assertSame('complete', $outcome, $row['case']);
            self::assertSame([Carve::render($row['input'], $options)], $chunks, $row['case']);
        }
    }

    public function testEmptyOutputStillCallsTheSinkOnce(): void
    {
        $chunks = [];
        self::assertSame('complete', Carve::tryRenderHtmlStreaming('', static function (string $html) use (&$chunks): void {
            $chunks[] = $html;
        }));
        self::assertSame([Carve::render('')], $chunks);
    }

    public function testSinkExceptionsPropagate(): void
    {
        $this->expectException(RuntimeException::class);
        Carve::tryRenderHtmlStreaming('hello', static function (): void {
            throw new RuntimeException('sink failed');
        });
    }
}
