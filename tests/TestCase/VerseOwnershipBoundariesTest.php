<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\TestCase;

final class VerseOwnershipBoundariesTest extends TestCase
{
    public function testLazyList(): void
    {
        $source = '- ::: |
  verse
lazy
  [r]: /hidden
  :::

[t][r]
';
        $expected = '<ul>
  <li>
    <div class="line-block">
      <p>verse<br>
lazy<br>
[r]: /hidden</p>
    </div>
  </li>
</ul>
<p>[t][r]</p>
';

        $this->assertSame($expected, (new CarveConverter())->convert($source));
    }

    public function testOpaqueComment(): void
    {
        $source = '::: |
%%%
:::
[r]: /hidden
%%%

[t][r]
';
        $expected = '<div class="line-block">
  <p><br>
:::<br>
[r]: /hidden<br>
</p>
  <p>[t][r]</p>
</div>
';

        $this->assertSame($expected, (new CarveConverter())->convert($source));
    }

    public function testOpaqueCode(): void
    {
        $source = '::: |
```
:::
```
[r]: /hidden
:::

[t][r]
';
        $expected = '<div class="line-block">
  <p><code>
:::
</code><br>
[r]: /hidden</p>
</div>
<p>[t][r]</p>
';

        $this->assertSame($expected, (new CarveConverter())->convert($source));
    }

    public function testAttachedCode(): void
    {
        $source = '> ::: |
> verse
+
```
:::
```
> [r]: /hidden
> :::

[t][r]
';
        $expected = '<blockquote>
  <div class="line-block">
    <p>verse</p>
    <p><code>
:::
</code></p>
    <p>[r]: /hidden</p>
  </div>
</blockquote>
<p>[t][r]</p>
';

        $this->assertSame($expected, (new CarveConverter())->convert($source));
    }

    public function testAfterVerse(): void
    {
        $source = '- ::: |
  verse
  :::

  [r]: /target

[t][r]
';
        $expected = '<ul>
  <li>
    <div class="line-block">
      <p>verse</p>
    </div>
  </li>
</ul>
<p><a href="/target">t</a></p>
';

        $this->assertSame($expected, (new CarveConverter())->convert($source));
    }

    public function testALazyLineAtTheStanzaEndKeepsItsAuthoredPosition(): void
    {
        $source = "- ::: |\n  verse\nlazy\n  :::\n";
        $converter = new CarveConverter(parser: new BlockParser(false, false, false, true));
        $tree = (new AstCodec())->encode($converter->parse($source));
        $paragraph = $tree['children'][0]['items'][0]['children'][0]['children'][0];

        $this->assertSame(3, $paragraph['pos']['endLine']);
        $this->assertSame(5, $paragraph['pos']['endColumn']);
        $this->assertSame(strpos($source, 'lazy') + 4, $paragraph['pos']['endOffset']);
    }

    public function testFalseCodeClosersDoNotHideTheVerseBoundary(): void
    {
        foreach (['```~', ' ```'] as $falseCloser) {
            $source = "::: |\n" . str_repeat("```x\n", 128)
                . $falseCloser . "\n:::\n\n[r]: /target\n\n[t][r]\n";
            $html = (new CarveConverter())->convert($source);

            $this->assertStringContainsString('href="/target"', $html);
        }
    }
}
