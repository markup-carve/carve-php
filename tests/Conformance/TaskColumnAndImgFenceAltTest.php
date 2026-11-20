<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Conformance;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\TestCase;

/**
 * The 20 November rulings: a task mark does not move the content column (C2-B-027, C2-L-051), and only an authored
 * `alt` gives the `imgFence` image its alt text (C2-X-059).
 */
final class TaskColumnAndImgFenceAltTest extends TestCase
{
    public function testTaskMarkLeavesTheBulletContentColumn(): void
    {
        foreach ([' ', 'x', 'X', '-', '>', '?'] as $mark) {
            foreach (['-', '*', '1.', 'a)'] as $marker) {
                $pad = str_repeat(' ', strlen($marker) + 1);
                $input = $marker . ' [' . $mark . "] Another pending item\n\n" . $pad . "With additional content.\n";
                $codes = array_map(static fn ($d): string => $d->code, Carve::lint($input));
                $this->assertNotContains('list-item-body-below-content-column', $codes, json_encode($input) ?: '');
                $this->assertSame(str_replace('[X]', '[x]', $input), Carve::format($input), json_encode($input) ?: '');
            }
        }
        $codes = array_map(static fn ($d): string => $d->code, Carve::lint("- a\n\n b\n"));
        $this->assertContains('list-item-body-below-content-column', $codes);
    }

    /**
     * C2-B-046, C2-W-088: text that would read as a task box behind an item marker keeps its backslash.
     */
    public function testBoxShapedTextBehindAnItemMarker(): void
    {
        $cases = [
            "- \\[x] a\n" => "- \\[x] a\n",
            "-  [x] Item\n" => "- \\[x] Item\n",
            "1.  [ ] Item\n" => "1. \\[ ] Item\n",
            "- - \\[x] a\n" => "- - \\[x] a\n",
            "- a\n\n  \\[x] b\n" => "- a\n\n  [x] b\n",
            "- > \\[x] a\n" => "- > [x] a\n",
            "- [x] \\[x] a\n" => "- [x] [x] a\n",
            "- \\[q] a\n" => "- [q] a\n",
        ];
        foreach ($cases as $input => $expected) {
            $this->assertSame($expected, Carve::format($input), json_encode($input) ?: '');
        }
    }

    public function testOnlyAnAuthoredAltIsTheImageAlt(): void
    {
        $options = ['extensions' => ['carve.imgFence.img'], 'renderers' => ['carve.imgFence.img' => 'standard']];
        $body = "```img\n<svg><title>T</title><rect/></svg>\n```\n";
        $this->assertStringContainsString('" alt="">', Carve::toHtml($body, $options));
        $html = Carve::toHtml('{ALT="a&quot;b" alt="Box" src=x title=t data-k=v}' . "\n" . $body, $options);
        $this->assertStringContainsString('" alt="Box" title="t" data-k="v">', $html);
        $this->assertStringNotContainsString('src="x"', $html);
    }
}
