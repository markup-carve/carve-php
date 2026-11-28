<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Cli;

use MarkupCarve\Carve\Cli\Command;
use MarkupCarve\Carve\Test\Support\Corpus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class Next2FlagsTest extends TestCase
{
    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function cases(): array
    {
        $ids = [
            'CC-519', 'CC-520', 'CC-521', 'CC-526', 'CC-527', 'CC-529', 'CC-532', 'CC-538',
            'CC-540', 'CC-541', 'CC-543', 'CC-555', 'CC-556', 'CC-559', 'CC-560', 'CC-562',
            'CC-565', 'CC-566', 'CC-567', 'CC-568', 'CC-571', 'CC-572', 'CC-576', 'CC-910',
            'CC-1051', 'CC-1105', 'CC-2521', 'CC-2522', 'CC-2527',
        ];
        $out = [];
        foreach (Corpus::clauseCases() as $case) {
            if (in_array($case['id'] ?? null, $ids, true)) {
                $out[$case['id']] = [$case];
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $case
     */
    #[DataProvider('cases')]
    public function testPinnedFlagCase(array $case): void
    {
        $dir = sys_get_temp_dir() . '/carve-next2-flags-' . bin2hex(random_bytes(6));
        mkdir($dir);
        try {
            foreach ($case['files'] ?? [] as $name => $content) {
                $path = $dir . '/' . $name;
                if (!is_dir(dirname($path))) {
                    mkdir(dirname($path), 0777, true);
                }
                file_put_contents($path, $content['text'] ?? hex2bin(str_replace(' ', '', $content['bytes'])));
            }
            $bin = dirname(__DIR__, 2) . '/bin/carve';
            $process = proc_open([PHP_BINARY, $bin, ...$case['args']], [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ], $pipes, $dir);
            self::assertIsResource($process);
            fwrite($pipes[0], $case['stdin'] ?? '');
            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $status = proc_close($process);
            self::checkExpected($case, $dir, $status, $stdout, $stderr);
        } finally {
            $entries = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($entries as $entry) {
                if ($entry instanceof SplFileInfo && $entry->isDir()) {
                    rmdir($entry->getPathname());
                } elseif ($entry instanceof SplFileInfo) {
                    unlink($entry->getPathname());
                }
            }
            rmdir($dir);
        }
    }

    /**
     * @param array<string, mixed> $case
     * @param string $stderr
     * @param string $stdout
     * @param int $status
     * @param string $dir
     */
    private static function checkExpected(array $case, string $dir, int $status, string $stdout, string $stderr): void
    {
        $expected = $case['expected'];
        self::assertSame($expected['status'], $status, $case['id'] . ' status');
        if (array_key_exists('stdout', $expected)) {
            self::assertSame($expected['stdout'], $stdout, $case['id'] . ' stdout');
        }
        if (isset($expected['stdoutJson'])) {
            self::assertEquals($expected['stdoutJson'], json_decode($stdout, true, 4096, JSON_THROW_ON_ERROR));
        }
        if (array_key_exists('stderrCategory', $expected)) {
            preg_match('/^carve\[([^\]]+)\]:/', $stderr, $match);
            self::assertSame($expected['stderrCategory'], $match[1] ?? null, $case['id'] . ' stderr category');
        }
        if (isset($expected['stderrDiagnostics'])) {
            $diagnostics = [];
            foreach (explode("\n", rtrim($stderr, "\n")) as $line) {
                if (preg_match('/^(<stdin>|[^:]+)(?::\d+:\d+)?: (?:warning|error|info) (\S+) /', $line, $match) === 1) {
                    $diagnostics[] = ['code' => $match[2], 'file' => $match[1]];
                }
            }
            self::assertSame($expected['stderrDiagnostics'], $diagnostics, $case['id'] . ' stderr diagnostics');
        }
        foreach ($expected['filesAfter'] ?? [] as $name => $wanted) {
            $path = $dir . '/' . $name;
            if ($wanted === null) {
                self::assertFileDoesNotExist($path);
            } elseif (isset($wanted['json'])) {
                self::assertEquals($wanted['json'], json_decode((string)file_get_contents($path), true, 4096, JSON_THROW_ON_ERROR));
            }
        }
    }

    public function testSuppressedLossStillFailsStrictRun(): void
    {
        $in = fopen('php://memory', 'r+');
        $out = fopen('php://memory', 'r+');
        $err = fopen('php://memory', 'r+');
        self::assertIsResource($in);
        self::assertIsResource($out);
        self::assertIsResource($err);
        fwrite($in, "[a](javascript:x)\n");
        rewind($in);
        $status = Command::main(['render', '--strict-losses', '--max-render-losses', '0', '-'], $in, $out, $err);
        rewind($out);
        rewind($err);
        self::assertSame(1, $status);
        self::assertSame('', stream_get_contents($out));
        self::assertStringStartsWith('carve[render-loss]:', (string)stream_get_contents($err));
    }

    public function testIncludeReportWorksForDiagnosticsAndRender(): void
    {
        foreach (['diagnostics', 'render'] as $command) {
            $in = fopen('php://memory', 'r+');
            $out = fopen('php://memory', 'r+');
            $err = fopen('php://memory', 'r+');
            self::assertIsResource($in);
            self::assertIsResource($out);
            self::assertIsResource($err);
            fwrite($in, "a\n");
            rewind($in);
            self::assertSame(0, Command::main([$command, '--report-includes', '-', '-'], $in, $out, $err));
            rewind($err);
            self::assertSame(
                ['files' => [['file' => '<stdin>', 'dependencies' => []]]],
                json_decode((string)stream_get_contents($err), true, 4096, JSON_THROW_ON_ERROR),
            );
        }
    }

    public function testMarkdownExportReportsLosses(): void
    {
        $in = fopen('php://memory', 'r+');
        $out = fopen('php://memory', 'r+');
        $err = fopen('php://memory', 'r+');
        self::assertIsResource($in);
        self::assertIsResource($out);
        self::assertIsResource($err);
        fwrite($in, "::: x\nz\n:::\n");
        rewind($in);
        self::assertSame(0, Command::main(['render', '--to', 'markdown', '--report-losses', '-', '-'], $in, $out, $err));
        rewind($err);
        $diagnostics = (string)stream_get_contents($err);
        self::assertStringContainsString('markdown-container-flattened', $diagnostics);
        self::assertStringContainsString('"suppressed":0', $diagnostics);
    }

    public function testMarkdownExportValidatesRenderOptions(): void
    {
        $in = fopen('php://memory', 'r+');
        $out = fopen('php://memory', 'r+');
        $err = fopen('php://memory', 'r+');
        self::assertIsResource($in);
        self::assertIsResource($out);
        self::assertIsResource($err);
        fwrite($in, "# Hello\n");
        rewind($in);
        self::assertSame(2, Command::main(['render', '--to', 'markdown', '--heading-ids', 'bogus', '-'], $in, $out, $err));
        rewind($err);
        self::assertStringStartsWith('carve[usage]:', (string)stream_get_contents($err));
    }
}
