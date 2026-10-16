<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\Parser\BlockParser;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ANoteMarkerLineBlockAnswersAsItsHostTest extends TestCase
{
    #[DataProvider('cases')]
    public function testNoteBody(string $source, string $expected): void
    {
        $html = (new HtmlRenderer())->render((new BlockParser())->parse($source));
        self::assertSame(preg_replace('/\s+/', '', $expected), preg_replace('/\s+/', '', $html));
    }

    public static function cases(): iterable
    {
        yield '[^c]: para / CMT / ``` 1' => [
            '[^c]: para
%% c
```
', '',
        ];

        yield '[^c]: para / .CMT / ``` 2' => [
            '[^c]: para
 %% c
```
', '',
        ];

        yield 'USE / [^c]: ::: note / .......ATTR 3' => [
            '[r][] [^p] [^c]
[^c]: ::: note
       {#x}
', '<p>[r][] [^p] <a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a></p>
<section role="doc-endnotes" aria-label="Footnotes">
<hr>
<ol>
<li id="fn1">
<aside class="admonition note" aria-label="Note">
</aside>
<p><a href="#fnref1" role="doc-backlink" aria-label="Back to reference">↩</a></p>
</li>
</ol>
</section>',
        ];

        yield 'USE / [^c]: ::: note / ..........HR 4' => [
            '[r][] [^p] [^c]
[^c]: ::: note
          ---
', '<p>[r][] [^p] <a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a></p>
<section role="doc-endnotes" aria-label="Footnotes">
<hr>
<ol>
<li id="fn1">
<aside class="admonition note" aria-label="Note">
<hr>
</aside>
<p><a href="#fnref1" role="doc-backlink" aria-label="Back to reference">↩</a></p>
</li>
</ol>
</section>',
        ];

        yield 'USE / [^c]: :: t / ............NDEF 5' => [
            '[r][] [^p] [^c]
[^c]: :: t
            [^p]: n
', '<p>[r][] [^p] <a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a></p>
<section role="doc-endnotes" aria-label="Footnotes">
<hr>
<ol>
<li id="fn1">
<dl>
<dt>t
[^p]: n</dt>
</dl>
<p><a href="#fnref1" role="doc-backlink" aria-label="Back to reference">↩</a></p>
</li>
</ol>
</section>',
        ];

        yield 'USE / [^c]: :: t / ............LDEF 6' => [
            '[r][] [^p] [^c]
[^c]: :: t
            [r]: /u
', '<p>[r][] [^p] <a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a></p>
<section role="doc-endnotes" aria-label="Footnotes">
<hr>
<ol>
<li id="fn1">
<dl>
<dt>t
[r]: /u</dt>
</dl>
<p><a href="#fnref1" role="doc-backlink" aria-label="Back to reference">↩</a></p>
</li>
</ol>
</section>',
        ];

        yield 'USE / [^c]: :: t / .CMT 7' => [
            '[r][] [^p] [^c]
[^c]: :: t
 %% c
', '<p>[r][] [^p] <a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a></p>
<section role="doc-endnotes" aria-label="Footnotes">
<hr>
<ol>
<li id="fn1">
<dl>
<dt>t
</dt>
</dl>
<p><a href="#fnref1" role="doc-backlink" aria-label="Back to reference">↩</a></p>
</li>
</ol>
</section>',
        ];

        yield 'USE / [^c]: ::: note / ......ATTR 8' => [
            '[r][] [^p] [^c]
[^c]: ::: note
      {#x}
', '<p>[r][] [^p] <a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a></p>
<section role="doc-endnotes" aria-label="Footnotes">
<hr>
<ol>
<li id="fn1">
<aside class="admonition note" aria-label="Note">
</aside>
<p><a href="#fnref1" role="doc-backlink" aria-label="Back to reference">↩</a></p>
</li>
</ol>
</section>',
        ];

        yield 'USE / [^c]: :: t / ...LDEF 9' => [
            '[r][] [^p] [^c]
[^c]: :: t
   [r]: /u
', '<p>[r][] [^p] <a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a></p>
<section role="doc-endnotes" aria-label="Footnotes">
<hr>
<ol>
<li id="fn1">
<dl>
<dt>t
[r]: /u</dt>
</dl>
<p><a href="#fnref1" role="doc-backlink" aria-label="Back to reference">↩</a></p>
</li>
</ol>
</section>',
        ];

        yield 'USE / [^c]: ::: note / .....ROW 10' => [
            '[r][] [^p] [^c]
[^c]: ::: note
     | a |
', '<p>[r][] [^p] <a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a></p>
<section role="doc-endnotes" aria-label="Footnotes">
<hr>
<ol>
<li id="fn1">
<aside class="admonition note" aria-label="Note">
<table>
<tbody>
<tr><td>a</td></tr>
</tbody>
</table>
</aside>
<p><a href="#fnref1" role="doc-backlink" aria-label="Back to reference">↩</a></p>
</li>
</ol>
</section>',
        ];

        yield 'USE / [^c]: :: t / ...NDEF 11' => [
            '[r][] [^p] [^c]
[^c]: :: t
   [^p]: n
', '<p>[r][] [^p] <a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a></p>
<section role="doc-endnotes" aria-label="Footnotes">
<hr>
<ol>
<li id="fn1">
<dl>
<dt>t
[^p]: n</dt>
</dl>
<p><a href="#fnref1" role="doc-backlink" aria-label="Back to reference">↩</a></p>
</li>
</ol>
</section>',
        ];

        yield 'USE / [^c]: ::: note / ..........ATTR 12' => [
            '[r][] [^p] [^c]
[^c]: ::: note
          {#x}
', '<p>[r][] [^p] <a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a></p>
<section role="doc-endnotes" aria-label="Footnotes">
<hr>
<ol>
<li id="fn1">
<aside class="admonition note" aria-label="Note">
</aside>
<p><a href="#fnref1" role="doc-backlink" aria-label="Back to reference">↩</a></p>
</li>
</ol>
</section>',
        ];

        yield 'USE / [^c]: ::: note / ...HEAD 13' => [
            '[r][] [^p] [^c]
[^c]: ::: note
   # H
', '<p>[r][] [^p] <a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a></p>
<section role="doc-endnotes" aria-label="Footnotes">
<hr>
<ol>
<li id="fn1">
<aside class="admonition note" aria-label="Note">
<h1 id="H">H</h1>
</aside>
<p><a href="#fnref1" role="doc-backlink" aria-label="Back to reference">↩</a></p>
</li>
</ol>
</section>',
        ];

        yield '[^c]: ::: note / ......para / CMT / ``` 14' => [
            '[^c]: ::: note
      para
%% c
```
', '',
        ];

        yield 'USE / [^c]: :: t / .......NDEF 15' => [
            '[r][] [^p] [^c]
[^c]: :: t
       [^p]: n
', '<p>[r][] [^p] <a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a></p>
<section role="doc-endnotes" aria-label="Footnotes">
<hr>
<ol>
<li id="fn1">
<dl>
<dt>t
[^p]: n</dt>
</dl>
<p><a href="#fnref1" role="doc-backlink" aria-label="Back to reference">↩</a></p>
</li>
</ol>
</section>',
        ];

        yield 'USE / [^c]: ::: note / .....ATTR 16' => [
            '[r][] [^p] [^c]
[^c]: ::: note
     {#x}
', '<p>[r][] [^p] <a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a></p>
<section role="doc-endnotes" aria-label="Footnotes">
<hr>
<ol>
<li id="fn1">
<aside class="admonition note" aria-label="Note">
</aside>
<p><a href="#fnref1" role="doc-backlink" aria-label="Back to reference">↩</a></p>
</li>
</ol>
</section>',
        ];

        yield 'USE / [^c]: ::: note / ...HR 17' => [
            '[r][] [^p] [^c]
[^c]: ::: note
   ---
', '<p>[r][] [^p] <a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a></p>
<section role="doc-endnotes" aria-label="Footnotes">
<hr>
<ol>
<li id="fn1">
<aside class="admonition note" aria-label="Note">
<hr>
</aside>
<p><a href="#fnref1" role="doc-backlink" aria-label="Back to reference">↩</a></p>
</li>
</ol>
</section>',
        ];

        yield 'USE / [^c]: ::: note / .......HEAD 18' => [
            '[r][] [^p] [^c]
[^c]: ::: note
       # H
', '<p>[r][] [^p] <a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a></p>
<section role="doc-endnotes" aria-label="Footnotes">
<hr>
<ol>
<li id="fn1">
<aside class="admonition note" aria-label="Note">
<h1 id="H">H</h1>
</aside>
<p><a href="#fnref1" role="doc-backlink" aria-label="Back to reference">↩</a></p>
</li>
</ol>
</section>',
        ];

        yield 'USE / [^c]: :: t / .........LDEF 19' => [
            '[r][] [^p] [^c]
[^c]: :: t
         [r]: /u
', '<p>[r][] [^p] <a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a></p>
<section role="doc-endnotes" aria-label="Footnotes">
<hr>
<ol>
<li id="fn1">
<dl>
<dt>t
[r]: /u</dt>
</dl>
<p><a href="#fnref1" role="doc-backlink" aria-label="Back to reference">↩</a></p>
</li>
</ol>
</section>',
        ];

        yield 'USE / [^c]: ::: note / .....HEAD 20' => [
            '[r][] [^p] [^c]
[^c]: ::: note
     # H
', '<p>[r][] [^p] <a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a></p>
<section role="doc-endnotes" aria-label="Footnotes">
<hr>
<ol>
<li id="fn1">
<aside class="admonition note" aria-label="Note">
<h1 id="H">H</h1>
</aside>
<p><a href="#fnref1" role="doc-backlink" aria-label="Back to reference">↩</a></p>
</li>
</ol>
</section>',
        ];

        yield 'USE / [^c]: ::: note / ......ROW 21' => [
            '[r][] [^p] [^c]
[^c]: ::: note
      | a |
', '<p>[r][] [^p] <a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a></p>
<section role="doc-endnotes" aria-label="Footnotes">
<hr>
<ol>
<li id="fn1">
<aside class="admonition note" aria-label="Note">
<table>
<tbody>
<tr><td>a</td></tr>
</tbody>
</table>
</aside>
<p><a href="#fnref1" role="doc-backlink" aria-label="Back to reference">↩</a></p>
</li>
</ol>
</section>',
        ];

        yield 'USE / [^c]: ::: note / ...ATTR 22' => [
            '[r][] [^p] [^c]
[^c]: ::: note
   {#x}
', '<p>[r][] [^p] <a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a></p>
<section role="doc-endnotes" aria-label="Footnotes">
<hr>
<ol>
<li id="fn1">
<aside class="admonition note" aria-label="Note">
</aside>
<p><a href="#fnref1" role="doc-backlink" aria-label="Back to reference">↩</a></p>
</li>
</ol>
</section>',
        ];

        yield 'USE / [^c]: :: t / ........LDEF 23' => [
            '[r][] [^p] [^c]
[^c]: :: t
        [r]: /u
', '<p>[r][] [^p] <a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a></p>
<section role="doc-endnotes" aria-label="Footnotes">
<hr>
<ol>
<li id="fn1">
<dl>
<dt>t
[r]: /u</dt>
</dl>
<p><a href="#fnref1" role="doc-backlink" aria-label="Back to reference">↩</a></p>
</li>
</ol>
</section>',
        ];

        yield 'USE / [^c]: ::: note / ..........HEAD 24' => [
            '[r][] [^p] [^c]
[^c]: ::: note
          # H
', '<p>[r][] [^p] <a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a></p>
<section role="doc-endnotes" aria-label="Footnotes">
<hr>
<ol>
<li id="fn1">
<aside class="admonition note" aria-label="Note">
<h1 id="H">H</h1>
</aside>
<p><a href="#fnref1" role="doc-backlink" aria-label="Back to reference">↩</a></p>
</li>
</ol>
</section>',
        ];

        yield 'USE / [^c]: ::: note / .......ROW 25' => [
            '[r][] [^p] [^c]
[^c]: ::: note
       | a |
', '<p>[r][] [^p] <a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a></p>
<section role="doc-endnotes" aria-label="Footnotes">
<hr>
<ol>
<li id="fn1">
<aside class="admonition note" aria-label="Note">
<table>
<tbody>
<tr><td>a</td></tr>
</tbody>
</table>
</aside>
<p><a href="#fnref1" role="doc-backlink" aria-label="Back to reference">↩</a></p>
</li>
</ol>
</section>',
        ];

        yield 'USE / [^c]: ::: note / ...ROW 26' => [
            '[r][] [^p] [^c]
[^c]: ::: note
   | a |
', '<p>[r][] [^p] <a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a></p>
<section role="doc-endnotes" aria-label="Footnotes">
<hr>
<ol>
<li id="fn1">
<aside class="admonition note" aria-label="Note">
<table>
<tbody>
<tr><td>a</td></tr>
</tbody>
</table>
</aside>
<p><a href="#fnref1" role="doc-backlink" aria-label="Back to reference">↩</a></p>
</li>
</ol>
</section>',
        ];

        yield 'USE / [^c]: :: t / .........NDEF 27' => [
            '[r][] [^p] [^c]
[^c]: :: t
         [^p]: n
', '<p>[r][] [^p] <a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a></p>
<section role="doc-endnotes" aria-label="Footnotes">
<hr>
<ol>
<li id="fn1">
<dl>
<dt>t
[^p]: n</dt>
</dl>
<p><a href="#fnref1" role="doc-backlink" aria-label="Back to reference">↩</a></p>
</li>
</ol>
</section>',
        ];

        yield '[^c]: ::: note / ......more / CMT / ``` 28' => [
            '[^c]: ::: note
      more
%% c
```
', '',
        ];

        yield '[^c]: 1. para / .CMT / ``` 29' => [
            '[^c]: 1. para
 %% c
```
', '',
        ];

        yield 'control 1' => [
            '[^c]: para
   # H

[^c]
', '<p><a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a></p><section role="doc-endnotes" aria-label="Footnotes"><hr><ol><li id="fn1"><p>para</p><h1 id="H">H</h1><p><a href="#fnref1" role="doc-backlink" aria-label="Back to reference">↩</a></p></li></ol></section>',
        ];

        yield 'control 2' => [
            '[^c]: ::: note
  para
  :::

[^c]
', '<p><a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a></p><section role="doc-endnotes" aria-label="Footnotes"><hr><ol><li id="fn1"><aside class="admonition note" aria-label="Note"><p>para</p></aside><p><a href="#fnref1" role="doc-backlink" aria-label="Back to reference">↩</a></p></li></ol></section>',
        ];
    }
}
