<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use PHPUnit\Framework\TestCase;

final class MemProbeTmpTest extends TestCase
{
    public function testProbe(): void
    {
        $repo = dirname(__DIR__, 2);
        $len = strlen($repo);
        $o = [];
        exec('cd ' . escapeshellarg($repo) . ' && git fetch -q origin next2 2>&1', $o);
        $variants = [
            'R0' => null,
            'A0' => 'next2',
            'B0' => 'head',
            'C0' => 'inline',
            'D0' => 'planner-only',
            'E0' => 'text-only',
        ];
        $out = [];
        foreach ($variants as $suffix => $kind) {
            $root = $kind === null ? $repo : substr($repo, 0, $len - 2) . $suffix;
            if ($kind !== null) {
                exec('cp -r ' . escapeshellarg($repo) . ' ' . escapeshellarg($root) . ' 2>&1', $o);
                $text = $root . '/src/Writer/Text.php';
                if ($kind === 'next2' || $kind === 'planner-only') {
                    exec('cd ' . escapeshellarg($root) . ' && git show FETCH_HEAD:src/Writer/Text.php > src/Writer/Text.php 2>&1', $o);
                }
                if ($kind === 'next2' || $kind === 'text-only') {
                    exec('cd ' . escapeshellarg($root) . ' && git show FETCH_HEAD:src/Writer/RepairPlanner.php > src/Writer/RepairPlanner.php 2>&1', $o);
                }
                if ($kind === 'inline') {
                    $s = (string)file_get_contents($text);
                    $const = "    /**\n     * The sealed nodes whose text can hold a `]` that pairs with a bracket before them (`BracketKind`).\n"
                        . "     *\n     * @var array<string, true>\n     */\n"
                        . "    private const BRACKETED = ['link' => true, 'image' => true, 'inline_footnote' => true];\n\n";
                    $s = str_replace($const, '', $s);
                    $s = str_replace('isset(self::BRACKETED[$type])', '($type === \'link\' || $type === \'image\' || $type === \'inline_footnote\')', $s);
                    file_put_contents($text, $s);
                }
            }
            $program = 'require ' . var_export($root . '/vendor/autoload.php', true) . '; '
                . '$line = rtrim(file_get_contents(' . var_export($root . '/tests/fixtures/writer/', true) . ' . "superscript-quote-line.txt")); '
                . '$source = "> " . implode("\n> ", array_fill(0, 47, $line)) . "\n"; '
                . '\MarkupCarve\Carve\Carve::formatWithDiagnostics($source); '
                . 'echo memory_get_peak_usage(true), ":", memory_get_peak_usage();';
            $r = [];
            exec(escapeshellarg(PHP_BINARY) . ' -d memory_limit=-1 -d opcache.enable_cli=0 -r ' . escapeshellarg($program) . ' 2>&1', $r);
            $out[] = 'VAR ' . $suffix . ' ' . ($kind ?? 'repo') . ' ' . implode(' ', $r);
        }
        $this->fail("PROBE\n" . implode("\n", $o) . "\n" . implode("\n", $out));
    }
}
