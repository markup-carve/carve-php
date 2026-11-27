<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Import;

use MarkupCarve\Carve\Cli\Command;
use PHPUnit\Framework\TestCase;

final class ImportSuppressedRefusalI02Test extends TestCase
{
    /**
     * @param array<string> $args
     * @param string $html
     * @param string $from
     *
     * @return array{int, string, string}
     */
    private static function cli(array $args, string $html, string $from = 'html'): array
    {
        $in = fopen('php://memory', 'r+');
        $out = fopen('php://memory', 'r+');
        $err = fopen('php://memory', 'r+');
        self::assertNotFalse($in);
        self::assertNotFalse($out);
        self::assertNotFalse($err);
        fwrite($in, $html);
        rewind($in);
        $status = Command::main(['import', '--from', $from, ...$args], $in, $out, $err);
        rewind($out);
        rewind($err);

        return [$status, (string)stream_get_contents($out), (string)stream_get_contents($err)];
    }

    public function testSuppressedWriterLossStillRefusesSource(): void
    {
        $html = str_repeat('<!--x-->', 101) . '<!doctype html><html lang="en" dir="ltr"><body><p>x</p></body></html>';
        [$status, $out, $err] = self::cli(['--report-losses', '-'], $html);
        self::assertSame(1, $status);
        self::assertSame('', $out);
        self::assertStringStartsWith('carve[writer-refused]:', $err);
        $lines = explode("\n", trim($err));
        $report = json_decode($lines[count($lines) - 1], true, 512, JSON_THROW_ON_ERROR);
        if (
            !is_array($report) || !is_array($report['files'] ?? null)
            || !is_array($report['files'][0] ?? null)
            || !is_array($report['files'][0]['diagnostics'] ?? null)
        ) {
            self::fail('The CLI did not write a diagnostics report');
        }
        self::assertCount(100, $report['files'][0]['diagnostics']);
        self::assertSame(4, $report['files'][0]['suppressed']);
    }

    public function testHubFilteredLossDoesNotCauseStrictFailure(): void
    {
        [$status, $out, $err] = self::cli(['--strict-losses'], "a  \nb\n", 'markdown');
        self::assertSame(0, $status);
        self::assertSame('', $err);
        self::assertNotSame('', $out);
    }
}
