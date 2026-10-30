<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

final class DescriptionHostedListLazyLineTest extends TestCase
{
    public function testAListItemAfterAHostedFootnoteKeepsItsLazyLine(): void
    {
        $html = (new CarveConverter())->convert("- q\n  :: t\n  :  [^f]: note\n     - nested\n  after\n");

        self::assertStringContainsString("<dd></dd>\n    </dl>\n  </li>\n</ul>", $html);
    }
}
