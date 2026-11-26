<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Cli;

use MarkupCarve\Carve\Cli\Command;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RenderContractFlagsTest extends TestCase
{
    /**
     * @return array<string, array{array<string>, string, int, string}>
     */
    public static function cases(): array
    {
        return [
            'roundtrip' => [['--roundtrip'], "a\n", 0, "<p>a</p>\n"],
            'static mode' => [['--mode', 'static'], "a\n", 0, "<p>a</p>\n"],
            'smart typography' => [['--smart-typography'], "a -- b\n", 0, "<p>a – b</p>\n"],
            'raw HTML' => [['--raw-html'], "```=html\n<b>x</b>\n```\n", 0, "<b>x</b>\n"],
            'sections' => [['--sections'], "a\n", 0, "<p>a</p>\n"],
            'source lines' => [['--source-lines'], "a\n", 0, '<p data-source-line="1">a</p>' . "\n"],
            'labels' => [['--labels', '{}'], "a\n", 0, "<p>a</p>\n"],
            'social' => [['--social', '{}'], "a\n", 0, "<p>a</p>\n"],
            'symbols' => [['--symbols', '{}'], "a\n", 0, "<p>a</p>\n"],
            'bad mode' => [['--mode', 'other'], "a\n", 2, ''],
        ];
    }

    #[DataProvider('cases')]
    public function testRenderFlags(array $flags, string $source, int $expectedStatus, string $expectedOutput): void
    {
        $in = fopen('php://memory', 'r+');
        $out = fopen('php://memory', 'r+');
        $err = fopen('php://memory', 'r+');
        self::assertIsResource($in);
        self::assertIsResource($out);
        self::assertIsResource($err);
        fwrite($in, $source);
        rewind($in);
        self::assertSame($expectedStatus, Command::main(['render', ...$flags, '-'], $in, $out, $err));
        rewind($out);
        self::assertSame($expectedOutput, stream_get_contents($out));
    }

    public function testHelpListsRenderOptions(): void
    {
        $in = fopen('php://memory', 'r+');
        $out = fopen('php://memory', 'r+');
        $err = fopen('php://memory', 'r+');
        self::assertIsResource($in);
        self::assertIsResource($out);
        self::assertIsResource($err);
        self::assertSame(0, Command::main(['--help'], $in, $out, $err));
        rewind($out);
        $help = (string)stream_get_contents($out);
        foreach (['render', 'import', 'lint', '--mode', '--roundtrip', '--smart-typography'] as $name) {
            self::assertStringContainsString($name, $help);
        }
    }
}
