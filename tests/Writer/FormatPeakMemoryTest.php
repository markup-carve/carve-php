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
     * The pages a parse or write leaves empty go back to the allocator before the next long-lived tree is built, so the
     * allocated heap stays near what is in use: 60.5 MB here, against 68.5 MB without (66.0 MB on 0.1.18).
     */
    public function testQuotedSuperscriptParagraphAllocatesUnderSixtyFourMegabytes(): void
    {
        if (PHP_VERSION_ID < 80400) {
            self::markTestSkipped('Allocator figures measured on PHP 8.4 and 8.5.');
        }
        $fixtures = var_export(dirname(__DIR__) . '/fixtures/writer/', true);
        $autoload = var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true);
        $program = 'require ' . $autoload . '; '
            . '$line = rtrim(file_get_contents(' . $fixtures . ' . "superscript-quote-line.txt")); '
            . '$source = "> " . implode("\n> ", array_fill(0, 47, $line)) . "\n"; '
            . '\MarkupCarve\Carve\Carve::formatWithDiagnostics($source); '
            . 'echo memory_get_peak_usage(true);';

        // Without opcache the compiled code is on the heap too, the same on every run.
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
        self::assertLessThan(64 * 1024 * 1024, (int)$stdout);
    }
}
