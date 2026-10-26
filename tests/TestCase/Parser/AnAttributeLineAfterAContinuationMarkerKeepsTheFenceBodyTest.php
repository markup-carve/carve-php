<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `+`, an attribute line, then a fence: the fence is the one attached block
 * (carve#1238), so a body line shaped like the host's marker stays code.
 */
class AnAttributeLineAfterAContinuationMarkerKeepsTheFenceBodyTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function shapes(): array
    {
        $fence = static fn (string $body, string $attr = '{.x}', string $fence = '```'): string => "+\n$attr\n$fence\n$body\ncode\n$fence\n";

        return [
            'bullet host, dash body' => [
                "- a\n" . $fence('- x'),
                '<ul><li>a<pre class="x"><code>- x' . "\ncode\n" . '</code></pre></li></ul>',
            ],
            'bullet host, star body, tilde fence' => [
                "- a\n" . $fence('* x', '{.x}', '~~~'),
                '<ul><li>a<pre class="x"><code>* x' . "\ncode\n" . '</code></pre></li></ul>',
            ],
            'ordered host, number body' => [
                "1. a\n" . $fence('1. x'),
                '<ol><li>a<pre class="x"><code>1. x' . "\ncode\n" . '</code></pre></li></ol>',
            ],
            'auto host, dot body' => [
                ". a\n" . $fence('. x'),
                '<ol><li>a<pre class="x"><code>. x' . "\ncode\n" . '</code></pre></li></ol>',
            ],
            'task host, dash body' => [
                "- [ ] a\n" . $fence('- x'),
                'class="x"><code>- x' . "\ncode\n" . '</code></pre></li></ul>',
            ],
            'nested host, dash body' => [
                "- a\n  - b\n" . $fence('- x'),
                '<ul><li>a<ul><li>b</li></ul><pre class="x"><code>- x' . "\ncode\n" . '</code></pre></li></ul>',
            ],
            'quoted host, number body' => [
                "> 1. a\n> +\n> {.x}\n> ```\n> 1. x\n> code\n> ```\n",
                '<blockquote><ol><li>a<pre class="x"><code>1. x' . "\ncode\n" . '</code></pre></li></ol></blockquote>',
            ],
            'two attribute lines' => [
                "- a\n" . $fence('- x', "{.x}\n{.z}"),
                '<ul><li>a<pre class="x z"><code>- x' . "\ncode\n" . '</code></pre></li></ul>',
            ],
            'wrapped attribute' => [
                "- a\n" . $fence('- x', "{.x\n .z}"),
                '<ul><li>a<pre class="x z"><code>- x' . "\ncode\n" . '</code></pre></li></ul>',
            ],
            'id and class, sibling after' => [
                "- a\n" . $fence('- x', '{#t .x}') . "- c\n",
                '<ul><li>a<pre id="t" class="x"><code>- x' . "\ncode\n" . '</code></pre></li><li>c</li></ul>',
            ],
            'definition host, colon body' => [
                ":: t\n: d\n" . $fence(': x'),
                '<dl><dt>t</dt><dd><p>d</p><pre class="x"><code>: x' . "\ncode\n" . '</code></pre></dd></dl>',
            ],
            'definition host, double colon body' => [
                ":: t\n: d\n" . $fence(':: x'),
                '<dl><dt>t</dt><dd><p>d</p><pre class="x"><code>:: x' . "\ncode\n" . '</code></pre></dd></dl>',
            ],
        ];
    }

    #[DataProvider('shapes')]
    public function testTheFenceBodyStaysInsideTheAttachedBlock(string $carve, string $expected): void
    {
        $html = preg_replace(['/>\s+</', '/\s+(?=<(?:pre|ul|ol|dl)\b)/'], ['><', ''], trim(CarveConverter::create()->convert($carve)));

        $this->assertStringContainsString($expected, (string)$html);
    }

    /**
     * A quoted value cannot hold a newline, so this brace run is paragraph text
     * and the fence below it is not attached.
     */
    public function testABraceRunTheAttributeMatcherRefusesAttachesNoFence(): void
    {
        $html = CarveConverter::create()->convert("- a\n+\n{k=\"hello\nworld\"}\n```\n- x\ncode\n```\n");

        $this->assertStringContainsString("</ul>\n<pre><code>- x\ncode\n</code></pre>", $html);
    }
}
