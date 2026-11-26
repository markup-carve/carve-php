<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use PHPUnit\Framework\TestCase;

/**
 * carve#6413: the quoted superscript paragraph at 128 KB below the memory the release needs.
 */
final class FormatPeakMemoryTest extends TestCase
{
    public function testQuotedSuperscriptParagraphFitsEightyMegabytes(): void
    {
        $fixtures = var_export(dirname(__DIR__) . '/fixtures/writer/', true);
        $autoload = var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true);
        $program = 'require ' . $autoload . '; '
            . '$line = rtrim(file_get_contents(' . $fixtures . ' . "superscript-quote-line.txt")); '
            . '$source = "> " . implode("\n> ", array_fill(0, 47, $line)) . "\n"; '
            . '$result = \MarkupCarve\Carve\Carve::formatWithDiagnostics($source); '
            . 'echo strlen($source), ":", count($result->diagnostics);';

        $process = proc_open([PHP_BINARY, '-d', 'memory_limit=80M', '-r', $program], [
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

        self::assertSame(0, proc_close($process), (string)$stderr);
        self::assertSame('131459:0', $stdout);
    }

    /**
     * Measured after a one-line warm-up format, so loaded and compiled classes do not count: the format holds 46.8 MB
     * at its peak (62.6 MB before carve-php#5793), and the pages a parse or write leaves empty go back to the allocator
     * before the next long-lived tree is built, so the allocated heap grows at most 6 MB beyond that (12 MB or more
     * without `HeapPages`).
     */
    public function testQuotedSuperscriptParagraphAllocatesNearWhatItUses(): void
    {
        if (PHP_VERSION_ID < 80400) {
            self::markTestSkipped('Allocator figures measured on PHP 8.4 and 8.5.');
        }
        $fixtures = var_export(dirname(__DIR__) . '/fixtures/writer/', true);
        $autoload = var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true);
        $program = 'require ' . $autoload . '; '
            . '$line = rtrim(file_get_contents(' . $fixtures . ' . "superscript-quote-line.txt")); '
            . '\MarkupCarve\Carve\Carve::formatWithDiagnostics("> " . $line . "\n"); '
            . '$source = "> " . implode("\n> ", array_fill(0, 47, $line)) . "\n"; '
            . '$used = memory_get_usage(); '
            . '$allocated = memory_get_usage(true); '
            . 'memory_reset_peak_usage(); '
            . '\MarkupCarve\Carve\Carve::formatWithDiagnostics($source); '
            . 'echo memory_get_peak_usage() - $used, ":", memory_get_peak_usage(true) - $allocated;';

        // Without opcache the classes the warm-up misses are compiled onto the heap the same way on every run.
        $process = proc_open([PHP_BINARY, '-d', 'memory_limit=-1', '-d', 'opcache.enable_cli=0', '-r', $program], [
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

        self::assertSame(0, proc_close($process), (string)$stderr);
        [$grown, $allocated] = array_map('intval', explode(':', (string)$stdout));
        self::assertLessThan(52 * 1024 * 1024, $grown, 'Memory the format holds at its peak');
        self::assertLessThan(8 * 1024 * 1024, $allocated - $grown, 'Heap allocated beyond what the format holds');
    }
}
