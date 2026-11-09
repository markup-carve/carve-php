<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

final class ANoteAttachedDescriptionEndsAtRefusedPlusTest extends TestCase
{
    public function testFollowingParagraphBelongsToTheNote(): void
    {
        $source = "[^a]: +\n:: term\n: desc\n+\np\n\nsee[^a]\n";
        $expected = "<p>see<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n"
            . "<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n"
            . "      <dl>\n        <dt>term</dt>\n        <dd>desc</dd>\n      </dl>\n"
            . "      <p>p<a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n"
            . "    </li>\n  </ol>\n</section>";

        self::assertSame($expected, (new CarveConverter())->convert($source));
    }

    public function testIndentedDescriptionContinuationDoesNotClaimTheLaterParagraph(): void
    {
        $source = "[^a]: +\n:: term\n: desc\n  continuation\n+\np\n\nsee[^a]\n";
        $expected = "<p>see<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n"
            . "<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n"
            . "      <dl>\n        <dt>term</dt>\n        <dd>desc\ncontinuation</dd>\n      </dl>\n"
            . "      <p>p<a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n"
            . "    </li>\n  </ol>\n</section>";

        self::assertSame($expected, (new CarveConverter())->convert($source));
    }
}
