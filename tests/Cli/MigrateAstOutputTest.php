<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Cli;

use MarkupCarve\Carve\Cli\Command;
use PHPUnit\Framework\TestCase;

final class MigrateAstOutputTest extends TestCase
{
    /**
     * carve-js 0.1.17 `carveToAstJson("# Café\n")`: offsets count code points.
     *
     * @var string
     */
    private const CAFE_0_1 = '{"type":"document","children":[{"type":"heading","level":1,"children":[{"type":"text","value":"Café",'
        . '"pos":{"startLine":1,"endLine":1,"startColumn":3,"endColumn":7,"startOffset":2,"endOffset":6}}],'
        . '"pos":{"startLine":1,"endLine":1,"startColumn":1,"endColumn":7,"startOffset":0,"endOffset":6},'
        . '"attrs":{"id":"Café"}}],"srcByteLength":8}';

    /**
     * @param array<string> $args
     * @param string $input
     *
     * @return array{int, string, string}
     */
    private static function call(array $args, string $input): array
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

    public function testBareTreeIsWrittenAsCanonicalJson(): void
    {
        [$status, $out] = self::call(['migrate-ast', '-'], self::CAFE_0_1);

        self::assertSame(0, $status);
        self::assertSame(
            '{"astVersion":"2.0","children":[{"attrs":{"id":"Café"},"children":[{"type":"text","value":"Café"}],'
            . '"level":1,"type":"heading"}],"srcByteLength":8,"type":"document"}' . "\n",
            $out,
        );
    }

    public function testCodePointPositionsWithoutSourceReportMigrationLoss(): void
    {
        [$status, , $err] = self::call(['migrate-ast', '-'], self::CAFE_0_1);

        self::assertSame(0, $status);
        self::assertSame(
            "carve[migration-loss]: positions dropped: 0.1 offsets count code points and no source was given to count bytes\n",
            $err,
        );
    }
}
