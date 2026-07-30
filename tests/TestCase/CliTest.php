<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
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
     * Run bin/carve with $args and a given stdin; returns ['out', 'err', 'exit'].
     *
     * @return array{out: string, err: string, exit: int}
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
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        return ['out' => (string)$out, 'err' => (string)$err, 'exit' => $exit];
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

    public function testStampInfoReportsProvenanceAndExitsZero(): void
    {
        $res = $this->runCliInput(['--stamp-info'], "text\n\n%% carve-version: 0.0.9; generated-by: carve-js 0.0.9\n");

        $this->assertSame(0, $res['exit']);
        $this->assertStringContainsString('carve-version: 0.0.9', $res['out']);
        $this->assertStringContainsString('generated-by: carve-js 0.0.9', $res['out']);
    }

    public function testStampInfoSaysSoWhenThereIsNoMarker(): void
    {
        $res = $this->runCliInput(['--stamp-info'], "text\n");

        $this->assertSame(0, $res['exit']);
        $this->assertStringContainsString('unstamped', $res['out']);
    }

    public function testStampCheckFailsForAnOlderOrUnknownDocument(): void
    {
        // Usable as a CI gate over a directory of stored documents.
        $older = $this->runCliInput(['--stamp-check'], "text\n\n%% carve-version: 0.0.9; generated-by: x\n");
        $unstamped = $this->runCliInput(['--stamp-check'], "text\n");

        $this->assertSame(1, $older['exit']);
        $this->assertStringContainsString('[behavior]', $older['err']);
        $this->assertSame(1, $unstamped['exit']);
    }

    public function testStampCheckPassesForACurrentDocument(): void
    {
        $current = "text\n\n%% carve-version: "
            . CarveConverter::SPEC_VERSION
            . "; generated-by: x\n";

        $this->assertSame(0, $this->runCliInput(['--stamp-check'], $current)['exit']);
    }

    public function testHelpListsFormatFlags(): void
    {
        $out = $this->runCli(['--help']);
        $this->assertStringContainsString('--ansi', $out);
        $this->assertStringContainsString('--markdown', $out);
        $this->assertStringContainsString('--json', $out);
        $this->assertStringContainsString('--from-json', $out);
        $this->assertStringContainsString('--stamp-check', $out);
    }

    public function testJsonEmitsTheEncodedAst(): void
    {
        $out = $this->runCli(['--json']);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($out, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('document', $decoded['type']);
        $this->assertArrayNotHasKey('ast', $decoded, 'the reference root carries no envelope (PART 12 §3)');
        $this->assertSame('heading', $decoded['children'][0]['type']);
    }

    public function testFromJsonRendersTheSameHtmlAsTheSource(): void
    {
        // The point of the pair: a tree produced anywhere renders identically to
        // parsing the source it came from.
        $json = $this->runCli(['--json']);

        $viaJson = $this->runCliInput(['--from-json'], $json)['out'];
        $direct = $this->runCli([]);

        $this->assertSame($direct, $viaJson);
    }

    public function testFromJsonFeedsEveryOtherFormat(): void
    {
        $json = $this->runCli(['--json']);

        $this->assertSame(
            $this->runCli(['--markdown']),
            $this->runCliInput(['--from-json', '--markdown'], $json)['out'],
        );
    }

    public function testFromJsonReportsBadInputWithoutAStackTrace(): void
    {
        foreach (
            [
                '{"ast": 99, "type": "document"}' => 'AST encoding version',
                '{not json' => 'Syntax error',
                '{"type": "document", "children": [{"type": "nope"}]}' => 'Unknown node type',
            ] as $input => $expected
        ) {
            $res = $this->runCliInput(['--from-json'], (string)$input);

            $this->assertSame(1, $res['exit'], 'malformed input must exit 1, not fatal');
            $this->assertStringContainsString($expected, $res['err']);
            $this->assertStringNotContainsString('Stack trace', $res['err']);
        }
    }
}
