<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FormatMemoryTest extends TestCase
{
    public function testLongParagraphWithNestedLinksFitsDefaultMemoryLimit(): void
    {
        $program = '$part = "^*[*[[*^*[*[[*^*[*[[*^*[*x](u)^]]](u)^]]](u)^]]](u)"; '
            . '$source = implode(" ", array_fill(0, (int)ceil(131072 / 53), $part)) . "\n"; ';

        self::assertSame('128648:0', self::formatIn128M($program));
    }

    /**
     * carve#6413: the writer inputs of carve-rs#4538 at 128 KB.
     *
     * @return array<string, array{string, string}>
     */
    public static function superscriptInputs(): array
    {
        $fixtures = var_export(dirname(__DIR__) . '/fixtures/writer/', true);

        return [
            'paragraphs of the unit' => [
                '$unit = rtrim(file_get_contents(' . $fixtures . ' . "superscript-unit.txt")); '
                . '$source = implode("\n\n", array_fill(0, 780, $unit)) . "\n"; ',
                '131039:0',
            ],
            'one quoted paragraph' => [
                '$line = rtrim(file_get_contents(' . $fixtures . ' . "superscript-quote-line.txt")); '
                . '$source = "> " . implode("\n> ", array_fill(0, 47, $line)) . "\n"; ',
                '131459:0',
            ],
        ];
    }

    #[DataProvider('superscriptInputs')]
    public function testSuperscriptInputsFitDefaultMemoryLimit(string $program, string $expected): void
    {
        self::assertSame($expected, self::formatIn128M($program));
    }

    private static function formatIn128M(string $build): string
    {
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        $program = 'require ' . var_export($autoload, true) . '; ' . $build
            . '$result = \\MarkupCarve\\Carve\\Carve::formatWithDiagnostics($source); '
            . 'echo strlen($source), ":", count($result->diagnostics);';

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

        return (string)$stdout;
    }
}
