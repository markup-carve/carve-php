<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

final class ADeepDescriptionKeepsItsTaskAndInvisibleDefinitionTest extends TestCase
{
    public function testTheNestedTermOpensAChildBlock(): void
    {
        $source = ":: x\n: :: t\n  : - [ ] :: t\n      : [r]:\n           /u\n";
        $expected = "<dl>\n  <dt>x</dt>\n  <dd>\n    <dl>\n      <dt>t</dt>\n      <dd>\n        <ul class=\"task-list\">\n          <li><input type=\"checkbox\" disabled> \n            <dl>\n              <dt>t</dt>\n              <dd>/u</dd>\n            </dl>\n          </li>\n        </ul>\n      </dd>\n    </dl>\n  </dd>\n</dl>";

        self::assertSame($expected, trim(CarveConverter::create()->convert($source)));
    }

    public function testTheFollowingDescriptionStartsBesideTheReferenceBody(): void
    {
        $source = ":: x\n: :: t\n  : - [ ] :: t\n      : [r]:\n           /u\n      : sibling\n";
        $html = trim(CarveConverter::create()->convert($source));

        self::assertStringContainsString("<dd>/u</dd>\n              <dd>sibling</dd>", $html);
        self::assertStringNotContainsString('/u' . "\n" . ': sibling', $html);
    }
}
