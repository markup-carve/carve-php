<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Inline;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Inline\InlineContext;
use MarkupCarve\Carve\Inline\InlineRunner;
use MarkupCarve\Carve\Test\Support\Linearity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Plain content over several lines skips the scanner; the nodes and positions stay those the scanner makes.
 */
final class PlainLinesTest extends TestCase
{
    /**
     * @return array<string, array{string, string, array<string>}>
     */
    public static function shapes(): array
    {
        return [
            'trailing and leading spaces' => [
                "ab  \n  cd\t\nef\n",
                '/children/0',
                ['text ab 0-2', 'soft_break 2-7', 'text cd 7-9', 'soft_break 9-11', 'text ef 11-13'],
            ],
            'hard breaks div' => [
                "::: \\\nab\n cd\n:::\n",
                '/children/0/children/0',
                ['text ab 6-8', 'hard_break 8-10', 'text cd 10-12'],
            ],
            'quote with a lazy line' => [
                "> ab\n> cd\nef\n",
                '/children/0/children/0',
                ['text ab 2-4', 'soft_break 4-7', 'text cd 7-9', 'soft_break 9-10', 'text ef 10-12'],
            ],
        ];
    }

    /**
     * @param string $source
     * @param string $path
     * @param array<string> $expected
     */
    #[DataProvider('shapes')]
    public function testNodesMatchTheScanner(string $source, string $path, array $expected): void
    {
        $node = Carve::parse($source);
        foreach (array_slice(explode('/', $path), 1) as $step) {
            if ($step !== 'children') {
                $children = $node->children ?? [];
                $node = $children[(int)$step];
            }
        }
        $actual = [];
        foreach ($node->children ?? [] as $n) {
            $value = $n->f['value'] ?? null;
            $actual[] = trim($n->type . ' ' . (is_string($value) ? $value : '')) . ' ' . $n->start . '-' . $n->end;
        }

        $this->assertSame($expected, $actual);
    }

    /**
     * The scanner path costs about 90 times the one-line plain text path on the same bytes; plain lines cost about 23.
     */
    public function testPlainLinesSkipTheScanner(): void
    {
        $line = 'lorem ipsum dolor sit amet consectetur';
        $lines = 2000;
        $wrapped = str_repeat($line . "\n", $lines);
        $spans = [];
        for ($k = 0; $k < $lines; $k++) {
            $spans[] = $k * (strlen($line) + 1);
            $spans[] = $k * (strlen($line) + 1) + strlen($line);
        }
        $flat = str_repeat($line . ' ', $lines);
        $ctx = new InlineContext();
        $excess = Linearity::excess(
            static fn () => InlineRunner::parse($flat, [0, strlen($flat) - 1], $ctx),
            static fn () => InlineRunner::parse($wrapped, $spans, $ctx),
            1,
            5,
        );

        $this->assertLessThanOrEqual(45.0, $excess);
    }
}
