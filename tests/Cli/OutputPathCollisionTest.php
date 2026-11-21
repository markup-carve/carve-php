<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Cli;

use MarkupCarve\Carve\Cli\Command;
use PHPUnit\Framework\TestCase;

final class OutputPathCollisionTest extends TestCase
{
    public function testReportAndOutputPathsAreCheckedBeforeInput(): void
    {
        $cases = [
            ['render', '--report-includes', 'r.json', '--report-losses', './r.json', 'missing.crv'],
            ['render', '-o', 'r.json', '--report-losses', 'r.json', 'missing.crv'],
        ];
        foreach ($cases as $args) {
            $in = fopen('php://memory', 'r+');
            $out = fopen('php://memory', 'r+');
            $err = fopen('php://memory', 'r+');
            self::assertNotFalse($in);
            self::assertNotFalse($out);
            self::assertNotFalse($err);
            $this->assertSame(2, Command::main($args, $in, $out, $err));
            rewind($out);
            rewind($err);
            $this->assertSame('', stream_get_contents($out));
            $this->assertStringStartsWith('carve[usage]:', (string)stream_get_contents($err));
        }
    }
}
