<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StaticKindCheckTest extends TestCase
{
    /**
     * A brace glued before a node's opener gives no kind, so no kind round adds a backslash elsewhere (carve-js output).
     *
     * @return array<string, array{string, string}>
     */
    public static function gluedBraces(): array
    {
        return [
            'ordered item' => [
                "1. {^x^}{,x,}*a\\{\\{*x*`c`{=x=}/\n",
                "1. {^x^}{,x,}*a{\\{*x*`c`{=x=}/\n",
            ],
            'quote' => [
                "> {_x_}\\*\\{*x*#}[a]\n{%/}^ `c`{/{%%}/}{_x_}{~x~}\n",
                "> {_x_}*\\{*x*#}[a]\n> {% /}^ `c`{/{% %}/}{_x_}{~x~}\n",
            ],
            'two lines' => [
                "{{=x=}/}{^x^}#\\*~\\{{*x*}{/x/},\n{^{%x^{^{,\n",
                "{{=x=}/}{^x^}#*~\\{*x*/x/,\n{^{%x^{^{,\n",
            ],
            'table row' => [
                "| {+x+}*}{/[a](u)/y/ [a]^} {{*x*}{* |\n",
                "| {+x+}*}{/[a](u)/y/ [a]^} \\{*x*{* |\n",
            ],
        ];
    }

    #[DataProvider('gluedBraces')]
    public function testGluedBraceGivesNoKind(string $source, string $expected): void
    {
        $this->assertSame($expected, Carve::format($source));
    }

    public function testPairsPageKeepsItsGluedBraceEscapes(): void
    {
        $source = str_repeat('{*{  *}{{*x*} {/{ /}{{/x/} {*{ *} {{*{x*} a *b* ', 40) . "\n";
        $expected = rtrim(str_repeat('{*{  *}\\{*x* {/{ /}\\{/x/ {*{ *} \\{*{x* a *b* ', 40)) . "\n";

        $this->assertSame($expected, Carve::format($source));
    }

    public function testColonLinesPastTheDepthCapKeepTheirLineEscapes(): void
    {
        // carve-php#5624: the escaped opener lines past the block depth cap.
        $open = [];
        for ($k = 0; $k < 260; $k++) {
            $open[] = str_repeat(':', 263 - $k);
        }
        $source = implode("\n", [...$open, '+', '', ...array_reverse($open)]) . "\n";
        $lines = explode("\n", Carve::format($source));

        $this->assertSame('\\' . str_repeat(':', 63), $lines[200]);
        $this->assertSame('\\::::', $lines[259]);
        $this->assertSame('\\+', $lines[260]);
        $this->assertSame('', $lines[261]);
        $this->assertSame('\\::::', $lines[262]);
    }

    public function testColonLinesPastTheDepthCapBelow64KbKeepTheirLineEscapes(): void
    {
        // carve-php#5624: below 64 KB the escaped lines close their divs bare, so the form without escapes is not read.
        $open = [];
        for ($k = 0; $k < 210; $k++) {
            $open[] = str_repeat(':', 213 - $k);
        }
        $source = implode("\n", [...$open, '+', '', ...array_reverse($open)]) . "\n";
        $written = Carve::format($source);
        $lines = explode("\n", $written);

        $this->assertSame('\\' . str_repeat(':', 13), $lines[200]);
        $this->assertSame('\\::::', $lines[209]);
        $this->assertSame('\\+', $lines[210]);
        $this->assertSame('\\::::', $lines[212]);
        $this->assertSame($written, Carve::format($written));
    }

    public function testItemLinePastTheDepthCapTakesNoKindRoundBelow64Kb(): void
    {
        // carve-php#5624: as above 64 KB and in the spec model, the list marker the escaped lines leave in their
        // paragraph keeps no backslash, so the written page is a fixed point at every size.
        $open = [];
        for ($k = 0; $k < 210; $k++) {
            $open[] = str_repeat(':', 213 - $k);
        }
        $source = implode("\n", [...$open, '- item', '', ...array_reverse($open)]) . "\n";
        $written = Carve::format($source);

        $this->assertSame('- item', explode("\n", $written)[210]);
        $this->assertSame($written, Carve::format($written));
    }
}
