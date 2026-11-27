<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Cli;

use MarkupCarve\Carve\Cli\Command;
use PHPUnit\Framework\TestCase;

final class Fix6931Test extends TestCase
{
    /**
     * @param list<string> $args
     *
     * @return array{int, string, string}
     */
    private static function call(array $args): array
    {
        $in = fopen('php://memory', 'r+');
        $out = fopen('php://memory', 'r+');
        $err = fopen('php://memory', 'r+');
        self::assertIsResource($in);
        self::assertIsResource($out);
        self::assertIsResource($err);
        $status = Command::main($args, $in, $out, $err);
        rewind($out);
        rewind($err);

        return [$status, (string)stream_get_contents($out), (string)stream_get_contents($err)];
    }

    public function testFixAndLintFixReportAppliedDiagnosticCounts(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'carve-cli03-');
        self::assertIsString($path);
        file_put_contents($path, "**bold**\n");
        try {
            foreach ([['fix', $path], ['lint', '--fix', $path]] as $args) {
                self::assertSame(
                    [0, "*bold*\n", $path . ": fixed markdown-strong-double-star 1\n"],
                    self::call($args),
                );
            }
        } finally {
            unlink($path);
        }
    }
}
