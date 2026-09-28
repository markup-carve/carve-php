<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

class AQuotedContainerFenceStoresNoLazyClaimTest extends TestCase
{
    public function testCorpus514(): void
    {
        $cases = json_decode(<<<'JSON'
[
  [
    "514-a-fence-a-container-inside-a-quote-holds-open-stores-no-claim-10",
    "> - a\n>\n>   ```\n>   x\n> after\nflush\n",
    "<blockquote>\n  <ul>\n    <li>a\n      <pre><code>x\n</code></pre>\n    </li>\n  </ul>\n  <p>after\nflush</p>\n</blockquote>\n"
  ],
  [
    "514-a-fence-a-container-inside-a-quote-holds-open-stores-no-claim-11",
    "> :  a\n>\n>    ```\n>    x\nflush\n",
    "<blockquote>\n  <p>:  a</p>\n  <p><code>\nx\nflush</code></p>\n</blockquote>\n"
  ],
  [
    "514-a-fence-a-container-inside-a-quote-holds-open-stores-no-claim-12",
    "> :: t\n> :  d\n>    - ```\n>      x\nflush\n",
    "<blockquote>\n  <dl>\n    <dt>t</dt>\n    <dd>d\n- <code>\nx\nflush</code></dd>\n  </dl>\n</blockquote>\n"
  ],
  [
    "514-a-fence-a-container-inside-a-quote-holds-open-stores-no-claim-2",
    "> - a\n>   - b\n>\n>     ```\n>     x\nflush\n",
    "<blockquote>\n  <ul>\n    <li>a\n      <ul>\n        <li>b\n          <pre><code>x\n</code></pre>\n        </li>\n      </ul>\n    </li>\n  </ul>\n</blockquote>\n<p>flush</p>\n"
  ],
  [
    "514-a-fence-a-container-inside-a-quote-holds-open-stores-no-claim-3",
    "> - a\n>\n>   ```\n>   x\n>   ```\nflush\n",
    "<blockquote>\n  <ul>\n    <li>a\n      <pre><code>x\n</code></pre>\n    </li>\n  </ul>\n</blockquote>\n<p>flush</p>\n"
  ],
  [
    "514-a-fence-a-container-inside-a-quote-holds-open-stores-no-claim-4",
    "> - a\n>   - b\n>\n>     ```\n>     x\n>     ```\nflush\n",
    "<blockquote>\n  <ul>\n    <li>a\n      <ul>\n        <li>b\n          <pre><code>x\n</code></pre>\n        </li>\n      </ul>\n    </li>\n  </ul>\n</blockquote>\n<p>flush</p>\n"
  ],
  [
    "514-a-fence-a-container-inside-a-quote-holds-open-stores-no-claim-5",
    "> - ```\n>   x\nflush\n",
    "<blockquote>\n  <ul>\n    <li>\n      <pre><code>x\n</code></pre>\n    </li>\n  </ul>\n</blockquote>\n<p>flush</p>\n"
  ],
  [
    "514-a-fence-a-container-inside-a-quote-holds-open-stores-no-claim-6",
    "> [^f]: t\n>\n>   ```\n>   x\nflush\n",
    "<blockquote>\n\n</blockquote>\n<p>flush</p>\n"
  ],
  [
    "514-a-fence-a-container-inside-a-quote-holds-open-stores-no-claim-7",
    "> - a\n>\n>   ```\n>   x\n>   ```\n>\n>   z\nflush\n",
    "<blockquote>\n  <ul>\n    <li><p>a</p>\n      <pre><code>x\n</code></pre>\n      <p>z\nflush</p>\n    </li>\n  </ul>\n</blockquote>\n"
  ],
  [
    "514-a-fence-a-container-inside-a-quote-holds-open-stores-no-claim-8",
    "> > - a\n> >\n> >   ```\n> >   x\nflush\n",
    "<blockquote>\n  <blockquote>\n    <ul>\n      <li>a\n        <pre><code>x\n</code></pre>\n      </li>\n    </ul>\n  </blockquote>\n</blockquote>\n<p>flush</p>\n"
  ],
  [
    "514-a-fence-a-container-inside-a-quote-holds-open-stores-no-claim-9",
    "> - a\n> - ```\n>   x\nflush\n",
    "<blockquote>\n  <ul>\n    <li>a</li>\n    <li>\n      <pre><code>x\n</code></pre>\n    </li>\n  </ul>\n</blockquote>\n<p>flush</p>\n"
  ],
  [
    "514-a-fence-a-container-inside-a-quote-holds-open-stores-no-claim",
    "> - a\n>\n>   ```\n>   x\nflush\n",
    "<blockquote>\n  <ul>\n    <li>a\n      <pre><code>x\n</code></pre>\n    </li>\n  </ul>\n</blockquote>\n<p>flush</p>\n"
  ],
  [
    "a lazy line preserves the item column",
    "> - a\nb\n>\n>   ```\n>   x\nflush\n",
    "<blockquote>\n  <ul>\n    <li>a\nb\n      <pre><code>x\n</code></pre>\n    </li>\n  </ul>\n</blockquote>\n<p>flush</p>"
  ],
  [
    "a lazy line before a closed fence",
    "> - a\nb\n>\n>   ```\n>   x\n>   ```\nflush\n",
    "<blockquote>\n  <ul>\n    <li>a\nb\n      <pre><code>x\n</code></pre>\n    </li>\n  </ul>\n</blockquote>\n<p>flush</p>"
  ],
  [
    "a lazy line preserves nested item columns",
    "> - a\n>   - b\nc\n>\n>     ```\n>     x\nflush\n",
    "<blockquote>\n  <ul>\n    <li>a\n      <ul>\n        <li>b\nc\n          <pre><code>x\n</code></pre>\n        </li>\n      </ul>\n    </li>\n  </ul>\n</blockquote>\n<p>flush</p>"
  ],
  [
    "an invalid info string leaves no closer",
    "> - a\n>   ```bad`\n>\n>   ```\n>   x\nflush\n",
    "<blockquote>\n  <ul>\n    <li>a\n<code>bad`</code>\n      <pre><code>x\n</code></pre>\n    </li>\n  </ul>\n</blockquote>\n<p>flush</p>"
  ],
  [
    "over-indented runs pair",
    "> - a\n>     ~~~\n>     x\n>     ~~~\n>\n>   ~~~\n>   x\nflush\n",
    "<blockquote>\n  <ul>\n    <li>a\n      <pre><code>x\n</code></pre>\n      <pre><code>x\n</code></pre>\n    </li>\n  </ul>\n</blockquote>\n<p>flush</p>"
  ],
  [
    "a fence without a closer stays inline",
    "> - a\n>   ```\n>   x\nflush\n",
    "<blockquote>\n  <ul>\n    <li>a\n<code>\nx\nflush</code></li>\n  </ul>\n</blockquote>"
  ]
]
JSON
, true, flags: JSON_THROW_ON_ERROR);
        $converter = new CarveConverter();
        foreach ($cases as [$name, $source, $expected]) {
            $this->assertSame(rtrim($expected), rtrim($converter->convert($source)), $name);
        }
    }

    public function testFenceDepthsAndMarkerWidths(): void
    {
        $converter = new CarveConverter();
        foreach ([1, 2, 3] as $depth) {
            foreach (['```', '~~~', '```=html'] as $fence) {
                foreach ([['- ', 2], ['1. ', 3], ['- [x] ', 2], ['-{.x} ', 2]] as [$marker, $column]) {
                    foreach ([false, true] as $closed) {
                        $quote = str_repeat('> ', $depth);
                        $pad = str_repeat(' ', $column);
                        $closer = str_starts_with($fence, '~') ? '~~~' : '```';
                        $ending = $closed ? "{$quote}{$pad}{$closer}\n" : '';
                        $source = "{$quote}{$marker}a\n" . rtrim($quote) . "\n{$quote}{$pad}{$fence}\n{$quote}{$pad}x\n{$ending}flush\n";
                        $this->assertStringEndsWith("</blockquote>\n<p>flush</p>\n", $converter->convert($source), $source);
                    }
                }
            }
        }
    }
}
