<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Cli;

use MarkupCarve\Carve\Cli\Command;
use PHPUnit\Framework\TestCase;

final class Help6930Test extends TestCase
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

    public function testEngineFlagsAppearOnceInCommandHelp(): void
    {
        [$status, $render, $err] = self::call(['render', '--help']);
        self::assertSame(0, $status);
        self::assertSame('', $err);
        self::assertSame(1, substr_count($render, '--to'));
        self::assertSame(1, substr_count($render, '--xhtml'));
        [$status, $import] = self::call(['import', '--help']);
        self::assertSame(0, $status);
        self::assertSame(1, substr_count($import, '--directory'));
    }
}
