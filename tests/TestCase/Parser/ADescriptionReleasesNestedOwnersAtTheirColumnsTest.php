<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Test\RuleATodo;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ADescriptionReleasesNestedOwnersAtTheirColumnsTest extends TestCase
{
    use RuleATodo;

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function examples(): iterable
    {
        yield '3655 table' => [":: T\n: D\n    [^n]: e\n\n     | a |\n", "<dl>\n<dt>T</dt>\n<dd>\n<p>D</p>\n<table>\n<tbody>\n<tr><td>a</td></tr>\n</tbody>\n</table>\n</dd>\n</dl>"];
        yield '3655 quote' => [":: T\n: D\n    [^n]: e\n\n     > q\n", "<dl>\n<dt>T</dt>\n<dd>\n<p>D</p>\n<blockquote><p>q</p></blockquote>\n</dd>\n</dl>"];
        yield '3656 separator' => ["- h0\n  :: t\n  : d\n    [^n]: note\n    - a\n     :: u\n     :  e\n", "<ul>\n<li>h0\n<dl>\n<dt>t</dt>\n<dd>\n<p>d</p>\n<ul>\n<li>a</li>\n</ul>\n<dl>\n<dt>u</dt>\n<dd>e</dd>\n</dl>\n</dd>\n</dl>\n</li>\n</ul>"];
        yield '3657 postblank item' => [":: T\n: D\n    :: t\n    :  d\n     - b\n\n    x\n", "<dl>\n<dt>T</dt>\n<dd>\n<p>D</p>\n<dl>\n<dt>t</dt>\n<dd>d</dd>\n</dl>\n<ul>\n<li>b</li>\n</ul>\n<p>x</p>\n</dd>\n</dl>"];
        yield '3658 document' => [":: t\n: d\n  :: u\n  :  e\n  1. q\n", "<dl>\n<dt>t</dt>\n<dd>\n<p>d</p>\n<dl>\n<dt>u</dt>\n<dd>e</dd>\n</dl>\n<ol>\n<li>q</li>\n</ol>\n</dd>\n</dl>"];
        yield '3658 item' => ["- h0\n  :: t\n  : d\n    :: u\n    :  e\n    1. q\n", "<ul>\n<li>h0\n<dl>\n<dt>t</dt>\n<dd>\n<p>d</p>\n<dl>\n<dt>u</dt>\n<dd>e</dd>\n</dl>\n<ol>\n<li>q</li>\n</ol>\n</dd>\n</dl>\n</li>\n</ul>"];
        yield '3655 bullet host' => ["- host\n  :: T\n  : D\n      [^n]: e\n\n       | a |\n", "<ul>\n<li>host\n<dl>\n<dt>T</dt>\n<dd>\n<p>D</p>\n<table>\n<tbody>\n<tr><td>a</td></tr>\n</tbody>\n</table>\n</dd>\n</dl>\n</li>\n</ul>"];
        yield '3655 quote host' => ["> :: T\n> : D\n>     [^n]: e\n>\n>      > q\n", "<blockquote>\n<dl>\n<dt>T</dt>\n<dd>\n<p>D</p>\n<blockquote><p>q</p></blockquote>\n</dd>\n</dl>\n</blockquote>"];
        yield '3656 ordered host' => ["1. h0\n   :: t\n   : d\n     [^n]: note\n     - a\n      :: u\n      :  e\n", "<ol>\n<li>h0\n<dl>\n<dt>t</dt>\n<dd>\n<p>d</p>\n<ul>\n<li>a</li>\n</ul>\n<dl>\n<dt>u</dt>\n<dd>e</dd>\n</dl>\n</dd>\n</dl>\n</li>\n</ol>"];
        yield '3657 ordered item' => [":: T\n: D\n    :: t\n    :  d\n     1. b\n\n    x\n", "<dl>\n<dt>T</dt>\n<dd>\n<p>D</p>\n<dl>\n<dt>t</dt>\n<dd>d</dd>\n</dl>\n<ol>\n<li>b</li>\n</ol>\n<p>x</p>\n</dd>\n</dl>"];
        yield '3657 quote host' => ["> :: T\n> : D\n>     :: t\n>     :  d\n>      - b\n>\n>     x\n", "<blockquote>\n<dl>\n<dt>T</dt>\n<dd>\n<p>D</p>\n<dl>\n<dt>t</dt>\n<dd>d</dd>\n</dl>\n<ul>\n<li>b</li>\n</ul>\n<p>x</p>\n</dd>\n</dl>\n</blockquote>"];
        yield '3658 task item' => [":: t\n: d\n  :: u\n  :  e\n  - [ ] q\n", "<dl>\n<dt>t</dt>\n<dd>\n<p>d</p>\n<dl>\n<dt>u</dt>\n<dd>e</dd>\n</dl>\n<ul class=\"task-list\">\n<li><input type=\"checkbox\" disabled aria-label=\"q\"> q</li>\n</ul>\n</dd>\n</dl>"];
        yield '3658 quote host' => ["> :: t\n> : d\n>   :: u\n>   :  e\n>   1. q\n", "<blockquote>\n<dl>\n<dt>t</dt>\n<dd>\n<p>d</p>\n<dl>\n<dt>u</dt>\n<dd>e</dd>\n</dl>\n<ol>\n<li>q</li>\n</ol>\n</dd>\n</dl>\n</blockquote>"];
        yield '3655 note floor control' => [":: T\n: D\n    [^n]: e\n\n      | a |\n", "<dl>\n<dt>T</dt>\n<dd>D</dd>\n</dl>"];
        yield '3656 same column control' => [":: t\n: d\n  :: u\n  :  e\n", "<dl>\n<dt>t</dt>\n<dd>\n<p>d</p>\n<dl>\n<dt>u</dt>\n<dd>e</dd>\n</dl>\n</dd>\n</dl>"];
        yield '3657 item column control' => [":: T\n: D\n     - b\n\n       x\n", "<dl>\n<dt>T</dt>\n<dd>\n<p>D\n- b</p>\n<p>x</p>\n</dd>\n</dl>"];
        yield '3658 inner column control' => [":: t\n: d\n  :: u\n  :  e\n     1. q\n", "<dl>\n<dt>t</dt>\n<dd>\n<p>d</p>\n<dl>\n<dt>u</dt>\n<dd>e\n1. q</dd>\n</dl>\n</dd>\n</dl>"];
        yield '3655 released fence body' => [":: T\n: D\n    [^n]: e\n\n     ```\n       x\n     ```", "<dl>\n<dt>T</dt>\n<dd>\n<p>D</p>\n<pre><code>  x\n</code></pre>\n</dd>\n</dl>"];
        yield '3655 released list siblings' => [":: T\n: D\n    [^n]: e\n\n     - a\n     - b", "<dl>\n<dt>T</dt>\n<dd>\n<p>D</p>\n<ul>\n<li>a</li>\n<li>b</li>\n</ul>\n</dd>\n</dl>"];
        yield '3655 released quote continuation' => [":: T\n: D\n    [^n]: e\n\n     > q\n     > r", "<dl>\n<dt>T</dt>\n<dd>\n<p>D</p>\n<blockquote><p>q\nr</p></blockquote>\n</dd>\n</dl>"];
        yield '3657 item continuation then release' => [":: T\n: D\n    :: t\n    :  d\n     - b\n\n       c\n\n    x", "<dl>\n<dt>T</dt>\n<dd>\n<p>D</p>\n<dl>\n<dt>t</dt>\n<dd>d</dd>\n</dl>\n<ul>\n<li><p>b</p>\n<p>c</p>\n</li>\n</ul>\n<p>x</p>\n</dd>\n</dl>"];
        yield '3656 second term' => ["- h0\n  :: t\n  : d\n    [^n]: note\n    - a\n     :: u\n     :: v\n     :  e", "<ul>\n<li>h0\n<dl>\n<dt>t</dt>\n<dd>\n<p>d</p>\n<ul>\n<li>a</li>\n</ul>\n<dl>\n<dt>u</dt>\n<dt>v</dt>\n<dd>e</dd>\n</dl>\n</dd>\n</dl>\n</li>\n</ul>"];
        yield '3656 second separator' => ["- h0\n  :: t\n  : d\n    [^n]: note\n    - a\n     :: u\n     :  e\n     :  f", "<ul>\n<li>h0\n<dl>\n<dt>t</dt>\n<dd>\n<p>d</p>\n<ul>\n<li>a</li>\n</ul>\n<dl>\n<dt>u</dt>\n<dd>e</dd>\n<dd>f</dd>\n</dl>\n</dd>\n</dl>\n</li>\n</ul>"];
        yield '3656 folded term' => ["- h0\n  :: t\n  : d\n    [^n]: note\n    - a\n     :: u\n     more\n     :  e", "<ul>\n<li>h0\n<dl>\n<dt>t</dt>\n<dd>\n<p>d</p>\n<ul>\n<li>a</li>\n</ul>\n<dl>\n<dt>u\nmore</dt>\n<dd>e</dd>\n</dl>\n</dd>\n</dl>\n</li>\n</ul>"];
        yield '3658 closed description paragraph' => [":: t\n: d\n  :: u\n  :  e\n\n  p\n    - q", "<dl>\n<dt>t</dt>\n<dd>\n<p>d</p>\n<dl>\n<dt>u</dt>\n<dd>e</dd>\n</dl>\n<p>p\n- q</p>\n</dd>\n</dl>"];
        yield '3658 ordered siblings' => [":: t\n: d\n  :: u\n  :  e\n  1. q\n  2. r", "<dl>\n<dt>t</dt>\n<dd>\n<p>d</p>\n<dl>\n<dt>u</dt>\n<dd>e</dd>\n</dl>\n<ol>\n<li>q</li>\n<li>r</li>\n</ol>\n</dd>\n</dl>"];
        yield '3657 marker column control' => [":: T\n: D\n    :: t\n    :  d\n     - b\n\n     x", "<dl>\n<dt>T</dt>\n<dd>\n<p>D</p>\n<dl>\n<dt>t</dt>\n<dd>d</dd>\n</dl>\n<ul>\n<li>b</li>\n</ul>\n<p>x</p>\n</dd>\n</dl>"];
        yield '3655 released fence below opener' => [":: T\n: D\n    [^n]: e\n\n     ```\n    x\n     ```", "<dl>\n<dt>T</dt>\n<dd>\n<p>D</p>\n<pre><code>x\n</code></pre>\n</dd>\n</dl>"];
        yield '3655 released fence across blank' => [":: T\n: D\n    [^n]: e\n\n     ```\n     x\n\n    y\n     z\n     ```", "<dl>\n<dt>T</dt>\n<dd>\n<p>D</p>\n<pre><code>x\n\ny\nz\n</code></pre>\n</dd>\n</dl>"];
        yield '3658 colon payload inside fence' => [":: T\n: D\n\n  ```\n  :   d\n     - b\n  ```", "<dl>\n<dt>T</dt>\n<dd>\n<p>D</p>\n<pre><code>:   d\n   - b\n</code></pre>\n</dd>\n</dl>"];
        yield 'note-owned postblank text' => [":: term\n:  detail\n  [^n]: note\n    [^n]: note\n      1. ordered\n       +\n       \n       text\n", "<dl>\n<dt>term</dt>\n<dd>detail\n<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a>: note</dd>\n</dl>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n<hr>\n<ol>\n<li id=\"fn1\">\n<p>note\n1. ordered\n+</p>\n<p>text<a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n</li>\n</ol>\n</section>"];
        yield '3656 trailing term space' => ["- h0\n  :: t\n  : d\n    [^n]: note\n    - a\n     :: u \n     :  e\n", "<ul>\n<li>h0\n<dl>\n<dt>t</dt>\n<dd>\n<p>d</p>\n<ul>\n<li>a</li>\n</ul>\n<dl>\n<dt>u</dt>\n<dd>e</dd>\n</dl>\n</dd>\n</dl>\n</li>\n</ul>"];
        yield '3656 trailing separator space' => ["- h0\n  :: t\n  : d\n    [^n]: note\n    - a\n     :: u\n     :  e \n", "<ul>\n<li>h0\n<dl>\n<dt>t</dt>\n<dd>\n<p>d</p>\n<ul>\n<li>a</li>\n</ul>\n<dl>\n<dt>u</dt>\n<dd>e</dd>\n</dl>\n</dd>\n</dl>\n</li>\n</ul>"];
        yield '3656 repeated separator trailing space' => ["- h0\n  :: t\n  : d\n    [^n]: note\n    - a\n     :: u\n     :  e \n     :  f\n", "<ul>\n<li>h0\n<dl>\n<dt>t</dt>\n<dd>\n<p>d</p>\n<ul>\n<li>a</li>\n</ul>\n<dl>\n<dt>u</dt>\n<dd>e</dd>\n<dd>f</dd>\n</dl>\n</dd>\n</dl>\n</li>\n</ul>"];
        yield 'release marker document' => [":: T\n: D\n  :: u\n  :  e\n   - b\n  - c\n", "<dl>\n<dt>T</dt>\n<dd>\n<p>D</p>\n<dl>\n<dt>u</dt>\n<dd>e</dd>\n</dl>\n<ul>\n<li>b</li>\n</ul>\n<ul>\n<li>c</li>\n</ul>\n</dd>\n</dl>"];
        yield 'release marker ordered' => [":: T\n: D\n  :: u\n  :  e\n   1. b\n  1. c\n", "<dl>\n<dt>T</dt>\n<dd>\n<p>D</p>\n<dl>\n<dt>u</dt>\n<dd>e</dd>\n</dl>\n<ol>\n<li>b</li>\n</ol>\n<ol>\n<li>c</li>\n</ol>\n</dd>\n</dl>"];
        yield 'release marker bullet host' => ["- h\n  :: T\n  : D\n    :: u\n    :  e\n     - b\n    - c\n", "<ul>\n<li>h\n<dl>\n<dt>T</dt>\n<dd>\n<p>D</p>\n<dl>\n<dt>u</dt>\n<dd>e</dd>\n</dl>\n<ul>\n<li>b</li>\n</ul>\n<ul>\n<li>c</li>\n</ul>\n</dd>\n</dl>\n</li>\n</ul>"];
        yield 'release marker depth two' => ["- h\n  - j\n    :: T\n    : D\n      :: u\n      :  e\n       - b\n      - c\n", "<ul>\n<li>h\n<ul>\n<li>j\n<dl>\n<dt>T</dt>\n<dd>\n<p>D</p>\n<dl>\n<dt>u</dt>\n<dd>e</dd>\n</dl>\n<ul>\n<li>b</li>\n</ul>\n<ul>\n<li>c</li>\n</ul>\n</dd>\n</dl>\n</li>\n</ul>\n</li>\n</ul>"];
        yield 'release marker quote' => ["> :: T\n> : D\n>   :: u\n>   :  e\n>    - b\n>   - c\n", "<blockquote>\n<dl>\n<dt>T</dt>\n<dd>\n<p>D</p>\n<dl>\n<dt>u</dt>\n<dd>e</dd>\n</dl>\n<ul>\n<li>b</li>\n</ul>\n<ul>\n<li>c</li>\n</ul>\n</dd>\n</dl>\n</blockquote>"];
        yield 'release text document' => [":: T\n: D\n    :: u\n    :  e\n\n     x\n", "<dl>\n<dt>T</dt>\n<dd>\n<p>D</p>\n<dl>\n<dt>u</dt>\n<dd>e</dd>\n</dl>\n<p>x</p>\n</dd>\n</dl>"];
        yield 'release text bullet host' => ["- h\n  :: T\n  : D\n      :: u\n      :  e\n\n       x\n", "<ul>\n<li>h\n<dl>\n<dt>T</dt>\n<dd>\n<p>D</p>\n<dl>\n<dt>u</dt>\n<dd>e</dd>\n</dl>\n<p>x</p>\n</dd>\n</dl>\n</li>\n</ul>"];
        yield 'release text ordered host' => ["1. h\n   :: T\n   : D\n       :: u\n       :  e\n\n        x\n", "<ol>\n<li>h\n<dl>\n<dt>T</dt>\n<dd>\n<p>D</p>\n<dl>\n<dt>u</dt>\n<dd>e</dd>\n</dl>\n<p>x</p>\n</dd>\n</dl>\n</li>\n</ol>"];
        yield 'release text depth two' => ["- h\n  - j\n    :: T\n    : D\n        :: u\n        :  e\n\n         x\n", "<ul>\n<li>h\n<ul>\n<li>j\n<dl>\n<dt>T</dt>\n<dd>\n<p>D</p>\n<dl>\n<dt>u</dt>\n<dd>e</dd>\n</dl>\n<p>x</p>\n</dd>\n</dl>\n</li>\n</ul>\n</li>\n</ul>"];
        yield 'release text quote' => ["> :: T\n> : D\n>     :: u\n>     :  e\n>\n>      x\n", "<blockquote>\n<dl>\n<dt>T</dt>\n<dd>\n<p>D</p>\n<dl>\n<dt>u</dt>\n<dd>e</dd>\n</dl>\n<p>x</p>\n</dd>\n</dl>\n</blockquote>"];
        yield 'lazy line before released marker' => [":: T\n: D\n  :: u\n  :  e\n  f\n  - c\n", "<dl>\n<dt>T</dt>\n<dd>\n<p>D</p>\n<dl>\n<dt>u</dt>\n<dd>e\nf</dd>\n</dl>\n<ul>\n<li>c</li>\n</ul>\n</dd>\n</dl>"];
        yield 'lazy line before released marker in item' => ["- h\n  :: T\n  : D\n    :: u\n    :  e\n    f\n    - c\n", "<ul>\n<li>h\n<dl>\n<dt>T</dt>\n<dd>\n<p>D</p>\n<dl>\n<dt>u</dt>\n<dd>e\nf</dd>\n</dl>\n<ul>\n<li>c</li>\n</ul>\n</dd>\n</dl>\n</li>\n</ul>"];
        yield 'released marker survives lazy folding by a fence' => [":: T\n: D\n  para\nlz\n  :: u\n  :  e\n   - b\n  - c\n  ```\n  x\n  ```\n", "<dl>\n<dt>T</dt>\n<dd>\n<p>D\npara\nlz</p>\n<dl>\n<dt>u</dt>\n<dd>e</dd>\n</dl>\n<ul>\n<li>b</li>\n</ul>\n<ul>\n<li>c</li>\n</ul>\n<pre><code>x\n</code></pre>\n</dd>\n</dl>"];
        yield 'term on a note marker has no own separator column' => ["[r][] [^p] [^c] [^d]\n[^c]: :: t\n      : para\n   [^p]: n\n", "<p>[r][] [^p] <a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a> [^d]</p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n<hr>\n<ol>\n<li id=\"fn1\">\n<dl>\n<dt>t\n: para\n[^p]: n</dt>\n</dl>\n<p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n</li>\n</ol>\n</section>"];
    }

    #[DataProvider('examples')]
    public function testRenderedHtml(string $source, string $expected): void
    {
        $html = trim(CarveConverter::create()->convert($source));
        $lines = [];
        $inPre = false;
        foreach (explode("\n", $html) as $line) {
            if ($inPre) {
                $lines[] = $line;
            } elseif (trim($line) !== '') {
                $lines[] = trim($line);
            }
            if (preg_match('/<pre[ >]/', $line) === 1) {
                $inPre = true;
            }
            if (str_contains($line, '</pre>')) {
                $inPre = false;
            }
        }

        $this->assertSame($expected, implode("\n", $lines));
    }
}
