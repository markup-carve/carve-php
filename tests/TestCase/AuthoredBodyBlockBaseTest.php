<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

final class AuthoredBodyBlockBaseTest extends TestCase
{
    public function testDefinitionAndFootnoteBodiesAcceptAuthoredBlockBases(): void
    {
        $shapes = [
            '# h',
            '> q',
            "```\ncode\n```",
            "```=html\n<b>x</b>\n```",
            "%%%\nhidden\n%%%",
            "::: note\nbody\n:::",
            "| A |\n| b |",
            ":: term\n:  def",
            "- one\n  - two",
            "{.c}\n# h",
        ];
        $converter = new CarveConverter();

        foreach ($shapes as $body) {
            $exactFootnote = "[^n]: intro\n\n" . $this->indent($body, 2) . "\n\nsee[^n]\n";
            $overFootnote = "[^n]: intro\n\n" . $this->indent($body, 3) . "\n\nsee[^n]\n";
            self::assertSame($converter->convert($exactFootnote), $converter->convert($overFootnote), $body);

            $exactDefinition = ":: term\n:  intro\n\n" . $this->indent($body, 3) . "\n";
            $overDefinition = ":: term\n:  intro\n\n" . $this->indent($body, 4) . "\n";
            self::assertSame($converter->convert($exactDefinition), $converter->convert($overDefinition), $body);
        }
    }

    public function testLinkDefinitionRegistersAtAnAuthoredFootnoteBase(): void
    {
        $html = (new CarveConverter())->convert("[^n]: note\n   [r]: /u\n\nsee[^n] and [t][r]\n");
        self::assertStringContainsString('<a href="/u">t</a>', $html);
        self::assertStringNotContainsString('[r]: /u', $html);
    }

    private function indent(string $source, int $width): string
    {
        $prefix = str_repeat(' ', $width);

        return implode("\n", array_map(static fn (string $line): string => $prefix . $line, explode("\n", $source)));
    }
}
