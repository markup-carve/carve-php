<?php

declare(strict_types=1);

namespace Carve\Test\TestCase;

use PHPUnit\Framework\TestCase;

/**
 * Exercises the `bin/carve` CLI's output-format flags end to end by piping
 * Carve source on stdin and capturing stdout for each format.
 */
class CliTest extends TestCase
{
    /**
     * @var string
     */
    private const SRC = "# Hi\n\n_em_ *strong* `code`\n";

    /**
     * Run bin/carve with $args, feeding self::SRC on stdin; returns stdout.
     */
    private function runCli(array $args): string
    {
        return $this->runCliInput($args, self::SRC)['out'];
    }

    /**
     * Run bin/carve with $args and a given stdin; returns ['out', 'exit'].
     *
     * @return array{out: string, exit: int}
     */
    private function runCliInput(array $args, string $stdin): array
    {
        $bin = dirname(__DIR__, 2) . '/bin/carve';
        $cmd = array_merge([PHP_BINARY, $bin], $args);
        $process = proc_open(
            $cmd,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        $this->assertIsResource($process);
        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        return ['out' => (string)$out, 'exit' => $exit];
    }

    public function testRendersHtmlByDefault(): void
    {
        $out = $this->runCli([]);
        $this->assertStringContainsString('<h1>Hi</h1>', $out);
        $this->assertStringContainsString('<strong>strong</strong>', $out);
    }

    public function testRendersMarkdown(): void
    {
        $out = $this->runCli(['--markdown']);
        $this->assertStringContainsString('# Hi', $out);
        $this->assertStringContainsString('**strong**', $out);
        $this->assertStringNotContainsString('<h1>', $out);
    }

    public function testRendersPlainText(): void
    {
        $out = $this->runCli(['--plain']);
        $this->assertStringContainsString('Hi', $out);
        $this->assertStringNotContainsString('<h1>', $out);
        $this->assertStringNotContainsString('**', $out);
    }

    public function testRendersAnsi(): void
    {
        $out = $this->runCli(['--ansi']);
        // An SGR escape sequence was emitted.
        $this->assertStringContainsString("\033[", $out);
    }

    public function testStrictNonHtmlExitsOneNotFatal(): void
    {
        // An error-level warning under --strict --markdown must report and exit
        // 1, not throw an uncaught ParseException (PHP fatal, exit 255).
        $res = $this->runCliInput(['--markdown', '--strict'], "```\nunclosed\n");
        $this->assertSame(1, $res['exit']);
    }

    public function testStrictNonHtmlCleanInputExitsZero(): void
    {
        $res = $this->runCliInput(['--plain', '--strict'], "# ok\n");
        $this->assertSame(0, $res['exit']);
    }

    public function testHelpListsFormatFlags(): void
    {
        $out = $this->runCli(['--help']);
        $this->assertStringContainsString('--ansi', $out);
        $this->assertStringContainsString('--markdown', $out);
    }
}
