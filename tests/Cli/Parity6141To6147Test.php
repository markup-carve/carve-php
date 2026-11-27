<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Cli;

use MarkupCarve\Carve\Cli\Command;
use PHPUnit\Framework\TestCase;

final class Parity6141To6147Test extends TestCase
{
    /**
     * @param array<string> $args
     * @param string $input
     *
     * @return array{int, string, string}
     */
    private static function call(array $args, string $input = ''): array
    {
        $in = fopen('php://memory', 'r+');
        $out = fopen('php://memory', 'r+');
        $err = fopen('php://memory', 'r+');
        self::assertIsResource($in);
        self::assertIsResource($out);
        self::assertIsResource($err);
        fwrite($in, $input);
        rewind($in);
        $status = Command::main($args, $in, $out, $err);
        rewind($out);
        rewind($err);

        return [$status, (string)stream_get_contents($out), (string)stream_get_contents($err)];
    }

    public function test6141HelpNamesCommandFlags(): void
    {
        [$status, $out] = self::call(['ast', '--help']);
        self::assertSame(0, $status);
        self::assertStringContainsString('Usage: carve ast', $out);
        self::assertStringContainsString('--envelope', $out);
        [$status, $out] = self::call(['import', '--help']);
        self::assertSame(0, $status);
        self::assertStringContainsString('--report-losses', $out);
        [$status, $out] = self::call(['fix', '--help']);
        self::assertSame(0, $status);
        self::assertStringContainsString('--rules', $out);
        self::assertStringContainsString('--write', $out);
    }

    public function test6142AstEnvelope(): void
    {
        [$status, $out] = self::call(['ast', '--envelope'], "# Title\n");
        self::assertSame(0, $status);
        $tree = json_decode($out, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('2.0', $tree['astVersion']);
        self::assertSame('document', $tree['document']['type']);
        self::assertStringEndsWith("\n", $out);
    }

    public function test6143ImportReportAndNegatedSwitches(): void
    {
        $args = ['import', '--from', 'html', '--report-losses', '-', '--no-raw-html', '--no-strict-losses'];
        [$status, $out, $err] = self::call($args, '<p><img src="x"></p>');
        self::assertSame(0, $status);
        self::assertSame("![](x)\n", $out);
        self::assertStringContainsString('"code":"image-alt-unknown"', $err);
        self::assertStringContainsString('"suppressed":0', $err);
        $path = tempnam(sys_get_temp_dir(), 'carve-import-loss-');
        self::assertIsString($path);
        try {
            [$status, $out] = self::call(['import', '--from', 'html', '--report-losses=' . $path], '<p>ok</p>');
            self::assertSame(0, $status);
            self::assertSame("ok\n", $out);
            $report = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame([], $report['files'][0]['diagnostics']);
            self::assertSame(0, $report['files'][0]['suppressed']);
        } finally {
            unlink($path);
        }
    }

    public function test6144StrictImportPrintsRetainedLoss(): void
    {
        [$status, $out, $err] = self::call(['import', '--from', 'html', '--strict-losses'], '<p><img src="x"></p>');
        self::assertSame(1, $status);
        self::assertSame('', $out);
        self::assertStringContainsString('carve[render-loss]:', $err);
        self::assertStringContainsString(': error image-alt-unknown ', $err);
    }

    public function test6143ReportCannotOverwriteOutput(): void
    {
        $path = sys_get_temp_dir() . '/carve-import-shared-' . bin2hex(random_bytes(6));
        [$status, $out, $err] = self::call(
            ['import', '--from', 'html', '--output', $path, '--report-losses', dirname($path) . '/./' . basename($path)],
            '<p>ok</p>',
        );
        self::assertSame(2, $status);
        self::assertSame('', $out);
        self::assertStringContainsString('shares a path', $err);
        self::assertFileDoesNotExist($path);
    }

    public function test6145InvalidUtf8AstInputIsUnreadable(): void
    {
        self::assertSame([2, '', "carve[input-unreadable]: <stdin>: not UTF-8\n"], self::call(['migrate-ast'], "\xff"));
    }

    public function test6146FixRepairsSourceBytes(): void
    {
        [$status, $out] = self::call(['fix'], "a\xffb\n");
        self::assertSame(0, $status);
        self::assertSame("a\u{FFFD}b\n", $out);
    }

    public function test6147SpaceOptionDecodesJson(): void
    {
        [$status, $out] = self::call(['render', '--option', 'headingShift=1'], "# Title\n");
        self::assertSame(0, $status);
        self::assertStringContainsString('<h2>Title</h2>', $out);
    }
}
