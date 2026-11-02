<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

final class ANestedQuotedTermContinuationUsesItsAuthoredColumnTest extends TestCase
{
    public function testSiblingTermContinuationLosesOnlyTheContainerPrefix(): void
    {
        $source = "- host\n  > - * :: t\n  >  : a\n  >   :: sibling\n  >   tail\n";
        $html = CarveConverter::create()->convert($source);

        self::assertStringContainsString("<dt>sibling</dt>\n      </dl>\n      <p>tail</p>", $html);
    }

    public function testTextPastTheTermColumnKeepsItsIndentation(): void
    {
        $source = "- host\n  > - * :: t\n  >  : a\n  >   :: sibling\n  >    tail\n";
        $html = CarveConverter::create()->convert($source);

        self::assertStringContainsString("<dt>sibling</dt>\n      </dl>\n      <p>tail</p>", $html);
    }

    public function testSameColumnWorksWhenASecondQuoteEnclosesTheList(): void
    {
        $source = "> - host\n>   > - * :: t\n>   >   :: sibling\n>   >   tail\n";
        $html = CarveConverter::create()->convert($source);

        self::assertStringContainsString("<dt>sibling</dt>\n            </dl>\n            tail\n", $html);
    }
}
