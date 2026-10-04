<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\Parser\BlockParser;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use PHPUnit\Framework\TestCase;

class SharedColonBoundaryTest extends TestCase
{
    public function testFigureHardbreakAndTransformedFramesMatchFreshCollection(): void
    {
        foreach (["::: figure\n", "::: \\\n"] as $opener) {
            foreach ([3, 40, 201] as $depth) {
                $body = str_repeat($opener, $depth) . "payload\n{.dangling}\n" . str_repeat(":::\n", $depth);
                foreach ([$body, "- lead\n\n  " . str_replace("\n", "\n  ", $body), "[^f]:\n  " . str_replace("\n", "\n  ", $body) . "\n\nref[^f]\n"] as $source) {
                    foreach ([false, true] as $positions) {
                        $reference = new class (trackSourceLines: true, trackPositions: $positions) extends BlockParser {
                        };
                        $candidate = new BlockParser(trackSourceLines: true, trackPositions: $positions);
                        self::assertEquals($reference->parse($source), $candidate->parse($source));
                    }
                }
            }
        }
    }

    public function testSharedBoundariesMatchFreshCollection(): void
    {
        $bodies = [
            "payload\n",
            "``` =html\n<pre>\n::: hidden\n</pre>\n```\n",
            "```\n::: hidden\n```\n",
            "%% comment\n::: hidden\n%%\n",
            "``` =html\n::: unclosed\n",
            "[ref]: /target\n\n[link][ref]\n",
            "+\n{.marked}\n# Heading\n",
        ];
        foreach ([false, true] as $positions) {
            foreach (["\n", "\r\n", "\r"] as $ending) {
                foreach ($bodies as $body) {
                    foreach ([true, false] as $closed) {
                        $source = "\u{feff}" . str_repeat(":::: box\n::: >\n", 12)
                            . $body . ($closed ? str_repeat(":::\n::::\n", 12) : '');
                        $source = str_replace("\n", $ending, $source);
                        $reference = new class (trackPositions: $positions) extends BlockParser {
                        };
                        $candidate = new BlockParser(trackPositions: $positions);
                        $expected = $reference->parse($source);
                        $actual = $candidate->parse($source);
                        self::assertEquals($expected, $actual);
                        self::assertSame((new HtmlRenderer())->render($expected), (new HtmlRenderer())->render($actual));
                        self::assertSame((new HtmlRenderer())->render($expected), (new HtmlRenderer())->render($candidate->parse($source)));
                    }
                }
            }
        }
    }
}
