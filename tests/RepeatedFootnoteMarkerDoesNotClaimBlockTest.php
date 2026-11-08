<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

final class RepeatedFootnoteMarkerDoesNotClaimBlockTest extends TestCase
{
    public function testRepeatedMarkerLeavesFollowingParagraphInDocument(): void
    {
        $source = "[^a]: +\n[^a]: +\n flush\n\nsee[^a]\n";
        $expected = <<<'HTML'
<p>flush</p>
<p>see<a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a></p>
<section role="doc-endnotes" aria-label="Footnotes">
  <hr>
  <ol>
    <li id="fn1">
      <p><a href="#fnref1" role="doc-backlink" aria-label="Back to reference">↩</a></p>
    </li>
  </ol>
</section>
HTML;

        self::assertSame($expected, (new CarveConverter())->convert($source));
    }

    public function testRepeatedMarkersReleaseTheNextBlockAfterMoreCollisions(): void
    {
        $html = (new CarveConverter())->convert("[^a]: +\n[^a]: +\n[^a]: +\n kept\n\nsee[^a]\n");

        self::assertStringStartsWith("<p>kept</p>\n<p>see", $html);
        self::assertSame(1, substr_count($html, '<p>kept</p>'));
    }

    public function testRepeatedMarkerKeepsItsOrdinaryIndentedBody(): void
    {
        $html = (new CarveConverter())->convert("[^a]: +\n[^a]: +\n  body\n\nsee[^a]\n");

        self::assertStringNotContainsString('<p>body</p>', $html);
    }

    public function testRepeatedMarkerReleasesParagraphInsideDiv(): void
    {
        $html = (new CarveConverter())->convert("::: note\n[^a]: +\n[^a]: +\n kept\n\nsee[^a]\n:::\n");

        self::assertStringContainsString("<p>kept</p>\n  <p>see", $html);
    }
}
