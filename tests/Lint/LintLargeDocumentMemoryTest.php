<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Lint;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LintLargeDocumentMemoryTest extends TestCase
{
    /**
     * @var string
     */
    private const EMPTY_GROUPS = ":::\n%% hidden\n%% hidden too\n:::\n\n::: custom\n%% hidden\n:::\n\n"
        . "::: note\n%% hidden\n:::\n\n::: >\n%% hidden\n:::\n\n::: |\n%% hidden\n:::\n\n"
        . "::: \\\n%% hidden\n:::\n\n::: figure\n%% hidden\n:::\n";

    /**
     * @var string
     */
    private const ALIGNED_TABLE = "|={>^} Name |= Value |\n|{?v} Bottom |{?~} Middle |\n|{?^} Top | plain |\n"
        . "|? lone |v? reversed |\n|?< wrong |^< axes |\n";

    /**
     * carve#6493: 128 KB of a corpus table or of empty colon groups linted past PHP's default memory limit.
     *
     * @return array<string, array{string}>
     */
    public static function largeDocuments(): array
    {
        return [
            'aligned table cells' => [self::ALIGNED_TABLE],
            'empty colon groups' => [self::EMPTY_GROUPS],
        ];
    }

    #[DataProvider('largeDocuments')]
    public function testLargeDocumentLintsUnderDefaultMemoryLimit(string $unit): void
    {
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        $program = 'require ' . var_export($autoload, true) . '; '
            . '$unit = ' . var_export($unit, true) . '; '
            . '$source = $unit; while (strlen($source) + strlen($unit) + 1 <= 131072) { $source .= "\n" . $unit; } '
            . 'echo count(\MarkupCarve\Carve\Carve::lint($source));';
        $process = proc_open(
            [PHP_BINARY, '-d', 'memory_limit=128M', '-r', $program],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        $this->assertIsResource($process);
        $stdout = (string)stream_get_contents($pipes[1]);
        $stderr = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $this->assertSame(0, proc_close($process), $stderr);
        $this->assertMatchesRegularExpression('/^\d+$/', $stdout);
    }
}
