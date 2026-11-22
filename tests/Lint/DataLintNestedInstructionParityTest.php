<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Lint;

use PHPUnit\Framework\TestCase;

/**
 * Measure warmed, optimized lint calls with an external user-space instruction counter.
 */
final class DataLintNestedInstructionParityTest extends TestCase
{
    public function testDataModeStaysWithinHandModeOnNestedBlocks(): void
    {
        $counter = getenv('CARVE_PERF_COUNTER');
        if ($counter === false || $counter === '') {
            self::markTestSkipped('Set CARVE_PERF_COUNTER to a user-space instruction counter');
        }
        $root = getenv('CARVE_PERF_ROOT') ?: dirname(__DIR__, 2);
        $hand = self::instructions($counter, $root, 'hand', 3)
            - self::instructions($counter, $root, 'hand', 1);
        $data = self::instructions($counter, $root, 'data', 3)
            - self::instructions($counter, $root, 'data', 1);

        self::assertLessThanOrEqual($hand, $data, 'Two additional data-mode calls must cost no more than hand mode');
    }

    private static function instructions(string $counter, string $root, string $mode, int $calls): int
    {
        $script = <<<'PHP'
            require $argv[1] . '/vendor/autoload.php';
            spl_autoload_register(static function (string $class) use ($argv): void {
                $prefix = 'MarkupCarve\\Carve\\';
                if (str_starts_with($class, $prefix)) {
                    $file = $argv[1] . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
                    if (is_file($file)) {
                        require $file;
                    }
                }
            }, true, true);
            $source = '';
            for ($i = 0; strlen($source) < 128 * 1024; $i++) {
                $source .= "> - item $i alpha delta eta kappa\n>\n>   > ::: note\n";
                $source .= ">   > para *strong $i* and _em_ text\n>   >\n";
                $source .= ">   > 1. inner alpha delta eta\n>   >\n";
                $source .= ">   >    - deeper `code $i`\n>   > :::\n> - sibling $i theta lambda xi\n\n";
            }
            putenv($argv[2] === 'data' ? 'CARVE_LINT_RULES=data' : 'CARVE_LINT_RULES');
            for ($i = 0; $i < (int)$argv[3]; $i++) {
                \MarkupCarve\Carve\Carve::lint($source);
            }
            PHP;
        $command = [$counter, PHP_BINARY, '-d', 'opcache.enable_cli=1', '-r', $script, $root, $mode, (string)$calls];
        $pipes = [];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), (string)$stdout . (string)$stderr);
        self::assertSame(
            1,
            preg_match('/instructions ([0-9]+) exit 0/', (string)$stdout . (string)$stderr, $matches),
            (string)$stdout . (string)$stderr,
        );

        return (int)$matches[1];
    }
}
