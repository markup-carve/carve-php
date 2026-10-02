<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Performance;

use MarkupCarve\Carve\Performance\HtmlOutput;
use PHPUnit\Framework\TestCase;

final class HtmlOutputTest extends TestCase
{
    public function testBufferedAndStreamedFragmentsHaveIdenticalEscaping(): void
    {
        $chunks = [];
        $buffered = new HtmlOutput();
        $streamed = new HtmlOutput(static function (string $chunk) use (&$chunks): void {
            $chunks[] = $chunk;
        });
        foreach ([$buffered, $streamed] as $output) {
            $output->push('<p title="');
            $output->attr('" & <');
            $output->push('">', 'before ');
            $output->text(str_repeat("<>&\u{00A0}😀", 1024));
            $output->push(' after', '</p>');
        }
        $expected = '<p title="&quot; &amp; &lt;">before '
            . str_repeat('&lt;&gt;&amp;&nbsp;😀', 1024) . ' after</p>';

        $this->assertSame($expected, $buffered->finish());
        $this->assertSame('', $streamed->finish());
        $this->assertSame($expected, implode('', $chunks));
        foreach ($chunks as $chunk) {
            $this->assertLessThanOrEqual(4096, strlen($chunk));
            $this->assertTrue(mb_check_encoding($chunk, 'UTF-8'));
        }
    }

    public function testEmptyPushPreservesEmptyBufferedAndStreamedOutput(): void
    {
        $chunks = [];
        $buffered = new HtmlOutput();
        $streamed = new HtmlOutput(static function (string $chunk) use (&$chunks): void {
            $chunks[] = $chunk;
        });
        $buffered->push();
        $streamed->push();
        $streamed->push('');
        $this->assertSame([], $chunks);

        $this->assertSame('', $buffered->finish());
        $this->assertSame('', $streamed->finish());
        $this->assertSame([''], $chunks);
    }

    public function testStreamedEmptyFragmentsDoNotAddChunks(): void
    {
        $chunks = [];
        $streamed = new HtmlOutput(static function (string $chunk) use (&$chunks): void {
            $chunks[] = $chunk;
        });
        $streamed->push('a', '', 'b');

        $this->assertSame('', $streamed->finish());
        $this->assertSame(['ab'], $chunks);
    }

    public function testStreamedFragmentsPreserveByteBoundariesAcrossArguments(): void
    {
        $cases = [
            [str_repeat('a', 4000), str_repeat('b', 200), [4096, 104]],
            [str_repeat('a', 4095), 'é' . str_repeat('b', 100), [4095, 102]],
        ];
        foreach ($cases as [$first, $second, $sizes]) {
            $chunks = [];
            $streamed = new HtmlOutput(static function (string $chunk) use (&$chunks): void {
                $chunks[] = $chunk;
            });
            $streamed->push($first, $second);
            $this->assertSame('', $streamed->finish());
            $this->assertSame($first . $second, implode('', $chunks));
            $this->assertSame($sizes, array_map(strlen(...), $chunks));
            foreach ($chunks as $chunk) {
                $this->assertTrue(mb_check_encoding($chunk, 'UTF-8'));
            }
        }
    }

    public function testDiscardedFragmentsProduceNoOutput(): void
    {
        $output = new HtmlOutput(discard: true);
        $output->push('<p>', 'content');
        $output->text('<>&');
        $output->attr('"');

        $this->assertSame('', $output->finish());
    }
}
