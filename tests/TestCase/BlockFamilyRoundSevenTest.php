<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Test\RuleATodo;
use PHPUnit\Framework\TestCase;

class BlockFamilyRoundSevenTest extends TestCase
{
    use RuleATodo;

    public function testRoundSevenCases(): void
    {
        $json = <<<'JSON'
[
  {
    "id": "r7_00",
    "source": "- :: t\n  : para\n   %% c\n```\n",
    "expected": "<ul>\n<li>\n<dl>\n<dt>t</dt>\n<dd>para</dd>\n</dl>\n</li>\n</ul>\n<pre><code></code></pre>",
    "shape": "PHP-1 - :: t / ~: para / ~CMT / ```"
  },
  {
    "id": "r7_01",
    "source": "- :: t\n  : para\n     %% c\n```\n",
    "expected": "<ul>\n<li>\n<dl>\n<dt>t</dt>\n<dd>para</dd>\n</dl>\n</li>\n</ul>\n<pre><code></code></pre>",
    "shape": "PHP-1 - :: t / ~: para / ~CMT / ```"
  },
  {
    "id": "r7_02",
    "source": "- :: t\n  : para\n        %% c\n```\n",
    "expected": "<ul>\n<li>\n<dl>\n<dt>t</dt>\n<dd>para</dd>\n</dl>\n</li>\n</ul>\n<pre><code></code></pre>",
    "shape": "PHP-1 - :: t / ~: para / ~CMT / ```"
  },
  {
    "id": "r7_03",
    "source": "- :: t\n  : para\n   %%%\n```\n",
    "expected": "<ul>\n<li>\n<dl>\n<dt>t</dt>\n<dd>para</dd>\n</dl>\n</li>\n</ul>\n<pre><code></code></pre>",
    "shape": "PHP-2 - :: t / ~: para / ~%%% / ```"
  },
  {
    "id": "r7_04",
    "source": "- :: t\n  : para\n     %%%\n```\n",
    "expected": "<ul>\n<li>\n<dl>\n<dt>t</dt>\n<dd>para</dd>\n</dl>\n</li>\n</ul>\n<pre><code></code></pre>",
    "shape": "PHP-2 - :: t / ~: para / ~%%% / ```"
  },
  {
    "id": "r7_05",
    "source": "- :: t\n  : para\n        %%%\n```\n",
    "expected": "<ul>\n<li>\n<dl>\n<dt>t</dt>\n<dd>para</dd>\n</dl>\n</li>\n</ul>\n<pre><code></code></pre>",
    "shape": "PHP-2 - :: t / ~: para / ~%%% / ```"
  },
  {
    "id": "r7_06",
    "source": ":: t\n: 1. para\n  {#x}\ntail\n",
    "expected": "<dl>\n<dt>t</dt>\n<dd>\n<ol>\n<li>para</li>\n</ol>\n</dd>\n</dl>\n<p>tail</p>",
    "shape": "PHP-3 :: t / : 1~ para / ~ATTR / T"
  },
  {
    "id": "r7_07",
    "source": ":: t\n: 1. para\n    {#x}\ntail\n",
    "expected": "<dl>\n<dt>t</dt>\n<dd>\n<ol>\n<li>para\n{<span class=\"tag\"><strong>#x</strong></span>}\ntail</li>\n</ol>\n</dd>\n</dl>",
    "shape": "PHP-3 :: t / : 1~ para / ~ATTR / T"
  },
  {
    "id": "r7_08",
    "source": "[^c]: [^d]: para\n        more\n%% c\n        tail\n",
    "expected": "",
    "shape": "PHP-4 [^c]: [^d]: para / ~more / CMT / ~T"
  },
  {
    "id": "r7_09",
    "source": "1. - para\n %% c\n```\nx\n",
    "expected": "<ol>\n<li>\n<ul>\n<li>para\n<pre><code></code></pre>\n</li>\n</ul>\n</li>\n</ol>\n<p>x</p>",
    "shape": "PHP-5 1~ - para / ~CMT / ``` / T"
  },
  {
    "id": "r7_10",
    "source": "1. - para\n %%%\n %%%\n```\nx\n",
    "expected": "<ol>\n<li>\n<ul>\n<li>para\n<pre><code></code></pre>\n</li>\n</ul>\n</li>\n</ol>\n<p>x</p>",
    "shape": "PHP-6 1~ - para / ~%%% / ~%%% / ``` / T"
  },
  {
    "id": "r7_11",
    "source": ":: t\n: :: t\n  : para\n %%%\n %%%\ntail\n",
    "expected": "<dl>\n<dt>t</dt>\n<dd>\n<dl>\n<dt>t</dt>\n<dd>\n<p>para</p>\n<p>tail</p>\n</dd>\n</dl>\n</dd>\n</dl>",
    "shape": "PHP-7 :: t / : :: t / ~: para / ~%%% / ~%%% / T"
  },
  {
    "id": "comment line twin",
    "source": ":: t\n: :: t\n  : para\n %% c\ntail\n",
    "expected": "<dl><dt>t</dt><dd><dl><dt>t</dt><dd><p>para</p><p>tail</p></dd></dl></dd></dl>"
  },
  {
    "id": "document column fence",
    "source": "- para\n%%%\n%%%\ntail\n",
    "expected": "<ul><li>para</li></ul><p>tail</p>"
  },
  {
    "id": "item content column fence",
    "source": "- para\n  %%%\n  %%%\ntail\n",
    "expected": "<ul><li>para</li></ul><p>tail</p>"
  },
  {
    "id": "outer body column fence",
    "source": ":: t\n: :: t\n  : para\n  %%%\n  %%%\ntail\n",
    "expected": "<dl><dt>t</dt><dd><dl><dt>t</dt><dd>para</dd></dl></dd></dl><p>tail</p>"
  },
  {
    "id": "n:desc/item-4131",
    "source": "[r][] [^p] [^c] [^d]\n\n:: t\n: - para\n    more\n    {#x}\n```\nx\n",
    "expected": "<p>[r][] [^p] [^c] [^d]</p>\n<dl>\n<dt>t</dt>\n<dd>\n<ul>\n<li>para\nmore</li>\n</ul>\n</dd>\n</dl>\n<pre><code>x\n</code></pre>",
    "shape": "n:desc/item-4131"
  },
  {
    "id": "n:desc/item-4722",
    "source": "[r][] [^p] [^c] [^d]\n\n:: t\n: - para\n     {#x}\ntail\n",
    "expected": "<p>[r][] [^p] [^c] [^d]</p>\n<dl>\n<dt>t</dt>\n<dd>\n<ul>\n<li>para</li>\n</ul>\n</dd>\n</dl>\n<p>tail</p>",
    "shape": "n:desc/item-4722"
  },
  {
    "id": "n:desc/item-4976",
    "source": "[r][] [^p] [^c] [^d]\n\n:: t\n: - para\n    more\n        {#x}\n```\nx\n",
    "expected": "<p>[r][] [^p] [^c] [^d]</p>\n<dl>\n<dt>t</dt>\n<dd>\n<ul>\n<li>para\nmore</li>\n</ul>\n</dd>\n</dl>\n<pre><code>x\n</code></pre>",
    "shape": "n:desc/item-4976"
  },
  {
    "id": "n:desc/ordered-6437",
    "source": "[r][] [^p] [^c] [^d]\n\n:: t\n: 1. para\n     more\n      {#x}\n```\nx\n",
    "expected": "<p>[r][] [^p] [^c] [^d]</p>\n<dl>\n<dt>t</dt>\n<dd>\n<ol>\n<li>para\nmore</li>\n</ol>\n</dd>\n</dl>\n<pre><code>x\n</code></pre>",
    "shape": "n:desc/ordered-6437"
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
