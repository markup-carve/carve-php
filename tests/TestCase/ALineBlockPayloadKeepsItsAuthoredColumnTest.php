<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

final class ALineBlockPayloadKeepsItsAuthoredColumnTest extends TestCase
{
    public function testAnIndentedColonRunStaysInTheNestedStanza(): void
    {
        $source = "- :: u\n  : ::: |\n     [x]: /u\n    - l\n       :::\nb\n";

        $expected = <<<'HTML'
<ul>
  <li>
    <dl>
      <dt>u</dt>
      <dd>
        <div class="line-block">
          <p>&nbsp;[x]: /u<br>
- l<br>
&nbsp;&nbsp;&nbsp;:::<br>
b</p>
        </div>
      </dd>
    </dl>
  </li>
</ul>
HTML;

        self::assertSame($expected, (new CarveConverter())->convert($source));
    }

    public function testTheSamePayloadSurvivesTwoItemLevels(): void
    {
        $source = "- - :: u\n    : ::: |\n      [x]: /u\n      - l\n         :::: \nb\n";

        $expected = <<<'HTML'
<ul>
  <li>
    <ul>
      <li>
        <dl>
          <dt>u</dt>
          <dd>
            <div class="line-block">
              <p>[x]: /u<br>
- l<br>
&nbsp;&nbsp;&nbsp;::::<br>
b</p>
            </div>
          </dd>
        </dl>
      </li>
    </ul>
  </li>
</ul>
HTML;

        self::assertSame($expected, (new CarveConverter())->convert($source));
    }
}
