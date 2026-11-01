<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

class InvisibleDefinitionBehindContainerMarkerTest extends TestCase
{
    public function testReferenceDefinitionInNestedDescriptionDoesNotOpenAParagraph(): void
    {
        $source = <<<'CARVE'
:: t
: - [ ] :: t
    : [r]:
         /u
CARVE;
        $expected = <<<'HTML'
<dl>
  <dt>t</dt>
  <dd>
    <ul class="task-list">
      <li><input type="checkbox" disabled>
        <dl>
          <dt>t</dt>
          <dd>/u</dd>
        </dl>
      </li>
    </ul>
  </dd>
</dl>
HTML;

        self::assertSame(
            str_replace("disabled>\n", "disabled> \n", $expected),
            CarveConverter::create()->convert($source),
        );
    }
}
