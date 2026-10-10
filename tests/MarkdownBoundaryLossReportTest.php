<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\TestCase;

class MarkdownBoundaryLossReportTest extends TestCase
{
    public function testBoundaryLossesNameTheirSourceLines(): void
    {
        foreach (
            [
                ["``` f&ouml;&ouml;\nfoo\n```\n", 1],
                ["````;\n````\n", 1],
                ["[foo]: <>\n\n[foo]\n", 3],
                ["[link]()\n", 1],
                ["[link](<>)\n", 1],
                ["[]()\n", 1],
                ["[foo]()\n\n[foo]: /url1\n", 1],
                ["before\n\n> ```föö\n> x\n> ```\n", 3],
                ["before\n\n- ```föö\n  x\n  ```\n", 3],
                ["before\n\n![alt](<> \"title\")\n", 3],
                ["before\n\nfirst\n[link]()\n", 4],
            ] as [$source, $line]
        ) {
            $converter = new MarkdownToCarve();
            $result = $converter->convertWithFidelityReport($source);
            self::assertSame($converter->convert($source), $result->value);
            $losses = array_values(array_filter($result->diagnostics, static fn ($row): bool => $row->code === 'structure-unspellable'));
            self::assertCount(1, $losses, $source);
            self::assertSame('dropped', $losses[0]->fidelity);
            self::assertSame('exact', $losses[0]->confidence);
            self::assertSame('line:' . $line, $losses[0]->path, $source);
        }
    }

    public function testSourceLinesSurviveMovedAndRebasedBlocks(): void
    {
        foreach (
            [
                ["p\n\nq\n\n- a\n- b\n\n  > ```föö\n  > x\n  > ```\n", 8],
                ["x[^1]\n\n[^1]: [link]()\n\na\n\nb\n\nc", 3],
                ["---toml\na = 1\n---\nx[^1]\n\n[^1]: [link]()\n\nend", 6],
            ] as [$source, $line]
        ) {
            $losses = array_values(array_filter((new MarkdownToCarve())->convertWithFidelityReport($source)->diagnostics, static fn ($row): bool => $row->code === 'structure-unspellable'));
            self::assertCount(1, $losses, $source);
            self::assertSame('line:' . $line, $losses[0]->path);
        }
    }

    public function testInvalidUtf8LanguageDoesNotThrow(): void
    {
        self::assertSame("```\nx\n```\n", (new MarkdownToCarve())->convert("```\xff\nx\n```\n"));
    }

    public function testImageDescriptionsDoNotReportEmptyLinks(): void
    {
        foreach (['![a [b]() c](img.png)', "[foo]: <>\n\n![x [foo] y](i.png)"] as $source) {
            $losses = array_values(array_filter((new MarkdownToCarve())->convertWithFidelityReport($source)->diagnostics, static fn ($row): bool => $row->code === 'structure-unspellable'));
            self::assertSame([], $losses, $source);
        }
    }

    public function testFoldedSourceLinesRemainExact(): void
    {
        foreach (
            [
                ["a\nb [l]()\n===\n", 2],
                ["> a\n> b [l]()\n> ===\n", 2],
                ["- a\n  b [l]()\n  ===\n", 2],
                ["p\n\n``a\nb\nc`` z\n[l]()\n", 6],
                ["> | a |\n> |---|\n> | b |\n> | [l]() |\n", 4],
            ] as [$source, $line]
        ) {
            $losses = array_values(array_filter((new MarkdownToCarve())->convertWithFidelityReport($source)->diagnostics, static fn ($row): bool => $row->code === 'structure-unspellable'));
            self::assertCount(1, $losses, $source);
            self::assertSame('line:' . $line, $losses[0]->path, $source);
        }
    }

    public function testOpaqueDestinationsAndDefinitionTitlesAreNotLinks(): void
    {
        foreach (['<http://x/[l]()>', "[foo]: /u '[l]()'\n\n[foo]\n"] as $source) {
            $result = (new MarkdownToCarve())->convertWithFidelityReport($source);
            self::assertSame([], array_values(array_filter($result->diagnostics, static fn ($row): bool => $row->code === 'structure-unspellable')), $source);
            self::assertStringContainsString('[l]()', $result->value);
        }
    }

    public function testHeadingSourceMapsAreConsumedAndKeepProtectedSourceLengths(): void
    {
        foreach (
            [
                ["a\n[l]()\n===\n\nx\n\n# a [l]()\n", ['line:2', 'line:7']],
                ["a &lt;&lt;&lt;\n[l]()\n---\n", ['line:2']],
                ["<code>a</code>\n[l]()\n===\n", ['line:2']],
            ] as [$source, $paths]
        ) {
            $losses = array_values(array_filter((new MarkdownToCarve())->convertWithFidelityReport($source)->diagnostics, static fn ($row): bool => $row->code === 'structure-unspellable'));
            self::assertSame($paths, array_map(static fn ($row): ?string => $row->path, $losses), $source);
        }
    }

    public function testValidConstructsDoNotReportBoundaryLosses(): void
    {
        foreach (['[link](url)', '![alt](image.png)', '`[link]()`', '\\[link]()', "```c++ metadata\nx\n```", "```&#99;\nx\n```", "```\nx\n```"] as $source) {
            $result = (new MarkdownToCarve())->convertWithFidelityReport($source);
            self::assertSame([], array_values(array_filter($result->diagnostics, static fn ($row): bool => $row->code === 'structure-unspellable')), $source);
        }
    }
}
