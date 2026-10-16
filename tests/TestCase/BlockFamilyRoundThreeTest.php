<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

class BlockFamilyRoundThreeTest extends TestCase
{
    public function testGeneratedCases(): void
    {
        // Keep each source beside its expected HTML so failures name the generated case.
        $json = <<<'JSON'
[
  {
    "id": "r3_011",
    "source": "[r][] [^p] [^c] [^d]\n[^c]: :: t\n      : para\n   [^p]: n\n",
    "expected": "<p>[r][] [^p] <a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a> [^d]</p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n<hr>\n<ol>\n<li id=\"fn1\">\n<dl>\n<dt>t\n: para\n[^p]: n</dt>\n</dl>\n<p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n</li>\n</ol>\n</section>"
  },
  {
    "id": "r3_012",
    "source": "[r][] [^p] [^c] [^d]\n[^c]: :: t\n      : para\n       [^p]: n\n",
    "expected": "<p>[r][] [^p] <a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a> [^d]</p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n<hr>\n<ol>\n<li id=\"fn1\">\n<dl>\n<dt>t\n: para\n[^p]: n</dt>\n</dl>\n<p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n</li>\n</ol>\n</section>"
  },
  {
    "id": "r3_013",
    "source": "[r][] [^p] [^c] [^d]\n[^c]: :: t\n      : para\n         [^p]: n\n",
    "expected": "<p>[r][] [^p] <a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a> [^d]</p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n<hr>\n<ol>\n<li id=\"fn1\">\n<dl>\n<dt>t\n: para\n[^p]: n</dt>\n</dl>\n<p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n</li>\n</ol>\n</section>"
  },
  {
    "id": "r3_014",
    "source": "[r][] [^p] [^c] [^d]\n[^c]: :: t\n      : para\n            [^p]: n\n",
    "expected": "<p>[r][] [^p] <a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a> [^d]</p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n<hr>\n<ol>\n<li id=\"fn1\">\n<dl>\n<dt>t\n: para\n[^p]: n</dt>\n</dl>\n<p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n</li>\n</ol>\n</section>"
  },
  {
    "id": "r3_015",
    "source": "[r][] [^p] [^c] [^d]\n\n[^c]: :: t\n      : para\n   [r]: /u\n",
    "expected": "<p>[r][] [^p] <a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a> [^d]</p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n<hr>\n<ol>\n<li id=\"fn1\">\n<dl>\n<dt>t\n: para\n[r]: /u</dt>\n</dl>\n<p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n</li>\n</ol>\n</section>"
  },
  {
    "id": "r3_016",
    "source": "[r][] [^p] [^c] [^d]\n\n[^c]: :: t\n      : para\n        [r]: /u\n",
    "expected": "<p>[r][] [^p] <a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a> [^d]</p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n<hr>\n<ol>\n<li id=\"fn1\">\n<dl>\n<dt>t\n: para\n[r]: /u</dt>\n</dl>\n<p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n</li>\n</ol>\n</section>"
  },
  {
    "id": "r3_017",
    "source": "[r][] [^p] [^c] [^d]\n\n[^c]: :: t\n      : para\n         [r]: /u\n",
    "expected": "<p>[r][] [^p] <a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a> [^d]</p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n<hr>\n<ol>\n<li id=\"fn1\">\n<dl>\n<dt>t\n: para\n[r]: /u</dt>\n</dl>\n<p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n</li>\n</ol>\n</section>"
  },
  {
    "id": "r3_018",
    "source": "[r][] [^p] [^c] [^d]\n\n[^c]: :: t\n      : para\n            [r]: /u\n",
    "expected": "<p>[r][] [^p] <a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a> [^d]</p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n<hr>\n<ol>\n<li id=\"fn1\">\n<dl>\n<dt>t\n: para\n[r]: /u</dt>\n</dl>\n<p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n</li>\n</ol>\n</section>"
  },
  {
    "id": "r3_022",
    "source": "[r][] [^p] [^c] [^d]\n:: t\n: [^c]: para\n     [^p]: n\n",
    "expected": "<p>[r][] <a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a> <a id=\"fnref2\" href=\"#fn2\" role=\"doc-noteref\"><sup>2</sup></a> [^d]</p>\n<dl>\n<dt>t</dt>\n<dd></dd>\n</dl>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n<hr>\n<ol>\n<li id=\"fn1\">\n<p>n<a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n</li>\n<li id=\"fn2\">\n<p>para<a href=\"#fnref2\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n</li>\n</ol>\n</section>"
  },
  {
    "id": "r3_023",
    "source": "[r][] [^p] [^c] [^d]\n:: t\n: [^c]: para\n        [^p]: n\n",
    "expected": "<p>[r][] <a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a> <a id=\"fnref2\" href=\"#fn2\" role=\"doc-noteref\"><sup>2</sup></a> [^d]</p>\n<dl>\n<dt>t</dt>\n<dd></dd>\n</dl>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n<hr>\n<ol>\n<li id=\"fn1\">\n<p>n<a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n</li>\n<li id=\"fn2\">\n<p>para<a href=\"#fnref2\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n</li>\n</ol>\n</section>"
  },
  {
    "id": "r3_037",
    "source": ":: t\n: para\n%% c\n```\nx\n",
    "expected": "<dl>\n<dt>t</dt>\n<dd>\n<p>para</p>\n<pre><code></code></pre>\n</dd>\n</dl>\n<p>x</p>"
  },
  {
    "id": "r3_038",
    "source": ":: t\n: para\n %% c\n```\nx\n",
    "expected": "<dl>\n<dt>t</dt>\n<dd>\n<p>para</p>\n<pre><code></code></pre>\n</dd>\n</dl>\n<p>x</p>"
  },
  {
    "id": "r3_046",
    "source": ":: t\n: [^c]: para\n   ---\n",
    "expected": "<dl>\n<dt>t</dt>\n<dd>\n<hr>\n</dd>\n</dl>"
  },
  {
    "id": "r3_047",
    "source": ":: t\n: [^c]: para\n   | a |\n",
    "expected": "<dl>\n<dt>t</dt>\n<dd>\n<table>\n<tbody>\n<tr><td>a</td></tr>\n</tbody>\n</table>\n</dd>\n</dl>"
  },
  {
    "id": "r3_059",
    "source": "1. ::: note\n   more\n    # H\ntail\n",
    "expected": "<ol>\n<li>\n<aside class=\"admonition note\" aria-label=\"Note\">\n<p>more</p>\n<h1 id=\"H\">H</h1>\n</aside>\n</li>\n</ol>\n<p>tail</p>"
  },
  {
    "id": "r3_060",
    "source": ":: t\n: 1. para\n   {#x}\ntail\n",
    "expected": "<dl>\n<dt>t</dt>\n<dd>\n<ol>\n<li>para\n{<span class=\"tag\"><strong>#x</strong></span>}\ntail</li>\n</ol>\n</dd>\n</dl>"
  },
  {
    "id": "r3_062",
    "source": ":: t\n: ::: note\n  para\n%% c\n```\nx\n",
    "expected": "<dl>\n<dt>t</dt>\n<dd>\n<aside class=\"admonition note\" aria-label=\"Note\">\n<p>para</p>\n<pre><code></code></pre>\n</aside>\n</dd>\n</dl>\n<p>x</p>"
  },
  {
    "id": "r3_063",
    "source": "1. :: t\n   : para\n%% c\n```\n",
    "expected": "<ol>\n<li>\n<dl>\n<dt>t</dt>\n<dd>\n<p>para</p>\n<pre><code></code></pre>\n</dd>\n</dl>\n</li>\n</ol>"
  },
  {
    "id": "r3_069",
    "source": "- :: t\n  : para\n %% c\n```\n",
    "expected": "<ul>\n<li>\n<dl>\n<dt>t</dt>\n<dd>\n<p>para</p>\n<pre><code></code></pre>\n</dd>\n</dl>\n</li>\n</ul>"
  },
  {
    "id": "r3_070",
    "source": ":: t\n: - para\n   {#x}\ntail\n",
    "expected": "<dl>\n<dt>t</dt>\n<dd>\n<ul>\n<li>para\n{<span class=\"tag\"><strong>#x</strong></span>}\ntail</li>\n</ul>\n</dd>\n</dl>"
  },
{
  "id": "n:desc/footnote-3340",
  "source": "[r][] [^p] [^c] [^d]\n\n:: t\n: [^c]: para\n    more\n   - li\ntail\n",
  "expected": "<p>[r][] [^p] <a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a> [^d]</p>\n<dl>\n<dt>t</dt>\n<dd>\n<ul>\n<li>li\ntail</li>\n</ul>\n</dd>\n</dl>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n<hr>\n<ol>\n<li id=\"fn1\">\n<p>para\nmore<a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n</li>\n</ol>\n</section>"
},
{
  "id": "n:desc/footnote-5960",
  "source": "[r][] [^p] [^c] [^d]\n\n:: t\n: [^c]: para\n    more\n   - li\n```\nx\n",
  "expected": "<p>[r][] [^p] <a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a> [^d]</p>\n<dl>\n<dt>t</dt>\n<dd>\n<ul>\n<li>li\n<code>\nx</code></li>\n</ul>\n</dd>\n</dl>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n<hr>\n<ol>\n<li id=\"fn1\">\n<p>para\nmore<a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n</li>\n</ol>\n</section>"
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
}
