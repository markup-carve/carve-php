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

    public function testValidConstructsDoNotReportBoundaryLosses(): void
    {
        foreach (['[link](url)', '![alt](image.png)', '`[link]()`', '\\[link]()', "```c++ metadata\nx\n```", "```&#99;\nx\n```", "```\nx\n```"] as $source) {
            $result = (new MarkdownToCarve())->convertWithFidelityReport($source);
            self::assertSame([], array_values(array_filter($result->diagnostics, static fn ($row): bool => $row->code === 'structure-unspellable')), $source);
        }
    }
}
