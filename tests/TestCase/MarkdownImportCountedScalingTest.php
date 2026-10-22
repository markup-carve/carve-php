<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\Converter\Markdown\ImportWork;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MarkdownImportCountedScalingTest extends TestCase
{
    public static function families(): array
    {
        return [
            'quote' => ["> # Head\n> paragraph\n>\n\n", 11999, 47999],
            'list' => ["-    one\n\n      two\n\n", 6999, 27999],
            'fence' => ["> ~~~\n> code\n> ~~~\n\n", 9999, 39999],
            'heading' => ["# Head\n\ntext\n\n", 6999, 27999],
            'held quote' => ["> 1. > nested\n>    ===\n\n\n", 13499, 53999],
            'mixed' => ['', 11327, 45327],
        ];
    }

    private static function source(string $block, int $lines): string
    {
        if ($block !== '') {
            return str_repeat($block, intdiv($lines, 4));
        }
        $mix = [
            "> # Head\n> paragraph\n>\n\n",
            "> quoted\n>\nnext\n\n",
            "-    one\n\n      two\n\n",
            "> ~~~\n> code\n> ~~~\n\n",
            "> 1. > nested\ncontinued\n\n\n",
            "#\n\ntext\n\n",
        ];
        $parts = [];
        for ($i = 0; $i < intdiv($lines, 4); $i++) {
            $parts[] = $mix[$i % count($mix)];
        }

        return implode('', $parts);
    }

    private static function countWork(string $source): array
    {
        ImportWork::$on = true;
        try {
            $output = (new MarkdownToCarve())->convert($source);

            return [ImportWork::counts(), strlen($output)];
        } finally {
            ImportWork::$on = false;
        }
    }

    #[DataProvider('families')]
    public function testFourTimesAsManyLinesTakeAtMostFiveTimesTheWork(string $block, int $smallBytes, int $largeBytes): void
    {
        [$small, $smallOutput] = self::countWork(self::source($block, 2000));
        [$large, $largeOutput] = self::countWork(self::source($block, 8000));
        self::assertSame($smallBytes, $smallOutput);
        self::assertSame($largeBytes, $largeOutput);
        foreach ($small as $name => $work) {
            if ($work === 0) {
                self::assertSame(0, $large[$name], $name);

                continue;
            }
            self::assertLessThanOrEqual(5 * $work, $large[$name], $name);
        }
    }

    public function testEveryCounterHasALiveInput(): void
    {
        $totals = array_fill_keys(array_keys(ImportWork::counts()), 0);
        foreach (["a *b*\nc\n", "1.  one\n    two\n", "> 1. > nested\n>    ===\n\n\n", self::source('', 2000)] as $source) {
            [$counts] = self::countWork($source);
            foreach ($counts as $name => $work) {
                $totals[$name] += $work;
            }
        }
        foreach ($totals as $name => $work) {
            self::assertGreaterThan(0, $work, $name);
        }
    }

    public function testNestedFootnoteWorkAccumulatesAndNextConversionResets(): void
    {
        [$nested] = self::countWork("before\n\n[^n]: one\n\n    two\n");
        self::assertSame(4, $nested['inlineLines']);
        [$next] = self::countWork("x\n");
        self::assertSame(1, $next['inlineLines']);
    }
}
