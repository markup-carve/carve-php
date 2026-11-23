<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Import;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve-php#5664: the writer's bookkeeping for one long paragraph stays within the default memory limit.
 */
final class LongParagraphImportMemoryTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function paragraphs(): array
    {
        return [
            'the ticket: 32,000 emphasis spans' => ['str_repeat("<em>a</em> ", 32000)', '1:63999:128000'],
            'strong spans between words' => ['str_repeat("<strong>ab</strong> cd ", 32000)', '1:64000:256000'],
        ];
    }

    #[DataProvider('paragraphs')]
    public function testOneLongParagraphImportsUnder128Megabytes(string $inline, string $expected): void
    {
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        $program = 'require ' . var_export($autoload, true) . '; '
            . '$result = \\MarkupCarve\\Carve\\Import\\HtmlImporter::import("<p>" . ' . $inline . ' . "</p>"); '
            . 'echo count($result->tree->children), ":", count($result->tree->children[0]->children), ":", strlen($result->source);';

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
