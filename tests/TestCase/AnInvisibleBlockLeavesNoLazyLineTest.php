<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AnInvisibleBlockLeavesNoLazyLineTest extends TestCase
{
    #[DataProvider('cases')]
    public function testContainerExtent(string $source, string $expected): void
    {
        $actual = (new CarveConverter())->convert($source);
        self::assertSame(preg_replace('/\s+/', '', $expected), preg_replace('/\s+/', '', $actual));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function cases(): array
    {
        return [
            'n:quote/desc-3423' => ["[r][] [^p] [^c] [^d]\n\n> :: t\n> : para\n>   more\n>       [^p]: n\n```\nx\n", "<p>[r][] <a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a> [^c] [^d]</p>\n<blockquote>\n  <dl>\n    <dt>t</dt>\n    <dd>para\nmore</dd>\n  </dl>\n</blockquote>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <p>n\n<code>\nx</code><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>"],
            'n:quote/desc-5782' => ["[r][] [^p] [^c] [^d]\n\n> :: t\n> : para\n>   more\n>    [r]: /u\ntail\n", "<p><a href=\"/u\">r</a> [^p] [^c] [^d]</p>\n<blockquote>\n<dl>\n<dt>t</dt>\n<dd>para\nmore</dd>\n</dl>\n</blockquote>\n<p>tail</p>"],
            'n:quote/desc-5809' => ["[r][] [^p] [^c] [^d]\n\n> :: t\n> : para\n>   more\n>    [r]: /u\n```\nx\n", "<p><a href=\"/u\">r</a> [^p] [^c] [^d]</p>\n<blockquote>\n<dl>\n<dt>t</dt>\n<dd>para\nmore</dd>\n</dl>\n</blockquote>\n<pre><code>x\n</code></pre>"],
            // Extra indentation has no definition site outside an open item.
            'reg23:s:quote-456' => ["[r][] [^p] [^c] [^d]\n\n> para\n>  [^p]: n\ntail\n", "<p>[r][] <a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a> [^c] [^d]</p>\n<blockquote><p>para</p></blockquote>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <p>n\ntail<a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>"],
            'reg23:s:quote-458' => ["[r][] [^p] [^c] [^d]\n\n> para\n>  [^p]: n\n```\nx\n", "<p>[r][] <a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a> [^c] [^d]</p>\n<blockquote><p>para</p></blockquote>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <p>n\n<code>\nx</code><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>"],
            'reg23:s:quote-466' => ["[r][] [^p] [^c] [^d]\n\n> para\n>     [^p]: n\ntail\n", "<p>[r][] <a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a> [^c] [^d]</p>\n<blockquote><p>para</p></blockquote>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <p>n\ntail<a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>"],
            'reg23:s:quote-468' => ["[r][] [^p] [^c] [^d]\n\n> para\n>     [^p]: n\n```\nx\n", "<p>[r][] <a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a> [^c] [^d]</p>\n<blockquote><p>para</p></blockquote>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <p>n\n<code>\nx</code><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>"],
            'reg23:s:quote-832' => ["[r][] [^p] [^c] [^d]\n\n> para\n> more\n>  [^p]: n\ntail\n", "<p>[r][] <a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a> [^c] [^d]</p>\n<blockquote><p>para\nmore</p></blockquote>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <p>n\ntail<a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>"],
            'reg23:s:quote-834' => ["[r][] [^p] [^c] [^d]\n\n> para\n> more\n>  [^p]: n\n```\nx\n", "<p>[r][] <a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a> [^c] [^d]</p>\n<blockquote><p>para\nmore</p></blockquote>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <p>n\n<code>\nx</code><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>"],
            'reg23:s:quote-842' => ["[r][] [^p] [^c] [^d]\n\n> para\n> more\n>     [^p]: n\ntail\n", "<p>[r][] <a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a> [^c] [^d]</p>\n<blockquote><p>para\nmore</p></blockquote>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <p>n\ntail<a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>"],
            'reg23:s:quote-844' => ["[r][] [^p] [^c] [^d]\n\n> para\n> more\n>     [^p]: n\n```\nx\n", "<p>[r][] <a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a> [^c] [^d]</p>\n<blockquote><p>para\nmore</p></blockquote>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <p>n\n<code>\nx</code><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>"],
            'reg23:n:quote/ordered-2849' => ["[r][] [^p] [^c] [^d]\n\n> 1. para\n>  [^p]: n\ntail\n", "<p>[r][] [^p] [^c] [^d]</p>\n<blockquote>\n<ol>\n<li>para\n[^p]: n\ntail</li>\n</ol>\n</blockquote>"],
            'reg23:n:desc/footnote-2891' => ["[r][] [^p] [^c] [^d]\n\n:: t\n: [^c]: para\n    more\n        [^p]: n\n::::\ntail\n", "<p>[r][] <a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a> <a id=\"fnref2\" href=\"#fn2\" role=\"doc-noteref\"><sup>2</sup></a> [^d]</p>\n<dl>\n<dt>t</dt>\n<dd></dd>\n</dl>\n<div>\n<p>tail</p>\n</div>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n<hr>\n<ol>\n<li id=\"fn1\">\n<p>n<a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n</li>\n<li id=\"fn2\">\n<p>para\nmore<a href=\"#fnref2\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n</li>\n</ol>\n</section>"],
            'reg23:n:quote/item-2894' => ["[r][] [^p] [^c] [^d]\n\n> - para\n>   more\n>  [^p]: n\ntail\n", "<p>[r][] [^p] [^c] [^d]</p>\n<blockquote>\n<ul>\n<li>para\nmore\n[^p]: n\ntail</li>\n</ul>\n</blockquote>"],
            'reg23:n:desc/footnote-3160' => ["[r][] [^p] [^c] [^d]\n\n:: t\n: [^c]: para\n    more\n        [^p]: n\n```\nx\n", "<p>[r][] <a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a> <a id=\"fnref2\" href=\"#fn2\" role=\"doc-noteref\"><sup>2</sup></a> [^d]</p>\n<dl>\n  <dt>t</dt>\n  <dd></dd>\n</dl>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <p>n\n<code>\nx</code><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n    <li id=\"fn2\">\n      <p>para\nmore<a href=\"#fnref2\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>"],
            'reg23:n:desc/quote-3248' => ["[r][] [^p] [^c] [^d]\n\n:: t\n: > para\n  > more\n  >  [^p]: n\ntail\n", "<p>[r][] [^p] [^c] [^d]</p>\n<dl>\n<dt>t</dt>\n<dd>\n<blockquote><p>para\nmore\n[^p]: n\ntail</p></blockquote>\n</dd>\n</dl>"],
            'reg23:n:quote/note-3440' => ["[r][] [^p] [^c] [^d]\n\n> ::: note\n> para\n>  [^p]: n\ntail\n", "<p>[r][] [^p] [^c] [^d]</p>\n<blockquote>\n<aside class=\"admonition note\" aria-label=\"Note\">\n<p>para\n[^p]: n\ntail</p>\n</aside>\n</blockquote>"],
            'reg23:n:desc/desc-3466' => ["[r][] [^p] [^c] [^d]\n\n:: t\n: :: t\n  : para\n    more\n   [^p]: n\ntail\n", "<p>[r][] <a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a> [^c] [^d]</p>\n<dl>\n  <dt>t</dt>\n  <dd>\n    <dl>\n      <dt>t</dt>\n      <dd>para\nmore</dd>\n    </dl>\n  </dd>\n</dl>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <p>n\ntail<a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>"],
            'reg23:n:desc/quote-4158' => ["[r][] [^p] [^c] [^d]\n\n:: t\n: > para\n  > more\n  >     [^p]: n\n```\nx\n", "<p>[r][] [^p] [^c] [^d]</p>\n<dl>\n<dt>t</dt>\n<dd>\n<blockquote><p>para\nmore\n[^p]: n\n<code>\nx</code></p></blockquote>\n</dd>\n</dl>"],
            'reg23:n:note/quote-4523' => ["[r][] [^p] [^c] [^d]\n\n::: note\n> para\n>  [^p]: n\n```\nx\n", "<p>[r][] [^p] [^c] [^d]</p>\n<aside class=\"admonition note\" aria-label=\"Note\">\n<blockquote><p>para\n[^p]: n\n<code>\nx</code></p></blockquote>\n</aside>"],
            'reg23:n:quote/quote-4940' => ["[r][] [^p] [^c] [^d]\n\n> > para\n> > more\n> >  [^p]: n\ntail\n", "<p>[r][] [^p] [^c] [^d]</p>\n<blockquote>\n<blockquote><p>para\nmore\n[^p]: n\ntail</p></blockquote>\n</blockquote>"],
            'reg23:n:note/quote-5210' => ["[r][] [^p] [^c] [^d]\n\n::: note\n> para\n>  [^p]: n\ntail\n", "<p>[r][] [^p] [^c] [^d]</p>\n<aside class=\"admonition note\" aria-label=\"Note\">\n<blockquote><p>para\n[^p]: n\ntail</p></blockquote>\n</aside>"],
            'reg23:n:desc/footnote-5260' => ["[r][] [^p] [^c] [^d]\n\n:: t\n: [^c]: para\n    more\n     [^p]: n\n```\nx\n", "<p>[r][] <a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a> <a id=\"fnref2\" href=\"#fn2\" role=\"doc-noteref\"><sup>2</sup></a> [^d]</p>\n<dl>\n  <dt>t</dt>\n  <dd></dd>\n</dl>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <p>n\n<code>\nx</code><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n    <li id=\"fn2\">\n      <p>para\nmore<a href=\"#fnref2\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>"],
            'reg23:n:quote/ordered-5293' => ["[r][] [^p] [^c] [^d]\n\n> 1. para\n>    more\n>  [^p]: n\n```\nx\n", "<p>[r][] [^p] [^c] [^d]</p>\n<blockquote>\n<ol>\n<li>para\nmore\n[^p]: n\n<code>\nx</code></li>\n</ol>\n</blockquote>"],
            'reg23:n:note/quote-5380' => ["[r][] [^p] [^c] [^d]\n\n::: note\n> para\n>     [^p]: n\ntail\n", "<p>[r][] [^p] [^c] [^d]</p>\n<aside class=\"admonition note\" aria-label=\"Note\">\n<blockquote><p>para\n[^p]: n\ntail</p></blockquote>\n</aside>"],
            'reg23:n:quote/item-5541' => ["[r][] [^p] [^c] [^d]\n\n> - para\n>   more\n>  [^p]: n\n```\nx\n", "<p>[r][] [^p] [^c] [^d]</p>\n<blockquote>\n<ul>\n<li>para\nmore\n[^p]: n\n<code>\nx</code></li>\n</ul>\n</blockquote>"],
            'n:desc/note-2992' => [":: t\n: ::: note\n  more\n[^p]: n\nx\n", '<dl><dt>t</dt><dd><aside class="admonition note" aria-label="Note"><p>more</p></aside></dd></dl><p>x</p>'],
            'n:ordered/quote-3064' => ["1. > para\n         [r]: /u\nx\n", '<ol><li><blockquote><p>para</p></blockquote></li></ol><p>x</p>'],
            'n:item/quote-3072' => ["- > para\n        [r]: /u\ntail\n", '<ul><li><blockquote><p>para</p></blockquote></li></ul><p>tail</p>'],
            'n:item/note-3091' => ["- ::: note\n   [r]: /u\ntail\n", '<ul><li><aside class="admonition note" aria-label="Note"></aside></li></ul><p>tail</p>'],
            'n:ordered/quote-3464' => ["1. > para\n     [^p]: n\nx\n", '<ol><li><blockquote><p>para</p></blockquote></li></ol>'],
            'n:item/note-3752' => ["- ::: note\n  {#x}\ntail\n", '<ul><li><aside class="admonition note" aria-label="Note"></aside></li></ul><p>tail</p>'],
            'n:quote/note-3967' => ["> ::: note\n>  %% c\ntail\n", '<blockquote><aside class="admonition note" aria-label="Note"></aside></blockquote><p>tail</p>'],
            'n:ordered/note-4285' => ["1. ::: note\n       [r]: /u\nx\n", '<ol><li><aside class="admonition note" aria-label="Note"></aside></li></ol><p>x</p>'],
            'n:ordered/note-4406' => ["1. ::: note\n   [^p]: n\nx\n", '<ol><li><aside class="admonition note" aria-label="Note"></aside></li></ol><p>x</p>'],
            'n:desc/note-4460' => [":: t\n: ::: note\n  [^p]: n\nx\n", '<dl><dt>t</dt><dd><aside class="admonition note" aria-label="Note"></aside></dd></dl>'],
            'n:ordered/note-4464' => ["1. ::: note\n    [^p]: n\ntail\n", '<ol><li><aside class="admonition note" aria-label="Note"></aside></li></ol><p>tail</p>'],
            'n:desc/note-4702' => [":: t\n: ::: note\n      [^p]: n\ntail\n", '<dl><dt>t</dt><dd><aside class="admonition note" aria-label="Note"></aside></dd></dl>'],
            'n:ordered/quote-4947' => ["1. > para\n      [^p]: n\nx\n", '<ol><li><blockquote><p>para</p></blockquote></li></ol>'],
            'n:ordered/quote-5094' => ["1. > para\n     [r]: /u\ntail\n", '<ol><li><blockquote><p>para</p></blockquote></li></ol><p>tail</p>'],
            'n:ordered/note-5348' => ["1. ::: note\n       [r]: /u\ntail\n", '<ol><li><aside class="admonition note" aria-label="Note"></aside></li></ol><p>tail</p>'],
            'n:ordered/quote-5422' => ["1. > para\n     [^p]: n\ntail\n", '<ol><li><blockquote><p>para</p></blockquote></li></ol>'],
            'n:ordered/note-5437' => ["1. ::: note\n    [r]: /u\ntail\n", '<ol><li><aside class="admonition note" aria-label="Note"></aside></li></ol><p>tail</p>'],
            'n:quote/item-5445' => ["> - para\n>    [^p]: n\nx\n", '<blockquote><ul><li>para</li></ul></blockquote>'],
            'n:item/note-5500' => ["- ::: note\n      [r]: /u\nx\n", '<ul><li><aside class="admonition note" aria-label="Note"></aside></li></ul><p>x</p>'],
            'n:item/note-5684' => ["- ::: note\n  [^p]: n\nx\n", '<ul><li><aside class="admonition note" aria-label="Note"></aside></li></ul><p>x</p>'],
            'n:item/quote-5691' => ["- > para\n    [^p]: n\ntail\n", '<ul><li><blockquote><p>para</p></blockquote></li></ul>'],
            'n:item/note-5760' => ["- ::: note\n  [^p]: n\ntail\n", '<ul><li><aside class="admonition note" aria-label="Note"></aside></li></ul><p>tail</p>'],
            'n:ordered/note-5805' => ["1. ::: note\n   [r]: /u\ntail\n", '<ol><li><aside class="admonition note" aria-label="Note"></aside></li></ol><p>tail</p>'],
            'n:item/note-5832' => ["- ::: note\n  [r]: /u\nx\n", '<ul><li><aside class="admonition note" aria-label="Note"></aside></li></ul><p>x</p>'],
            'n:quote/note-5993' => ["> ::: note\n> [r]: /u\nx\n", '<blockquote><aside class="admonition note" aria-label="Note"></aside></blockquote><p>x</p>'],
            'famI:c000' => ["- para\n   {#x}\ntail\n", '<ul><li>para</li></ul><p>tail</p>'],
            'famI:c001' => ["- para\n   {#x}\n```\n", '<ul><li>para</li></ul><pre><code></code></pre>'],
            'famI:c002' => ["- para\n      {#x}\ntail\n", '<ul><li>para</li></ul><p>tail</p>'],
            'famI:c003' => ["- para\n      {#x}\n```\n", '<ul><li>para</li></ul><pre><code></code></pre>'],
            'famI:c004' => ["1. para\n    {#x}\ntail\n", '<ol><li>para</li></ol><p>tail</p>'],
            'famI:c005' => ["1. para\n    {#x}\n```\n", '<ol><li>para</li></ol><pre><code></code></pre>'],
            'famI:c006' => ["1. para\n       {#x}\ntail\n", '<ol><li>para</li></ol><p>tail</p>'],
            'famI:c007' => ["1. para\n       {#x}\n```\n", '<ol><li>para</li></ol><pre><code></code></pre>'],
            'famI:c023' => ["- 1. para\n   {#x}\nx\n", '<ul><li><ol><li>para</li></ol></li></ul><p>x</p>'],
            'famI:c028' => ["- - para\n   {#x}\ntail\n", '<ul><li><ul><li>para</li></ul></li></ul><p>tail</p>'],
            'famI:c072' => ["1. 1. para\n     {#x}\ntail\n", '<ol><li><ol><li>para</li></ol></li></ol><p>tail</p>'],
            'famI:c143' => ["- 1. para\n    {#x}\nx\n", '<ul><li><ol><li>para</li></ol></li></ul><p>x</p>'],
            'famI:c146' => ["- [^c]: para\n   {#x}\ntail\n", '<ul><li></li></ul><p>tail</p>'],
            'famI:c192' => ["1. - para\n    {#x}\ntail\n", '<ol><li><ul><li>para</li></ul></li></ol><p>tail</p>'],
            'famI:c154' => ["1. :: t\n   : para\n     {#x}\ntail\n", '<ol><li><dl><dt>t</dt><dd>para</dd></dl></li></ol><p>tail</p>'],
            'famI:c189' => ["1. :: t\n   : para\n      {#x}\ntail\n", '<ol><li><dl><dt>t</dt><dd>para</dd></dl></li></ol><p>tail</p>'],
            'famI:c177' => ["- :: t\n  : para\n   {#x}\ntail\n", '<ul><li><dl><dt>t</dt><dd>para</dd></dl></li></ul><p>tail</p>'],
            'famI:c033' => ["- [^c]: para\n        [^p]: n\n    tail\n", '<ul><li>tail</li></ul>'],
            'famI:c047' => ["- > para\n     [^p]: n\n    tail\n", '<ul><li><blockquote><p>para</p></blockquote>tail</li></ul>'],
            'famI:c080' => ["- > para\n        [^p]: n\n    tail\n", '<ul><li><blockquote><p>para</p></blockquote>tail</li></ul>'],
            'famI:c114' => ["1. [^c]: para\n         [^p]: n\n     tail\n", '<ol><li>tail</li></ol>'],
            'open-div-paragraph' => ["- ::: note\n  para\ntail\n", '<ul><li><aside class="admonition note" aria-label="Note"><p>para tail</p></aside></li></ul>'],
            'open-quote-paragraph' => ["1. > para\ntail\n", '<ol><li><blockquote><p>para tail</p></blockquote></li></ol>'],
        ];
    }
}
