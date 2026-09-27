<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

class NestedFenceBlankRunsTest extends TestCase
{
    public function testTrailingBlanksRemainFencePayload(): void
    {
        $converter = CarveConverter::create();
        foreach (['```', '~~~'] as $fence) {
            foreach ([1, 2, 3] as $blanks) {
                foreach ([0, 1, 2, 3] as $depth) {
                    foreach (['out', '# Heading', '- next', ''] as $tail) {
                        $source = "- head\n\n   " . $fence . "\n   body\n" . str_repeat("\n", $blanks) . $tail . "\n";
                        for ($i = 0; $i < $depth; $i++) {
                            $lines = explode("\n", $source);
                            array_pop($lines);
                            $source = "- parent\n" . implode("\n", array_map(static fn ($line) => '  ' . $line, $lines)) . "\n";
                        }
                        $expected = "body\n" . str_repeat("\n", $blanks + ($tail === '' ? 1 : 0)) . '</code></pre>';
                        $this->assertStringContainsString($expected, $converter->convert($source), $source);
                    }
                }
            }
        }
    }

    public function testMarkerLeadChildKeepsTrailingBlanks(): void
    {
        $source = "- - head\n\n     ```\n     body\n\n\nout\n";
        $html = CarveConverter::create()->convert($source);
        $this->assertStringContainsString("body\n\n\n</code></pre>", $html);
        $this->assertStringEndsWith('<p>out</p>', trim($html));
    }

    public function testClosedFenceDoesNotAcquireFollowingSpacing(): void
    {
        $source = "- parent\n  - head\n\n     ```\n     body\n     ```\n\n\n  out\n";
        $this->assertStringContainsString("<pre><code>body\n</code></pre>", CarveConverter::create()->convert($source));
    }
}
