<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Import;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve-php#5627: an import of many small inline nodes stays inside the default memory limit.
 */
final class ImportMemoryTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string}>
     */
    public static function inputs(): array
    {
        return [
            'HTML links, 400 KB' => ['html', '<p><a href="u">a</a></p>' . "\n", '16000:paragraph:link'],
            'HTML emphasis in one paragraph, 144 KB' => ['html', '<em>a</em> ', '1:paragraph:emphasis'],
            'Markdown emphasis, 80 KB' => ['markdown', "*a*\n\n", '16000:paragraph:emphasis'],
        ];
    }

    #[DataProvider('inputs')]
    public function testManySmallInlineNodesImportUnder128Megabytes(string $from, string $part, string $expected): void
    {
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        $source = $part === '<em>a</em> '
            ? '"<p>" . str_repeat(' . var_export($part, true) . ', 13000) . "</p>"'
            : 'str_repeat(' . var_export($part, true) . ', 16000)';
        $program = 'require ' . var_export($autoload, true) . '; '
            . '$result = \\MarkupCarve\\Carve\\Carve::importDocument(' . $source . ', ["from" => ' . var_export($from, true) . ']); '
            . '$first = $result->tree->children[0]; '
            . 'echo count($result->tree->children), ":", $first->type, ":", $first->children[0]->type;';

        $process = proc_open([PHP_BINARY, '-d', 'memory_limit=128M', '-r', $program], [
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

        self::assertSame(0, proc_close($process), $stderr);
        self::assertSame($expected, $stdout);
    }
}
