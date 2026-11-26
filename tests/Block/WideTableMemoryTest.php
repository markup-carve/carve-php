<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Block;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Node;
use PHPUnit\Framework\TestCase;

final class WideTableMemoryTest extends TestCase
{
    public function testPublicParseKeepsEmptyCellsIndependent(): void
    {
        $first = self::emptyParagraphs(Carve::parse("|||\n"));
        $again = self::emptyParagraphs(Carve::parse("|||\n"));
        self::assertCount(2, $first);
        self::assertCount(2, $again);
        self::assertNotSame($first[0] ?? null, $first[1] ?? null);
        self::assertNotSame($first[0] ?? null, $again[0] ?? null);
    }

    /**
     * @return array<\MarkupCarve\Carve\Node>
     */
    private static function emptyParagraphs(Node $root): array
    {
        $found = [];
        $stack = [$root];
        while ($stack !== []) {
            $node = array_pop($stack);
            if ($node->type === 'cell_paragraph') {
                $found[] = $node;
            }
            foreach ($node->children ?? [] as $child) {
                $stack[] = $child;
            }
        }

        return $found;
    }

    public function testWideTableLintAndFormatFitDefaultMemoryLimit(): void
    {
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        foreach (['lint' => 'count', 'format' => 'strlen'] as $method => $measure) {
            $program = 'require ' . var_export($autoload, true) . '; '
                . '$source = str_repeat("|", 65536); '
                . 'echo ' . $measure . '(\\MarkupCarve\\Carve\\Carve::' . $method . '($source));';
            [$status, $stdout, $stderr] = self::runWithLimit($program, '128M');
            self::assertSame(0, $status, $method . ': ' . $stderr);
            self::assertSame($method === 'lint' ? '0' : '131072', $stdout);
        }
    }

    public function testWideTableRenderFitsDefaultMemoryLimit(): void
    {
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        $program = 'require ' . var_export($autoload, true) . '; '
            . '$source = str_repeat("|", 65536); '
            . '$html = \\MarkupCarve\\Carve\\Carve::render($source); '
            . 'echo strlen($html);';
        [$status, $stdout, $stderr] = self::runWithLimit($program, '128M');
        self::assertSame(0, $status, $stderr);
        self::assertSame('589866', $stdout);
    }

    /**
     * @return array{0: int, 1: string, 2: string}
     */
    private static function runWithLimit(string $program, string $limit): array
    {
        $process = proc_open([PHP_BINARY, '-d', 'memory_limit=' . $limit, '-r', $program], [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }
}
