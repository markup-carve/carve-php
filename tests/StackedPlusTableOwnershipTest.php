<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

final class StackedPlusTableOwnershipTest extends TestCase
{
    public function testWholeTableStaysInInnerItemWhileLaterParagraphBelongsToTaskItem(): void
    {
        $source = "- [ ] - +\n      | a |\n      | b |\n\n      q\n";
        $expected = <<<'HTML'
<ul class="task-list">
  <li><input type="checkbox" disabled>__SPACE__
    <ul>
      <li>
        <table>
          <tbody>
            <tr><td>a</td></tr>
            <tr><td>b</td></tr>
          </tbody>
        </table>
      </li>
    </ul>
    <p>q</p>
  </li>
</ul>
HTML;
        self::assertSame(str_replace('__SPACE__', ' ', $expected), trim((new CarveConverter())->convert($source)));
    }
}
