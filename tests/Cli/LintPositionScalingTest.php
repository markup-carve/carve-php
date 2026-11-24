<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Cli;

use Closure;
use MarkupCarve\Carve\Cli\Command;
use PHPUnit\Framework\TestCase;

final class LintPositionScalingTest extends TestCase
{
    /**
     * C2-W-156: the bare underline is a migration notice, on here by id.
     *
     * @var array<string>
     */
    private const ON = ['--rules', '{"deprecated-bare-underline":"on"}'];

    public function testTextAndJsonPositionsAcrossUnicodeAndLines(): void
    {
        $source = "😀 _a_ _b_\n\n_c_\n";
        [$status, $output] = self::call(['lint', ...self::ON], $source);
        self::assertSame(1, $status);
        self::assertStringContainsString('<stdin>:1:3: warning deprecated-bare-underline', $output);
        self::assertStringContainsString('<stdin>:1:7: warning deprecated-bare-underline', $output);
        self::assertStringContainsString('<stdin>:3:1: warning deprecated-bare-underline', $output);

        [$status, $json] = self::call(['lint', '--json', ...self::ON], $source);
        self::assertSame(1, $status);
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertIsArray($data['files']);
        self::assertIsArray($data['files'][0]);
        self::assertIsArray($data['files'][0]['diagnostics']);
        $positions = [];
        foreach ($data['files'][0]['diagnostics'] as $finding) {
            self::assertIsArray($finding);
            $positions[] = [$finding['line'], $finding['column']];
        }
        self::assertSame([[1, 3], [1, 7], [3, 1]], $positions);
    }

    public function testManyFindingsOnOneLineAndManyLines(): void
    {
        foreach ([str_repeat('_a_ ', 1024), str_repeat("_a_\n", 1024)] as $source) {
            [$status, $output] = self::call(['lint', ...self::ON], $source);
            self::assertSame(1, $status);
            self::assertCount(1024, array_filter(explode("\n", trim($output))));
        }
    }

    public function testPositionWorkGrowsWithInputAndFindings(): void
    {
        $small = str_repeat('_a_ ', 2048);
        $large = str_repeat('_a_ ', 8192);
        $smallTime = self::bestTime(static fn (): array => self::call(['lint', ...self::ON], $small));
        $largeTime = self::bestTime(static fn (): array => self::call(['lint', ...self::ON], $large));
        self::assertLessThan($smallTime * 6, $largeTime);
    }

    /**
     * @param \Closure(): array<mixed> $scan
     */
    private static function bestTime(Closure $scan): int
    {
        $best = PHP_INT_MAX;
        for ($trial = 0; $trial < 3; $trial++) {
            $start = hrtime(true);
            $scan();
            $best = min($best, hrtime(true) - $start);
        }

        return $best;
    }

    /**
     * @param array<string> $args
     * @param string $source
     *
     * @return array{int, string}
     */
    private static function call(array $args, string $source): array
    {
        $in = fopen('php://memory', 'r+');
        $out = fopen('php://memory', 'r+');
        $err = fopen('php://memory', 'r+');
        self::assertNotFalse($in);
        self::assertNotFalse($out);
        self::assertNotFalse($err);
        fwrite($in, $source);
        rewind($in);
        $status = Command::main($args, $in, $out, $err);
        rewind($out);

        return [$status, (string)stream_get_contents($out)];
    }
}
