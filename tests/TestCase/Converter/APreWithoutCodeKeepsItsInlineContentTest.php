<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class APreWithoutCodeKeepsItsInlineContentTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function preProvider(): array
    {
        return [
            'cell text' => ['<table><tr><td><pre>f</pre></td></tr></table>', "| f |\n"],
            'cell emphasis' => ["<table><tr><td><pre>a <b>b</b>\nc</pre></td></tr></table>", "| a *b* c |\n"],
            'cell code' => ['<table><tr><td><pre><code>a b</code></pre></td></tr></table>', "| `a b` |\n"],
            'caption emphasis' => [
                "<figure><img src=\"/i\" alt=\"x\"><figcaption><pre>a <b>b</b>\nc</pre></figcaption></figure>",
                "![x](/i)\n^ a *b* c\n",
            ],
            'caption code' => [
                '<figure><img src="/i" alt="x"><figcaption><pre><code>a b</code></pre></figcaption></figure>',
                "![x](/i)\n^ `a b`\n",
            ],
            'image role' => [
                '<table><tr><td><pre role="img" class="mermaid" aria-label="Chart">a <b>b</b></pre></td></tr></table>',
                "| `a b` |\n",
            ],
            'caption highlight' => [
                '<figure><img src="/i" alt="x"><figcaption><pre><span class="k">word</span> x</pre>'
                    . '</figcaption></figure>',
                "![x](/i)\n^ [word]{.k} x\n",
            ],
            'block pre' => ["<pre>a <b>b</b>\nc</pre>", "```\na b\nc\n```\n"],
        ];
    }

    #[DataProvider('preProvider')]
    public function testInlineProjection(string $html, string $expected): void
    {
        $this->assertSame($expected, (new HtmlToCarve())->convert($html));
    }

    public function testHighlightAttributesSurvive(): void
    {
        $html = '<table><tr><td><div class="hl"><pre><span class="k">\forall</span> x</pre></div></td>'
            . '<td>b</td></tr></table>';
        $result = (new HtmlToCarve())->convertWithReport($html);
        $this->assertSame("| [\\\\forall]{.k} x | b |\n", $result->value);
        $this->assertSame([
            ['attribute-dropped', '/table[1]/tr[1]/td[1]/div[1]'],
            ['element-unwrapped', '/table[1]/tr[1]/td[1]/div[1]'],
            ['element-unwrapped', '/table[1]/tr[1]/td[1]/div[1]/pre[1]'],
        ], array_map(static fn ($row) => [$row->code, $row->path], $result->diagnostics));
    }
}
