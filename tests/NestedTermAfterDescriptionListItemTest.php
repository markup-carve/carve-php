<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

final class NestedTermAfterDescriptionListItemTest extends TestCase
{
    public function testTermAtDescriptionColumnFollowsTheList(): void
    {
        $html = (new CarveConverter())->convert(":: term\n: - a\n   :: sub\n: def\n");
        self::assertStringContainsString("<li>a</li>\n    </ul>\n    <dl>\n      <dt>sub</dt>", $html);
        self::assertStringContainsString("</dl>\n  </dd>\n  <dd>def</dd>", $html);
    }

    public function testTermAndBodyAtDescriptionColumnStayTogether(): void
    {
        $html = (new CarveConverter())->convert(":: t\n: - a\n   :: s\n   : v\n: d\n");
        self::assertStringContainsString("<li>a</li>\n    </ul>\n    <dl>\n      <dt>s</dt>\n      <dd>v</dd>", $html);
    }

    public function testTermAtItemContentColumnStaysInTheItem(): void
    {
        $html = (new CarveConverter())->convert(":: t\n: - a\n    :: s\n: d\n");
        self::assertStringContainsString("<li>a\n        <dl>\n          <dt>s</dt>", $html);
    }
}
