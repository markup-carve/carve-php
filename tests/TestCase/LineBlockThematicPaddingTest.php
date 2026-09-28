<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

class LineBlockThematicPaddingTest extends TestCase
{
    public function testSmartDashNeedsNoPaddingOrEscape(): void
    {
        $formatted = CarveConverter::toCarve("::: |\n---\n");
        $this->assertSame("::: |\n---\n:::\n", $formatted);
        $this->assertSame("<div class=\"line-block\">\n  <p>—</p>\n</div>\n", (new CarveConverter())->convert($formatted));
    }

    public function testThematicLookingLinesPreserveLayoutAndSettle(): void
    {
        $bodies = [
            '---', '----', '-----', '------', '-------', '--------', '---------',
            "---\nnext", "first\n---", "first\n---\nlast", "---\n\n---",
            ' ---', "\t---", '---  ', '\\---', '---\\', '***', '___',
        ];
        $converter = new CarveConverter();
        foreach ($bodies as $body) {
            $block = "::: |\n" . $body . "\n:::\n";
            $lines = explode("\n", rtrim($block, "\n"));
            $quoted = implode("\n", array_map(static fn (string $line): string => '> ' . $line, $lines)) . "\n";
            $listed = "- item\n\n" . implode("\n", array_map(static fn (string $line): string => '  ' . $line, $lines)) . "\n";
            foreach ([$block, "::: |\n" . $body . "\n", $quoted, $listed] as $source) {
                $formatted = CarveConverter::toCarve($source);
                $this->assertSame($converter->convert($source), $converter->convert($formatted), $source);
                $this->assertSame($formatted, CarveConverter::toCarve($formatted), $source);
            }
        }
    }

    public function testOrdinaryParagraphGuardsAndThematicBreaksStayUnchanged(): void
    {
        foreach ([" ---\n", "---\n"] as $source) {
            $this->assertSame($source, CarveConverter::toCarve($source));
        }
    }
}
