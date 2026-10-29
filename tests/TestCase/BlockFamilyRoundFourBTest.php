<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Test\RuleATodo;
use PHPUnit\Framework\TestCase;

class BlockFamilyRoundFourBTest extends TestCase
{
    use RuleATodo;

    public function testDescriptionColumnRulings(): void
    {
        // These cases cover the description column and nested block readings.
        $json = <<<'JSON'
[
  {
    "id": "r4_000",
    "source": ":: t\n: para\n # H\n",
    "expected": "<dl>\n<dt>t</dt>\n<dd>para\n# H</dd>\n</dl>"
  },
  {
    "id": "r4_001",
    "source": ":: t\n: para\n ---\n",
    "expected": "<dl>\n<dt>t</dt>\n<dd>para\n—</dd>\n</dl>"
  },
  {
    "id": "r4_002",
    "source": ":: t\n: para\n | a |\n",
    "expected": "<dl>\n<dt>t</dt>\n<dd>para\n| a |</dd>\n</dl>"
  },
  {
    "id": "r4_011",
    "source": ":: t\n: > para\n # H\n",
    "expected": "<dl>\n<dt>t</dt>\n<dd>\n<blockquote><p>para\n# H</p></blockquote>\n</dd>\n</dl>"
  },
  {
    "id": "r4_012",
    "source": ":: t\n: > para\n ---\n",
    "expected": "<dl>\n<dt>t</dt>\n<dd>\n<blockquote><p>para\n—</p></blockquote>\n</dd>\n</dl>"
  },
  {
    "id": "r4_013",
    "source": ":: t\n: > para\n | a |\n",
    "expected": "<dl>\n<dt>t</dt>\n<dd>\n<blockquote><p>para\n| a |</p></blockquote>\n</dd>\n</dl>"
  },
  {
    "id": "r4_014",
    "source": ":: t\n: - para\n ---\n",
    "expected": "<dl>\n<dt>t</dt>\n<dd>\n<ul>\n<li>para\n—</li>\n</ul>\n</dd>\n</dl>"
  },
  {
    "id": "r4_015",
    "source": ":: t\n: - para\n # H\n",
    "expected": "<dl>\n<dt>t</dt>\n<dd>\n<ul>\n<li>para\n# H</li>\n</ul>\n</dd>\n</dl>"
  },
  {
    "id": "r4_016",
    "source": ":: t\n: - para\n | a |\n",
    "expected": "<dl>\n<dt>t</dt>\n<dd>\n<ul>\n<li>para\n| a |</li>\n</ul>\n</dd>\n</dl>"
  },
  {
    "id": "r4_017",
    "source": ":: t\n: 1. para\n # H\n",
    "expected": "<dl>\n<dt>t</dt>\n<dd>\n<ol>\n<li>para\n# H</li>\n</ol>\n</dd>\n</dl>"
  },
  {
    "id": "r4_018",
    "source": ":: t\n: 1. para\n ---\n",
    "expected": "<dl>\n<dt>t</dt>\n<dd>\n<ol>\n<li>para\n—</li>\n</ol>\n</dd>\n</dl>"
  },
  {
    "id": "r4_019",
    "source": ":: t\n: 1. para\n | a |\n",
    "expected": "<dl>\n<dt>t</dt>\n<dd>\n<ol>\n<li>para\n| a |</li>\n</ol>\n</dd>\n</dl>"
  },
  {
    "id": "r4_020",
    "source": ":: t\n: :: t\n # H\n",
    "expected": "<dl>\n<dt>t</dt>\n<dd>\n<dl>\n<dt>t\n# H</dt>\n</dl>\n</dd>\n</dl>"
  },
  {
    "id": "r4_021",
    "source": ":: t\n: :: t\n ---\n",
    "expected": "<dl>\n<dt>t</dt>\n<dd>\n<dl>\n<dt>t\n—</dt>\n</dl>\n</dd>\n</dl>"
  },
  {
    "id": "r4_022",
    "source": ":: t\n: :: t\n | a |\n",
    "expected": "<dl>\n<dt>t</dt>\n<dd>\n<dl>\n<dt>t\n| a |</dt>\n</dl>\n</dd>\n</dl>"
  },
  {
    "id": "r4_023",
    "source": ":: t\n: ::: note\n  para\n ---\n",
    "expected": "<dl>\n<dt>t</dt>\n<dd>\n<aside class=\"admonition note\" aria-label=\"Note\">\n<p>para\n—</p>\n</aside>\n</dd>\n</dl>"
  },
  {
    "id": "r4_024",
    "source": ":: t\n: ::: note\n  para\n | a |\n",
    "expected": "<dl>\n<dt>t</dt>\n<dd>\n<aside class=\"admonition note\" aria-label=\"Note\">\n<p>para\n| a |</p>\n</aside>\n</dd>\n</dl>"
  },
  {
    "id": "r4_025",
    "source": ":: t\n: ::: note\n  more\n ---\n",
    "expected": "<dl>\n<dt>t</dt>\n<dd>\n<aside class=\"admonition note\" aria-label=\"Note\">\n<p>more\n—</p>\n</aside>\n</dd>\n</dl>"
  },
  {
    "id": "r4_026",
    "source": ":: t\n: ::: note\n  more\n | a |\n",
    "expected": "<dl>\n<dt>t</dt>\n<dd>\n<aside class=\"admonition note\" aria-label=\"Note\">\n<p>more\n| a |</p>\n</aside>\n</dd>\n</dl>"
  },
  {
    "id": "r4_027",
    "source": "> :: t\n> : para\n>  # H\n",
    "expected": "<blockquote>\n<dl>\n<dt>t</dt>\n<dd>para\n# H</dd>\n</dl>\n</blockquote>"
  },
  {
    "id": "r4_028",
    "source": "> :: t\n> : para\n>  | a |\n",
    "expected": "<blockquote>\n<dl>\n<dt>t</dt>\n<dd>para\n| a |</dd>\n</dl>\n</blockquote>"
  },
  {
    "id": "r4_029",
    "source": ":: t\n:  para\n  # H\n",
    "expected": "<dl>\n<dt>t</dt>\n<dd>para\n# H</dd>\n</dl>"
  },
  {
    "id": "r4_030",
    "source": ":: t\n:  para\n  ---\n",
    "expected": "<dl>\n<dt>t</dt>\n<dd>para\n—</dd>\n</dl>"
  },
  {
    "id": "r4_031",
    "source": ":: t\n:  para\n  | a |\n",
    "expected": "<dl>\n<dt>t</dt>\n<dd>para\n| a |</dd>\n</dl>"
  },
  {
    "id": "r4_046",
    "source": ":: t\n:  para\n > q\n",
    "expected": "<dl>\n<dt>t</dt>\n<dd>para\n&gt; q</dd>\n</dl>"
  },
  {
    "id": "r4_047",
    "source": ":: t\n: para\n > q\n",
    "expected": "<dl>\n<dt>t</dt>\n<dd>para\n&gt; q</dd>\n</dl>"
  },
  {
    "id": "n:desc/quote-3031",
    "source": "[r][] [^p] [^c] [^d]\n\n:: t\n: > para\n---\n```\nx\n",
    "expected": "<p>[r][] [^p] [^c] [^d]</p>\n<dl>\n<dt>t</dt>\n<dd>\n<blockquote><p>para</p></blockquote>\n</dd>\n</dl>\n<hr>\n<pre><code>x\n</code></pre>"
  },
  {
    "id": "n:desc/quote-3321",
    "source": "[r][] [^p] [^c] [^d]\n\n:: t\n: > para\n    more\n| a |\n```\nx\n",
    "expected": "<p>[r][] [^p] [^c] [^d]</p>\n<dl>\n<dt>t</dt>\n<dd>\n<blockquote><p>para\nmore</p></blockquote>\n</dd>\n</dl>\n<table>\n<tbody>\n<tr><td>a</td></tr>\n</tbody>\n</table>\n<pre><code>x\n</code></pre>"
  },
  {
    "id": "n:desc/quote-3823",
    "source": "[r][] [^p] [^c] [^d]\n\n:: t\n: > para\n| a |\n```\nx\n",
    "expected": "<p>[r][] [^p] [^c] [^d]</p>\n<dl>\n<dt>t</dt>\n<dd>\n<blockquote><p>para</p></blockquote>\n</dd>\n</dl>\n<table>\n<tbody>\n<tr><td>a</td></tr>\n</tbody>\n</table>\n<pre><code>x\n</code></pre>"
  },
  {
    "id": "n:desc/quote-3956",
    "source": "[r][] [^p] [^c] [^d]\n\n:: t\n: > para\n| a |\n    tail\n",
    "expected": "<p>[r][] [^p] [^c] [^d]</p>\n<dl>\n<dt>t</dt>\n<dd>\n<blockquote><p>para</p></blockquote>\n</dd>\n</dl>\n<table>\n<tbody>\n<tr><td>a</td></tr>\n</tbody>\n</table>\n<p>tail</p>"
  },
  {
    "id": "n:desc/quote-4067",
    "source": "[r][] [^p] [^c] [^d]\n\n:: t\n: > para\n| a |\n\ntail\n",
    "expected": "<p>[r][] [^p] [^c] [^d]</p>\n<dl>\n<dt>t</dt>\n<dd>\n<blockquote><p>para</p></blockquote>\n</dd>\n</dl>\n<table>\n<tbody>\n<tr><td>a</td></tr>\n</tbody>\n</table>\n<p>tail</p>"
  },
  {
    "id": "n:desc/quote-4345",
    "source": "[r][] [^p] [^c] [^d]\n\n:: t\n: > para\n    more\n---\n    tail\n",
    "expected": "<p>[r][] [^p] [^c] [^d]</p>\n<dl>\n<dt>t</dt>\n<dd>\n<blockquote><p>para\nmore</p></blockquote>\n</dd>\n</dl>\n<hr>\n<p>tail</p>"
  },
  {
    "id": "n:desc/quote-4510",
    "source": "[r][] [^p] [^c] [^d]\n\n:: t\n: > para\n    more\n| a |\n    tail\n",
    "expected": "<p>[r][] [^p] [^c] [^d]</p>\n<dl>\n<dt>t</dt>\n<dd>\n<blockquote><p>para\nmore</p></blockquote>\n</dd>\n</dl>\n<table>\n<tbody>\n<tr><td>a</td></tr>\n</tbody>\n</table>\n<p>tail</p>"
  },
  {
    "id": "n:desc/quote-4636",
    "source": "[r][] [^p] [^c] [^d]\n\n:: t\n: > para\n  > more\n---\ntail\n",
    "expected": "<p>[r][] [^p] [^c] [^d]</p>\n<dl>\n<dt>t</dt>\n<dd>\n<blockquote><p>para\nmore</p></blockquote>\n</dd>\n</dl>\n<hr>\n<p>tail</p>"
  },
  {
    "id": "n:desc/quote-4895",
    "source": "[r][] [^p] [^c] [^d]\n\n:: t\n: > para\n# H\ntail\n",
    "expected": "<p>[r][] [^p] [^c] [^d]</p>\n<dl>\n<dt>t</dt>\n<dd>\n<blockquote><p>para</p></blockquote>\n</dd>\n</dl>\n<section id=\"H\">\n<h1>H</h1>\n<p>tail</p>\n</section>"
  },
  {
    "id": "n:desc/quote-5223",
    "source": "[r][] [^p] [^c] [^d]\n\n:: t\n: > para\n# H\n\ntail\n",
    "expected": "<p>[r][] [^p] [^c] [^d]</p>\n<dl>\n<dt>t</dt>\n<dd>\n<blockquote><p>para</p></blockquote>\n</dd>\n</dl>\n<section id=\"H\">\n<h1>H</h1>\n<p>tail</p>\n</section>"
  },
  {
    "id": "n:desc/quote-5607",
    "source": "[r][] [^p] [^c] [^d]\n\n:: t\n: > para\n  > more\n| a |\n\ntail\n",
    "expected": "<p>[r][] [^p] [^c] [^d]</p>\n<dl>\n<dt>t</dt>\n<dd>\n<blockquote><p>para\nmore</p></blockquote>\n</dd>\n</dl>\n<table>\n<tbody>\n<tr><td>a</td></tr>\n</tbody>\n</table>\n<p>tail</p>"
  },
  {
    "id": "n:desc/quote-5875",
    "source": "[r][] [^p] [^c] [^d]\n\n:: t\n: > para\n| a |\n::::\ntail\n",
    "expected": "<p>[r][] [^p] [^c] [^d]</p>\n<dl>\n<dt>t</dt>\n<dd>\n<blockquote><p>para</p></blockquote>\n</dd>\n</dl>\n<table>\n<tbody>\n<tr><td>a</td></tr>\n</tbody>\n</table>\n<div>\n<p>tail</p>\n</div>"
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
