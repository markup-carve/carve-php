<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\TestCase;

class MarkdownImageLabelsTest extends TestCase
{
    public function testImageDescriptionsAndReferences(): void
    {
        $cases = json_decode(<<<'JSON'
[["![foo [bar](/v)][r]\n\n[r]: /u", "<img src=\"/u\" alt=\"foo bar\">"], ["![x [y](/v \"t\")](u)", "<img src=\"u\" alt=\"x y\">"],["![a[b]c](u)", "<img src=\"u\" alt=\"a[b]c\">"], ["![[x]](u)", "<img src=\"u\" alt=\"[x]\">"], ["![a[b]c][r]\n\n[r]: /u", "<img src=\"/u\" alt=\"a[b]c\">"], ["![`a\\b`](u)", "<img src=\"u\" alt=\"a\\b\">"],
  [
    "![foo *bar*]\n\n[foo *bar*]: train.jpg \"train & tracks\"\n",
    "<img src=\"train.jpg\" alt=\"foo bar\" title=\"train &amp; tracks\">"
  ],
  [
    "![foo ![bar](/url)](/url2)\n",
    "<img src=\"/url2\" alt=\"foo bar\">"
  ],
  [
    "![foo [bar](/url)](/url2)\n",
    "<img src=\"/url2\" alt=\"foo bar\">"
  ],
  [
    "![foo *bar*][]\n\n[foo *bar*]: train.jpg \"train & tracks\"\n",
    "<img src=\"train.jpg\" alt=\"foo bar\" title=\"train &amp; tracks\">"
  ],
  [
    "![foo *bar*][foobar]\n\n[FOOBAR]: train.jpg \"train & tracks\"\n",
    "<img src=\"train.jpg\" alt=\"foo bar\" title=\"train &amp; tracks\">"
  ],
  [
    "My ![foo bar](/path/to/train.jpg  \"title\"   )\n",
    "<p>My <img src=\"/path/to/train.jpg\" alt=\"foo bar\" title=\"title\"></p>"
  ],
  [
    "![foo][bar]\n\n[BAR]: /url\n",
    "<img src=\"/url\" alt=\"foo\">"
  ],
  [
    "![*foo* bar][]\n\n[*foo* bar]: /url \"title\"\n",
    "<img src=\"/url\" alt=\"foo bar\" title=\"title\">"
  ],
  [
    "![Foo][]\n\n[foo]: /url \"title\"\n",
    "<img src=\"/url\" alt=\"Foo\" title=\"title\">"
  ],
  [
    "![foo] \n[]\n\n[foo]: /url \"title\"\n",
    "<p><img src=\"/url\" alt=\"foo\" title=\"title\">\n[]</p>"
  ],
  [
    "![foo]\n\n[foo]: /url \"title\"\n",
    "<img src=\"/url\" alt=\"foo\" title=\"title\">"
  ],
  [
    "![*foo* bar]\n\n[*foo* bar]: /url \"title\"\n",
    "<img src=\"/url\" alt=\"foo bar\" title=\"title\">"
  ],
  [
    "![Foo]\n\n[foo]: /url \"title\"\n",
    "<img src=\"/url\" alt=\"Foo\" title=\"title\">"
  ],
  [
    "![foo_bar_baz](/u)",
    "<img src=\"/u\" alt=\"foo_bar_baz\">"
  ],
  [
    "![a **b _c_** d](/u)",
    "<img src=\"/u\" alt=\"a b c d\">"
  ],
  [
    "![a `*b*` c](/u)",
    "<img src=\"/u\" alt=\"a *b* c\">"
  ],
  [
    "![a \\*b\\* c](/u)",
    "<img src=\"/u\" alt=\"a *b* c\">"
  ]
]
JSON
, true, flags: JSON_THROW_ON_ERROR);
        foreach ($cases as [$source, $expected]) {
            $converted = (new MarkdownToCarve())->convert($source);
            $this->assertSame($expected, rtrim((new CarveConverter())->convert($converted), "\n"), $source);
        }
    }
}
