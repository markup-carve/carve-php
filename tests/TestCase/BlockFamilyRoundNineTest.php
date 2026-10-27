<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

class BlockFamilyRoundNineTest extends TestCase
{
    public function testRoundNineCases(): void
    {
        $json = <<<'JSON'
[
  {
    "id": "r9_00",
    "source": "> [^c]: para\n > q\n",
    "expected": "<blockquote>\n</blockquote>\n<p>&gt; q</p>"
  },
  {
    "id": "r9_01",
    "source": "> [^c]: para\n  > q\n",
    "expected": "<blockquote>\n</blockquote>\n<p>&gt; q</p>"
  },
  {
    "id": "r9_02",
    "source": "> [^c]: para\n   > q\n",
    "expected": "<blockquote>\n</blockquote>\n<p>&gt; q</p>"
  },
  {
    "id": "r9_03",
    "source": "> [^c]: para\n     > q\n",
    "expected": "<blockquote>\n</blockquote>\n<p>&gt; q</p>"
  },
  {
    "id": "r9_04",
    "source": ":: t\n: > para\n  ^ cap\n```\n",
    "expected": "<dl>\n<dt>t</dt>\n<dd>\n<figure>\n<blockquote><p>para</p></blockquote>\n<figcaption>cap\n<code></code></figcaption>\n</figure>\n</dd>\n</dl>"
  },
  {
    "id": "r9_05",
    "source": "1. :: t\n  %%%\n2. next\n%%%\n",
    "expected": "<ol>\n  <li>\n    <dl>\n      <dt>t\n</dt>\n    </dl>\n  </li>\n  <li>next</li>\n</ol>\n"
  },
  {
    "id": "quote marker at quote column",
    "source": "> [^c]: para\n> q\n",
    "expected": "<blockquote><p>q</p></blockquote>"
  },
  {
    "id": "caption continuation without fence",
    "source": ":: t\n: > para\n  ^ cap\n  tail\n",
    "expected": "<dl><dt>t</dt><dd><figure><blockquote><p>para</p></blockquote><figcaption>cap\ntail</figcaption></figure></dd></dl>"
  }
]
JSON;
        $cases = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $converter = new CarveConverter();
        foreach ($cases as $case) {
            if ($case['id'] === 'r9_05') {
                self::assertSame(preg_replace('/\n\z/', '', $case['expected']), $converter->convert($case['source']), $case['id']);

                continue;
            }
            self::assertSame(
                preg_replace('/\s+/', '', $case['expected']),
                preg_replace('/\s+/', '', $converter->convert($case['source'])),
                $case['id'],
            );
        }
    }
}
