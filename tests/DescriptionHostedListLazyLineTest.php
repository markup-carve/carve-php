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

        // The marker misses the note and opens a list in the description, which takes the lazy line (carve#4860).
        self::assertStringContainsString("<dd>\n        <ul>\n          <li>nested\nafter</li>\n        </ul>\n      </dd>", $html);
    }
}
