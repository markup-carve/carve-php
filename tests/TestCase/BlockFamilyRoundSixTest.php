<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Test\RuleATodo;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class BlockFamilyRoundSixTest extends TestCase
{
    use RuleATodo;

    public function testGeneratedCases(): void
    {
        $json = <<<'JSON'
[
  {
    "id": "r6_05",
    "source": ":: t\n: 1. para\n %%%\n %%%\ntail\n",
    "expected": "<dl>\n<dt>t</dt>\n<dd>\n<ol>\n<li>para\ntail\n</li>\n</ol>\n</dd>\n</dl>",
    "shape": "PHP-4 :: t / : 1~ para / ~%%% / ~%%% / T"
  },
  {
    "id": "r6_06",
    "source": ":: t\n: 1. para\n    %%%\n    %%%\ntail\n",
    "expected": "<dl>\n<dt>t</dt>\n<dd>\n<ol>\n<li>para</li>\n</ol>\n</dd>\n</dl>\n<p>tail</p>",
    "shape": "PHP-4 :: t / : 1~ para / ~%%% / ~%%% / T"
  },
  {
    "id": "r6_07",
    "source": "1. > para\n    %%%\nx\n",
    "expected": "<ol>\n<li>\n<blockquote><p>para</p></blockquote>\n</li>\n</ol>\n<p>x</p>",
    "shape": "PHP-5 1~ > para / ~%%% / T"
  },
  {
    "id": "r6_08",
    "source": "1. > para\n         %%%\nx\n",
    "expected": "<ol>\n<li>\n<blockquote><p>para</p></blockquote>\n</li>\n</ol>\n<p>x</p>",
    "shape": "PHP-5 1~ > para / ~%%% / T"
  },
  {
    "id": "r6_12",
    "source": "[r][] [^p] [^c] [^d]\n[^c]: :: t\n %% c\n",
    "expected": "<p>[r][] [^p] <a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a> [^d]</p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n<hr>\n<ol>\n<li id=\"fn1\">\n<dl>\n<dt>t\n</dt>\n</dl>\n<p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n</li>\n</ol>\n</section>",
    "shape": "PHP-9 USE / [^c]: :: t / ~CMT"
  },
  {
    "id": "r6_13",
    "source": "- :: t\n  : para\n %%%\n %%%\n    tail\n",
    "expected": "<ul>\n<li>\n<dl>\n<dt>t</dt>\n<dd>\n<p>para</p>\n<p>tail</p>\n</dd>\n</dl>\n</li>\n</ul>",
    "shape": "PHP-10 - :: t / ~: para / ~%%% / ~%%% / ~T"
  },
  {
    "id": "r6_14",
    "source": ":: t\n: > para\n   %%%\n   %%%\ntail\n",
    "expected": "<dl>\n<dt>t</dt>\n<dd>\n<blockquote><p>para</p></blockquote>\n</dd>\n</dl>\n<p>tail</p>",
    "shape": "PHP-11 :: t / : > para / ~%%% / ~%%% / T"
  },
  {
    "id": "r6_15",
    "source": ":: t\n: > para\n        %%%\n        %%%\ntail\n",
    "expected": "<dl>\n<dt>t</dt>\n<dd>\n<blockquote><p>para</p></blockquote>\n</dd>\n</dl>\n<p>tail</p>",
    "shape": "PHP-11 :: t / : > para / ~%%% / ~%%% / T"
  },
  {
    "id": "r6_16",
    "source": "1. [^c]: para\n   %%%\n   %%%\n     tail\n",
    "expected": "<ol>\n<li>tail</li>\n</ol>",
    "shape": "PHP-12 1~ [^c]: para / ~%%% / ~%%% / ~T"
  },
  {
    "id": "r6_17",
    "source": "[r][] [^p] [^c] [^d]\n[^c]: :: t\n %%%\n %%%\n",
    "expected": "<p>[r][] [^p] <a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a> [^d]</p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n<hr>\n<ol>\n<li id=\"fn1\">\n<dl>\n<dt>t\n</dt>\n</dl>\n<p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n</li>\n</ol>\n</section>",
    "shape": "PHP-13 USE / [^c]: :: t / ~%%% / ~%%%"
  },
  {
    "id": "r6_18",
    "source": "1. - para\n  {#x}\n```\n",
    "expected": "<ol>\n<li>\n<ul>\n<li>para\n{<span class=\"tag\"><strong>#x</strong></span>}\n<code></code></li>\n</ul>\n</li>\n</ol>",
    "shape": "PHP-14 1~ - para / ~ATTR / ```"
  },
  {
    "id": "r6_19",
    "source": "- > para\n    %%%\nx\n",
    "expected": "<ul>\n<li>\n<blockquote><p>para</p></blockquote>\n</li>\n</ul>\n<p>x</p>",
    "shape": "PHP-15 - > para / ~%%% / T"
  },
  {
    "id": "r6_20",
    "source": "[r][] [^p] [^c] [^d]\n[^c]: 1. para\n   %%%\n   %%%\n         tail\n",
    "expected": "<p>[r][] [^p] <a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a> [^d]</p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n<hr>\n<ol>\n<li id=\"fn1\">\n<ol>\n<li>para\ntail\n</li>\n</ol>\n<p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n</li>\n</ol>\n</section>",
    "shape": "PHP-16 USE / [^c]: 1~ para / ~%%% / ~%%% / ~T"
  },
  {
    "id": "r6_23",
    "source": "[r][] [^p] [^c] [^d]\n[^c]: - para\n %%%\n %%%\n        tail\n",
    "expected": "<p>[r][] [^p] <a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a> [^d]</p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n<hr>\n<ol>\n<li id=\"fn1\">\n<ul>\n<li>para\ntail\n</li>\n</ul>\n<p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n</li>\n</ol>\n</section>",
    "shape": "PHP-19 USE / [^c]: - para / ~%%% / ~%%% / ~T"
  },
  {
    "id": "r6_24",
    "source": ":: t\n: :: t\n %%%\n",
    "expected": "<dl>\n<dt>t</dt>\n<dd>\n<dl>\n<dt>t\n</dt>\n</dl>\n</dd>\n</dl>",
    "shape": "PHP-20 :: t / : :: t / ~%%%"
  },
  {
    "id": "r6_25",
    "source": "- :: t\n  : para\n %%%\n %%%\nx\n",
    "expected": "<ul>\n<li>\n<dl>\n<dt>t</dt>\n<dd>\n<p>para</p>\n<p>x</p>\n</dd>\n</dl>\n</li>\n</ul>",
    "shape": "PHP-21 - :: t / ~: para / ~%%% / ~%%% / T"
  },
  {
    "id": "r6_28",
    "source": ":: t\n: :: t\n  : para\n   %%%\n   %%%\n    tail\n",
    "expected": "<dl>\n<dt>t</dt>\n<dd>\n<dl>\n<dt>t</dt>\n<dd>\n<p>para</p>\n<p>tail</p>\n</dd>\n</dl>\n</dd>\n</dl>",
    "shape": "PHP-24 :: t / : :: t / ~: para / ~%%% / ~%%% / ~T"
  },
  {
    "id": "r6_35",
    "source": "[r][] [^p] [^c] [^d]\n[^c]: :: t\n %%%\n %%%\ntail\n",
    "expected": "<p>[r][] [^p] <a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a> [^d]</p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n<hr>\n<ol>\n<li id=\"fn1\">\n<dl>\n<dt>t\ntail</dt>\n</dl>\n<p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n</li>\n</ol>\n</section>",
    "shape": "PHP-31 USE / [^c]: :: t / ~%%% / ~%%% / T"
  },
  {
    "id": "r6_36",
    "source": ":: t\n: ![a](x.png)\n^ cap\n",
    "expected": "<dl>\n<dt>t</dt>\n<dd><img src=\"x.png\" alt=\"a\">\n^ cap</dd>\n</dl>",
    "shape": "PHP-32 :: t / : ![a](T~png) / ^ cap"
  },
  {
    "id": "r6_37",
    "source": ":: t\n: > para\n^ cap\n",
    "expected": "<dl>\n<dt>t</dt>\n<dd>\n<blockquote><p>para\n^ cap</p></blockquote>\n</dd>\n</dl>",
    "shape": "PHP-33 :: t / : > para / ^ cap"
  }
]
JSON;
        $cases = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $converter = new CarveConverter();
        foreach ($cases as $case) {
            self::assertSame(
                preg_replace('/\s+/', '', $case['expected']),
                preg_replace('/\s+/', '', $converter->convert($case['source'])),
                $case['id'],
            );
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function commentColumnCases(): iterable
    {
        yield 's:item-1383' => [
            "[r][] [^p] [^c] [^d]\n\n- para\n  %% c\n```\nx\n",
            "<p>[r][] [^p] [^c] [^d]</p>\n<ul>\n<li>para</li>\n</ul>\n<pre><code>x\n</code></pre>",
        ];

        yield 's:item-1388' => [
            "[r][] [^p] [^c] [^d]\n\n- para\n   %% c\n```\nx\n",
            "<p>[r][] [^p] [^c] [^d]</p>\n<ul>\n<li>para</li>\n</ul>\n<pre><code>x\n</code></pre>",
        ];

        yield 's:item-1393' => [
            "[r][] [^p] [^c] [^d]\n\n- para\n      %% c\n```\nx\n",
            "<p>[r][] [^p] [^c] [^d]</p>\n<ul>\n<li>para</li>\n</ul>\n<pre><code>x\n</code></pre>",
        ];

        yield 's:item-1508' => [
            "[r][] [^p] [^c] [^d]\n\n- para\n  %%%\n  c\n  %%%\n```\nx\n",
            "<p>[r][] [^p] [^c] [^d]</p>\n<ul>\n<li>para</li>\n</ul>\n<pre><code>x\n</code></pre>",
        ];

        yield 's:item-1513' => [
            "[r][] [^p] [^c] [^d]\n\n- para\n   %%%\n   c\n   %%%\n```\nx\n",
            "<p>[r][] [^p] [^c] [^d]</p>\n<ul>\n<li>para</li>\n</ul>\n<pre><code>x\n</code></pre>",
        ];

        yield 's:item-1518' => [
            "[r][] [^p] [^c] [^d]\n\n- para\n      %%%\n      c\n      %%%\n```\nx\n",
            "<p>[r][] [^p] [^c] [^d]</p>\n<ul>\n<li>para</li>\n</ul>\n<pre><code>x\n</code></pre>",
        ];

        yield 's:item-1608' => [
            "[r][] [^p] [^c] [^d]\n\n- para\n  more\n  %% c\n```\nx\n",
            "<p>[r][] [^p] [^c] [^d]</p>\n<ul>\n<li>para\nmore</li>\n</ul>\n<pre><code>x\n</code></pre>",
        ];

        yield 's:item-1613' => [
            "[r][] [^p] [^c] [^d]\n\n- para\n  more\n   %% c\n```\nx\n",
            "<p>[r][] [^p] [^c] [^d]</p>\n<ul>\n<li>para\nmore</li>\n</ul>\n<pre><code>x\n</code></pre>",
        ];

        yield 's:item-1618' => [
            "[r][] [^p] [^c] [^d]\n\n- para\n  more\n      %% c\n```\nx\n",
            "<p>[r][] [^p] [^c] [^d]</p>\n<ul>\n<li>para\nmore</li>\n</ul>\n<pre><code>x\n</code></pre>",
        ];

        yield 's:item-1733' => [
            "[r][] [^p] [^c] [^d]\n\n- para\n  more\n  %%%\n  c\n  %%%\n```\nx\n",
            "<p>[r][] [^p] [^c] [^d]</p>\n<ul>\n<li>para\nmore</li>\n</ul>\n<pre><code>x\n</code></pre>",
        ];

        yield 's:item-1738' => [
            "[r][] [^p] [^c] [^d]\n\n- para\n  more\n   %%%\n   c\n   %%%\n```\nx\n",
            "<p>[r][] [^p] [^c] [^d]</p>\n<ul>\n<li>para\nmore</li>\n</ul>\n<pre><code>x\n</code></pre>",
        ];

        yield 's:item-1743' => [
            "[r][] [^p] [^c] [^d]\n\n- para\n  more\n      %%%\n      c\n      %%%\n```\nx\n",
            "<p>[r][] [^p] [^c] [^d]</p>\n<ul>\n<li>para\nmore</li>\n</ul>\n<pre><code>x\n</code></pre>",
        ];

        yield 's:ordered-1853' => [
            "[r][] [^p] [^c] [^d]\n\n1. para\n   %% c\n```\nx\n",
            "<p>[r][] [^p] [^c] [^d]</p>\n<ol>\n<li>para</li>\n</ol>\n<pre><code>x\n</code></pre>",
        ];

        yield 's:ordered-1858' => [
            "[r][] [^p] [^c] [^d]\n\n1. para\n    %% c\n```\nx\n",
            "<p>[r][] [^p] [^c] [^d]</p>\n<ol>\n<li>para</li>\n</ol>\n<pre><code>x\n</code></pre>",
        ];

        yield 's:ordered-1863' => [
            "[r][] [^p] [^c] [^d]\n\n1. para\n       %% c\n```\nx\n",
            "<p>[r][] [^p] [^c] [^d]</p>\n<ol>\n<li>para</li>\n</ol>\n<pre><code>x\n</code></pre>",
        ];

        yield 's:ordered-2003' => [
            "[r][] [^p] [^c] [^d]\n\n1. para\n   %%%\n   c\n   %%%\n```\nx\n",
            "<p>[r][] [^p] [^c] [^d]</p>\n<ol>\n<li>para</li>\n</ol>\n<pre><code>x\n</code></pre>",
        ];

        yield 's:ordered-2008' => [
            "[r][] [^p] [^c] [^d]\n\n1. para\n    %%%\n    c\n    %%%\n```\nx\n",
            "<p>[r][] [^p] [^c] [^d]</p>\n<ol>\n<li>para</li>\n</ol>\n<pre><code>x\n</code></pre>",
        ];

        yield 's:ordered-2013' => [
            "[r][] [^p] [^c] [^d]\n\n1. para\n       %%%\n       c\n       %%%\n```\nx\n",
            "<p>[r][] [^p] [^c] [^d]</p>\n<ol>\n<li>para</li>\n</ol>\n<pre><code>x\n</code></pre>",
        ];

        yield 's:ordered-2123' => [
            "[r][] [^p] [^c] [^d]\n\n1. para\n   more\n   %% c\n```\nx\n",
            "<p>[r][] [^p] [^c] [^d]</p>\n<ol>\n<li>para\nmore</li>\n</ol>\n<pre><code>x\n</code></pre>",
        ];

        yield 's:ordered-2128' => [
            "[r][] [^p] [^c] [^d]\n\n1. para\n   more\n    %% c\n```\nx\n",
            "<p>[r][] [^p] [^c] [^d]</p>\n<ol>\n<li>para\nmore</li>\n</ol>\n<pre><code>x\n</code></pre>",
        ];

        yield 's:ordered-2133' => [
            "[r][] [^p] [^c] [^d]\n\n1. para\n   more\n       %% c\n```\nx\n",
            "<p>[r][] [^p] [^c] [^d]</p>\n<ol>\n<li>para\nmore</li>\n</ol>\n<pre><code>x\n</code></pre>",
        ];

        yield 's:ordered-2273' => [
            "[r][] [^p] [^c] [^d]\n\n1. para\n   more\n   %%%\n   c\n   %%%\n```\nx\n",
            "<p>[r][] [^p] [^c] [^d]</p>\n<ol>\n<li>para\nmore</li>\n</ol>\n<pre><code>x\n</code></pre>",
        ];

        yield 's:ordered-2278' => [
            "[r][] [^p] [^c] [^d]\n\n1. para\n   more\n    %%%\n    c\n    %%%\n```\nx\n",
            "<p>[r][] [^p] [^c] [^d]</p>\n<ol>\n<li>para\nmore</li>\n</ol>\n<pre><code>x\n</code></pre>",
        ];

        yield 's:ordered-2283' => [
            "[r][] [^p] [^c] [^d]\n\n1. para\n   more\n       %%%\n       c\n       %%%\n```\nx\n",
            "<p>[r][] [^p] [^c] [^d]</p>\n<ol>\n<li>para\nmore</li>\n</ol>\n<pre><code>x\n</code></pre>",
        ];

        yield 'n:ordered/footnote-3255' => [
            "[r][] [^p] [^c] [^d]\n\n1. [^c]: para\n   %% c\n     tail\n",
            "<p>[r][] [^p] <a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a> [^d]</p>\n<ol>\n<li></li>\n</ol>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n<hr>\n<ol>\n<li id=\"fn1\">\n<p>para</p>\n<p>tail<a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n</li>\n</ol>\n</section>",
        ];

        yield 'n:item/quote-3948' => [
            "[r][] [^p] [^c] [^d]\n\n- > para\n    more\n    %% c\n```\nx\n",
            "<p>[r][] [^p] [^c] [^d]</p>\n<ul>\n<li>\n<blockquote><p>para\nmore</p></blockquote>\n</li>\n</ul>\n<pre><code>x\n</code></pre>",
        ];

        yield 'n:item/quote-3949' => [
            "[r][] [^p] [^c] [^d]\n\n- > para\n    more\n    %%%\n    c\n    %%%\n```\nx\n",
            "<p>[r][] [^p] [^c] [^d]</p>\n<ul>\n<li>\n<blockquote><p>para\nmore</p></blockquote>\n</li>\n</ul>\n<pre><code>x\n</code></pre>",
        ];

        yield 'n:ordered/quote-4589' => [
            "[r][] [^p] [^c] [^d]\n\n1. > para\n         %% c\n```\nx\n",
            "<p>[r][] [^p] [^c] [^d]</p>\n<ol>\n<li>\n<blockquote><p>para</p></blockquote>\n</li>\n</ol>\n<pre><code>x\n</code></pre>",
        ];

        yield 'n:ordered/quote-4590' => [
            "[r][] [^p] [^c] [^d]\n\n1. > para\n         %%%\n         c\n         %%%\n```\nx\n",
            "<p>[r][] [^p] [^c] [^d]</p>\n<ol>\n<li>\n<blockquote><p>para</p></blockquote>\n</li>\n</ol>\n<pre><code>x\n</code></pre>",
        ];

        yield 'n:ordered/quote-5643' => [
            "[r][] [^p] [^c] [^d]\n\n1. > para\n    %% c\n```\nx\n",
            "<p>[r][] [^p] [^c] [^d]</p>\n<ol>\n<li>\n<blockquote><p>para</p></blockquote>\n</li>\n</ol>\n<pre><code>x\n</code></pre>",
        ];

        yield 'n:ordered/quote-5644' => [
            "[r][] [^p] [^c] [^d]\n\n1. > para\n    %%%\n    c\n    %%%\n```\nx\n",
            "<p>[r][] [^p] [^c] [^d]</p>\n<ol>\n<li>\n<blockquote><p>para</p></blockquote>\n</li>\n</ol>\n<pre><code>x\n</code></pre>",
        ];

        yield 'n:ordered/quote-5755' => [
            "[r][] [^p] [^c] [^d]\n\n1. > para\n     more\n    %% c\n```\nx\n",
            "<p>[r][] [^p] [^c] [^d]</p>\n<ol>\n<li>\n<blockquote><p>para\nmore</p></blockquote>\n</li>\n</ol>\n<pre><code>x\n</code></pre>",
        ];

        yield 'n:ordered/quote-5756' => [
            "[r][] [^p] [^c] [^d]\n\n1. > para\n     more\n    %%%\n    c\n    %%%\n```\nx\n",
            "<p>[r][] [^p] [^c] [^d]</p>\n<ol>\n<li>\n<blockquote><p>para\nmore</p></blockquote>\n</li>\n</ol>\n<pre><code>x\n</code></pre>",
        ];

        yield 'n:ordered/footnote-6060' => [
            "[r][] [^p] [^c] [^d]\n\n1. [^c]: para\n     more\n   %% c\n```\nx\n",
            "<p>[r][] [^p] <a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a> [^d]</p>\n<ol>\n<li></li>\n</ol>\n<pre><code>x\n</code></pre>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n<hr>\n<ol>\n<li id=\"fn1\">\n<p>para\nmore<a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n</li>\n</ol>\n</section>",
        ];

        yield 'n:ordered/footnote-6061' => [
            "[r][] [^p] [^c] [^d]\n\n1. [^c]: para\n     more\n   %%%\n   c\n   %%%\n```\nx\n",
            "<p>[r][] [^p] <a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a> [^d]</p>\n<ol>\n<li></li>\n</ol>\n<pre><code>x\n</code></pre>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n<hr>\n<ol>\n<li id=\"fn1\">\n<p>para\nmore<a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n</li>\n</ol>\n</section>",
        ];

        yield 'n:footnote/desc-6729' => [
            "[r][] [^p] [^c] [^d]\n\n[^c]: :: t\n      : para\n        more\n %% c\ntail\n",
            "<p>[r][] [^p] <a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a> [^d]</p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n<hr>\n<ol>\n<li id=\"fn1\">\n<dl>\n<dt>t\n: para\nmore\ntail</dt>\n</dl>\n<p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n</li>\n</ol>\n</section>",
        ];
    }

    #[DataProvider('commentColumnCases')]
    public function testCommentColumn(string $source, string $expected): void
    {
        self::assertSame(
            preg_replace('/\s+/', '', $expected),
            preg_replace('/\s+/', '', (new CarveConverter())->convert($source)),
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function heldListFenceCases(): iterable
    {
        yield 'r6_00' => [
            "1. para\n %% c\n```\n",
            "<ol>\n<li>para\n<pre><code></code></pre>\n</li>\n</ol>",
        ];

        yield 'r6_01' => [
            "1. para\n  %% c\n```\n",
            "<ol>\n<li>para\n<pre><code></code></pre>\n</li>\n</ol>",
        ];

        yield 'r6_02' => [
            "1. para\n %%%\n %%%\n```\n",
            "<ol>\n<li>para\n<pre><code></code></pre>\n</li>\n</ol>",
        ];

        yield 'r6_03' => [
            "1. para\n  %%%\n  %%%\n```\n",
            "<ol>\n<li>para\n<pre><code></code></pre>\n</li>\n</ol>",
        ];

        yield 'r6_04' => [
            "- para\n%% c\n```\n",
            "<ul>\n<li>para\n<pre><code></code></pre>\n</li>\n</ul>",
        ];

        yield 'r6_09' => [
            "- para\n %% c\n```\n",
            "<ul>\n<li>para\n<pre><code></code></pre>\n</li>\n</ul>",
        ];

        yield 'r6_10' => [
            "- para\n %%%\n %%%\n```\n",
            "<ul>\n<li>para\n<pre><code></code></pre>\n</li>\n</ul>",
        ];

        yield 'r6_11' => [
            "1. para\n%% c\n```\n",
            "<ol>\n<li>para\n<pre><code></code></pre>\n</li>\n</ol>",
        ];

        yield 'r6_21' => [
            "- > para\n%% c\n```\n",
            "<ul>\n<li>\n<blockquote><p>para</p></blockquote>\n<pre><code></code></pre>\n</li>\n</ul>",
        ];

        yield 'r6_22' => [
            "1. > para\n%% c\n```\n",
            "<ol>\n<li>\n<blockquote><p>para</p></blockquote>\n<pre><code></code></pre>\n</li>\n</ol>",
        ];

        yield 'r6_26' => [
            "- - para\n %% c\n```\n",
            "<ul>\n<li>\n<ul>\n<li>para\n<pre><code></code></pre>\n</li>\n</ul>\n</li>\n</ul>",
        ];

        yield 'r6_27' => [
            "- - para\n %%%\n %%%\n```\n",
            "<ul>\n<li>\n<ul>\n<li>para\n<pre><code></code></pre>\n</li>\n</ul>\n</li>\n</ul>",
        ];

        yield 'r6_29' => [
            "- ::: note\n  more\n %% c\n```\n",
            "<ul>\n<li>\n<aside class=\"admonition note\" aria-label=\"Note\">\n<p>more</p>\n<pre><code></code></pre>\n</aside>\n</li>\n</ul>",
        ];

        yield 'r6_30' => [
            "- ::: note\n  more\n %%%\n```\n",
            "<ul>\n<li>\n<aside class=\"admonition note\" aria-label=\"Note\">\n<p>more</p>\n<pre><code></code></pre>\n</aside>\n</li>\n</ul>",
        ];

        yield 'r6_31' => [
            "1. > para\n %% c\n```\n",
            "<ol>\n<li>\n<blockquote><p>para</p></blockquote>\n<pre><code></code></pre>\n</li>\n</ol>",
        ];

        yield 'r6_32' => [
            "1. > para\n %%%\n %%%\n```\n",
            "<ol>\n<li>\n<blockquote><p>para</p></blockquote>\n<pre><code></code></pre>\n</li>\n</ol>",
        ];

        yield 'r6_33' => [
            "[r][] [^p] [^c] [^d]\n[^c]: 1. para\n %% c\n```\nx\n",
            "<p>[r][] [^p] <a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a> [^d]</p>\n<p>x</p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n<hr>\n<ol>\n<li id=\"fn1\">\n<ol>\n<li>para\n<pre><code></code></pre>\n</li>\n</ol>\n<p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n</li>\n</ol>\n</section>",
        ];

        yield 'r6_34' => [
            "[r][] [^p] [^c] [^d]\n[^c]: 1. para\n %%%\n %%%\n```\n",
            "<p>[r][] [^p] <a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a> [^d]</p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n<hr>\n<ol>\n<li id=\"fn1\">\n<ol>\n<li>para\n<pre><code></code></pre>\n</li>\n</ol>\n<p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n</li>\n</ol>\n</section>",
        ];
    }

    #[DataProvider('heldListFenceCases')]
    public function testHeldListFence(string $source, string $expected): void
    {
        self::assertSame(
            preg_replace('/\s+/', '', $expected),
            preg_replace('/\s+/', '', (new CarveConverter())->convert($source)),
        );
    }

    public function testNoteHostedTermKeepsCommentBreak(): void
    {
        $html = (new CarveConverter())->convert("[r][] [^p] [^c] [^d]\n[^c]: :: t\n %% c\n");
        self::assertStringContainsString("<dt>t\n</dt>", $html);
    }

    public function testCaptionAtDescriptionBodyColumnAttaches(): void
    {
        $converter = new CarveConverter();
        self::assertSame(
            preg_replace('/\s+/', '', '<dl><dt>t</dt><dd><figure><img src="x.png" alt="a"><figcaption>cap</figcaption></figure></dd></dl>'),
            preg_replace('/\s+/', '', $converter->convert(":: t\n: ![a](x.png)\n  ^ cap\n")),
        );
        self::assertSame(
            '<dl><dt>t</dt><dd><figure><blockquote><p>para</p></blockquote><figcaption>cap</figcaption></figure></dd></dl>',
            preg_replace('/\s+/', '', $converter->convert(":: t\n: > para\n  ^ cap\n")),
        );
    }
}
