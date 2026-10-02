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

    public function testDiscardedFragmentsProduceNoOutput(): void
    {
        $output = new HtmlOutput(discard: true);
        $output->push('<p>', 'content');
        $output->text('<>&');
        $output->attr('"');

        $this->assertSame('', $output->finish());
    }
}
