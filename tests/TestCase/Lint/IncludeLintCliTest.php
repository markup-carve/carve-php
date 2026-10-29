<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Lint;

use MarkupCarve\Carve\Lint\DocumentLinter;
use MarkupCarve\Carve\Lint\IncludeLinter;
use MarkupCarve\Carve\Test\RuleATodo;
use PHPUnit\Framework\TestCase;

final class IncludeLintCliTest extends TestCase
{
    use RuleATodo;

    public function testNoResolverKeepsDocumentLint(): void
    {
        $source = "{{ missing.crv }}\n\n**bold**\n";
        self::assertEquals((new DocumentLinter())->lint($source), (new IncludeLinter())->lint($source));
    }

    public function testMalformedIncludedTable(): void
    {
        $directory = sys_get_temp_dir() . '/carve-lint-' . bin2hex(random_bytes(8));
        mkdir($directory . '/data', 0700, true);
        file_put_contents($directory . '/doc.crv', "{table}\n{{ data/plans.csv @code:csv }}\n");
        file_put_contents($directory . '/data/plans.csv', "Plan,Seats,Price\nStarter,1,\"$0\"\nTeam,10,\"$49\" billed yearly\n");
        try {
            $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../../../bin/carve');
            $root = escapeshellarg($directory);
            exec('cd ' . $root . ' && ' . $command . ' lint --include-root . doc.crv 2>&1', $output, $status);
            self::assertSame(1, $status);
            self::assertCount(1, $output);
            self::assertStringStartsWith('data/plans.csv:3:1 delimited-table-malformed', $output[0]);
            self::assertStringEndsWith('(included from doc.crv:2:1)', $output[0]);
            $output = [];
            exec('cd ' . $root . ' && ' . $command . ' lint doc.crv 2>&1', $output, $status);
            self::assertSame(0, $status);
            self::assertSame([], $output);
        } finally {
            unlink($directory . '/data/plans.csv');
            unlink($directory . '/doc.crv');
            rmdir($directory . '/data');
            rmdir($directory);
        }
    }
}
