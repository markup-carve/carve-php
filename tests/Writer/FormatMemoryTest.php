<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use PHPUnit\Framework\TestCase;

final class FormatMemoryTest extends TestCase
{
    public function testLongParagraphWithNestedLinksFitsDefaultMemoryLimit(): void
    {
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        $program = 'require ' . var_export($autoload, true) . '; '
            . '$part = "^*[*[[*^*[*[[*^*[*[[*^*[*x](u)^]]](u)^]]](u)^]]](u)"; '
            . '$source = implode(" ", array_fill(0, (int)ceil(131072 / 53), $part)) . "\n"; '
            . '$result = \\MarkupCarve\\Carve\\Carve::formatWithDiagnostics($source); '
            . 'echo strlen($source), ":", count($result->diagnostics);';

        $process = proc_open([PHP_BINARY, '-d', 'memory_limit=128M', '-r', $program], [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        self::assertSame(0, proc_close($process), $stderr);
        self::assertSame('128648:0', $stdout);
    }
}
