<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * markup-carve/carve#4457: one HTML attribute name per element, compared ASCII
 * case-insensitively; a structural value wins over an authored copy.
 */
class AnHtmlAttributeIsWrittenOnceTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function provideShapes(): array
    {
        return [
            'ordered type' => ["{type=x}\na. alpha\n", "<ol type=\"a\">\n  <li>alpha</li>\n</ol>"],
            'ordered start' => ["{start=9}\n3. x\n", "<ol start=\"3\">\n  <li>x</li>\n</ol>"],
            'start without a structural one' => ["{start=9}\n1. x\n", "<ol start=\"9\">\n  <li>x</li>\n</ol>"],
            'image alt' => ["![a](/u.png){ALT=z}\n", '<img src="/u.png" alt="a">'],
            'case-only pair' => ["{K=1 k=2}\npara\n", '<p K="2">para</p>'],
            'class slot spellings' => ["[x]{.a CLASS=b ID=c #d}\n", '<p><span class="a b" ID="d">x</span></p>'],
            'line block class' => ["{CLASS=z}\n::: |\nverse\n:::\n", "<div CLASS=\"z line-block\">\n  <p>verse</p>\n</div>"],
            'admonition class' => ["{CLASS=v}\n::: note\nx\n:::\n", "<aside class=\"admonition note v\" aria-label=\"Note\">\n  <p>x</p>\n</aside>"],
            'typed div class' => ["{CLASS=v}\n::: calculation\nx\n:::\n", "<div class=\"calculation v\">\n  <p>x</p>\n</div>"],
            'math class' => ["\$`m`{CLASS=v}\n", '<p><span CLASS="math inline v" role="math">\\(m\\)</span></p>'],
            'abbr title' => ["[x]{abbr=\"a\" data-k=1 TITLE=v}\n", '<p><abbr title="v" data-k="1">x</abbr></p>'],
            'abbr key in another case' => ["[x]{abbr=v ABBR=w}\n", '<p><abbr title="v" ABBR="w">x</abbr></p>'],
            'fence header title' => ["{TITLE=z}\n```js \"t\"\nc\n```\n", "<pre TITLE=\"z\"><code class=\"language-js\">c\n</code></pre>"],
            'cell alignment' => ["|>{STYLE=v} a |\n", "<table>\n  <tbody>\n    <tr><td STYLE=\"v; text-align: right;\">a</td></tr>\n  </tbody>\n</table>"],
            'heading id' => ["{ID=v}\n# h\n\n</#v>\n", "<section id=\"v\">\n  <h1>h</h1>\n  <p><a href=\"#v\">h</a></p>\n</section>"],
        ];
    }

    #[DataProvider('provideShapes')]
    public function testTheAttributeIsWrittenOnce(string $source, string $expected): void
    {
        $this->assertSame($expected, trim((new CarveConverter())->convert($source)));
    }

    public function testAFootnoteReferenceTakesAnUppercaseId(): void
    {
        $html = (new CarveConverter())->convert("x[^1]{ID=v}\n\n[^1]: n\n");

        $this->assertStringContainsString('<a id="v" href="#fn1" role="doc-noteref">', $html);
        $this->assertStringContainsString('<a href="#v" role="doc-backlink"', $html);
    }
}
