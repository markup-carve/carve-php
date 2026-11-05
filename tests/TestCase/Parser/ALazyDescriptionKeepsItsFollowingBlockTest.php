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

    public function testProtectedFenceKeepsItsCloserAndFollowingBlock(): void
    {
        $source = "- :: t\n  : :: t\n    : :: t\n      : x\n    para\n         ```\n         code\n         ```\n        after\n";
        $html = CarveConverter::create()->convert($source);

        self::assertStringContainsString("<pre><code>code\n</code></pre>\n                <p>after</p>", $html);
    }

    public function testLowerFenceCloserReleasesTheFollowingSiblingBlock(): void
    {
        $source = ":: t\n: :: t\n  : :: t\n    : x\npara\n      ```\n      code\n     ```\n     ::: d\n";
        $html = CarveConverter::create()->convert($source);

        self::assertStringContainsString("<pre><code>code\n</code></pre>", $html);
        self::assertStringContainsString("        </dl>\n        <pre><code>::: d\n</code></pre>", $html);
    }

    public function testSiblingBlockAfterFenceKeepsItsReachedDescription(): void
    {
        $source = ":: t\n: :: t\n  : :: t\n    : x\npara\n      ```\n      code\n      ```\n     ::: d\n";
        $html = CarveConverter::create()->convert($source);

        self::assertStringContainsString("        </dl>\n        <div class=\"d\">", $html);
    }
}
