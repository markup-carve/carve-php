<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Cli;

use MarkupCarve\Carve\Cli\Command;
use PHPUnit\Framework\TestCase;

final class Next2CliCasesTest extends TestCase
{
    public function testPinnedCliCases(): void
    {
        $spec = json_decode(
            (string)file_get_contents(__DIR__ . '/../../spec/conformance/clause-cases.json'),
            true,
            4096,
            JSON_THROW_ON_ERROR,
        );
        $ids = ['CC-570', 'CC-579', 'CC-581', 'CC-584', 'CC-911', 'CC-1035', 'CC-1036', 'CC-1366'];
        $seen = [];
        foreach ($spec['cases'] as $case) {
            if (!in_array($case['id'], $ids, true)) {
                continue;
            }
            $seen[] = $case['id'];
            [$status, $stdout, $stderr] = self::runCase($case);
            $expected = $case['expected'];
            self::assertSame($expected['status'], $status, $case['id'] . ' status');
            if (isset($expected['stdout'])) {
                self::assertSame($expected['stdout'], $stdout, $case['id'] . ' output');
            }
            if (isset($expected['stderrCategory'])) {
                self::assertStringStartsWith('carve[' . $expected['stderrCategory'] . ']:', $stderr, $case['id'] . ' error');
            }
        }
        self::assertCount(count($ids), $seen);
    }

    /**
     * @param array<string, mixed> $case
     *
     * @return array{0: int, 1: string, 2: string}
     */
    private static function runCase(array $case): array
    {
        $directory = sys_get_temp_dir() . '/carve-next2-cli-' . bin2hex(random_bytes(8));
        mkdir($directory);
        foreach ($case['files'] ?? [] as $name => $content) {
            file_put_contents($directory . '/' . $name, $content['text']);
        }
        $previous = getcwd();
        chdir($directory);
        $in = fopen('php://memory', 'r+');
        $out = fopen('php://memory', 'r+');
        $err = fopen('php://memory', 'r+');
        self::assertNotFalse($in);
        self::assertNotFalse($out);
        self::assertNotFalse($err);
        fwrite($in, $case['stdin'] ?? '');
        rewind($in);
        try {
            $status = Command::main($case['args'], $in, $out, $err);
            rewind($out);
            rewind($err);

            return [$status, (string)stream_get_contents($out), (string)stream_get_contents($err)];
        } finally {
            chdir($previous);
            foreach (array_keys($case['files'] ?? []) as $name) {
                unlink($directory . '/' . $name);
            }
            rmdir($directory);
        }
    }
}
