<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

final class ALazyDescriptionKeepsItsFollowingBlockTest extends TestCase
{
    public function testBlockAfterLazyLineStaysInInnermostDescription(): void
    {
        $source = "- :: t\n  : :: t\n    : :: t\n      : x\n    para\n         ::: d\n";
        $expected = <<<'HTML'
<ul>
  <li>
    <dl>
      <dt>t</dt>
      <dd>
        <dl>
          <dt>t</dt>
          <dd>
            <dl>
              <dt>t</dt>
              <dd>
                <p>x
para</p>
                <div class="d">

                </div>
              </dd>
            </dl>
          </dd>
        </dl>
      </dd>
    </dl>
  </li>
</ul>
HTML;

        self::assertSame($expected, trim(CarveConverter::create()->convert($source)));
    }

    public function testFenceAfterFoldedLazyLineKeepsItsDescription(): void
    {
        $source = ":: t\n: :: t\n  : :: t\n    : x\n para\n      ```\n";
        $expected = <<<'HTML'
<dl>
  <dt>t</dt>
  <dd>
    <dl>
      <dt>t</dt>
      <dd>
        <dl>
          <dt>t</dt>
          <dd>
            <p>x
para</p>
            <pre><code></code></pre>
          </dd>
        </dl>
      </dd>
    </dl>
  </dd>
</dl>
HTML;

        self::assertSame($expected, trim(CarveConverter::create()->convert($source)));
    }
}
