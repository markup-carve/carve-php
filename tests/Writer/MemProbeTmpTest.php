<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use PHPUnit\Framework\TestCase;

final class MemProbeTmpTest extends TestCase
{
    public function testProbe(): void
    {
        $repo = dirname(__DIR__, 2);
        exec('cd ' . escapeshellarg($repo) . ' && git fetch -q origin next2 && git worktree add -q /tmp/n2 FETCH_HEAD 2>&1', $o);
        $out = [];
        foreach (['head' => $repo, 'next2' => '/tmp/n2'] as $name => $root) {
            foreach ([0, 1024, 2048, 4096, 8192, 16384, 32768, 65536] as $pad) {
                $program = 'spl_autoload_register(function ($c) { $p = "MarkupCarve\\\\Carve\\\\"; if (str_starts_with($c, $p)) { $f = ' . var_export($root, true) . ' . "/src/" . str_replace("\\\\", "/", substr($c, strlen($p))) . ".php"; if (is_file($f)) { require $f; } } }, true, true); '
                    . 'require ' . var_export($repo . '/vendor/autoload.php', true) . '; '
                    . '$pad = str_repeat("x", ' . $pad . '); '
                    . '$line = rtrim(file_get_contents(' . var_export($repo . '/tests/fixtures/writer/', true) . ' . "superscript-quote-line.txt")); '
                    . '$source = "> " . implode("\n> ", array_fill(0, 47, $line)) . "\n"; '
                    . '\MarkupCarve\Carve\Carve::formatWithDiagnostics($source); '
                    . 'echo memory_get_peak_usage(true), ":", memory_get_peak_usage();';
                $r = [];
                exec(escapeshellarg(PHP_BINARY) . ' -d memory_limit=-1 -d opcache.enable_cli=0 -r ' . escapeshellarg($program) . ' 2>&1', $r);
                $out[] = $name . ' pad ' . $pad . ' ' . implode(' ', $r);
            }
        }
        $this->fail("PROBE\n" . implode("\n", $o) . "\n" . implode("\n", $out));
    }
}
