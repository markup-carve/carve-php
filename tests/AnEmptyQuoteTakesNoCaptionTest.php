<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * markup-carve/carve#2892, PART 9 section 4d: a structural-only line resets
 * caption scope.
 *
 * A bare `>` is empty once its structural prefix is removed. It is not a blank
 * line, so the one-blank caption allowance never spends it, and the quote it
 * opens holds nothing a caption could be about. Counting it as blank let
 * `| a |`, blank, `>`, blank, `^ cap` render a captioned figure around an empty
 * quote, a host the caption was not written under.
 *
 * All three engines and the oracle agreed on the old reading, so the corpus
 * could not see it; djot.js 0.3.2 drops the `^ ` line entirely there. Keeping
 * the text as a paragraph is the safer half of that disagreement and is what
 * the importers already write.
 */
class AnEmptyQuoteTakesNoCaptionTest extends TestCase
{
    private CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = CarveConverter::create();
    }

    public function testEmptyQuoteBetweenTableAndMarkerTakesNoCaption(): void
    {
        $this->assertSame(
            "<table>\n  <tbody>\n    <tr><td>a</td></tr>\n  </tbody>\n</table>\n"
            . "<blockquote>\n\n</blockquote>\n<p>^ cap</p>\n",
            $this->converter->convert("| a |\n\n>\n\n^ cap\n"),
        );
    }

    public function testEmptyQuoteAdjacentToTheMarkerTakesNoCaption(): void
    {
        $this->assertSame(
            "<blockquote>\n\n</blockquote>\n<p>^ cap</p>\n",
            $this->converter->convert(">\n^ cap\n"),
        );
    }

    public function testQuoteWithContentStillCaptionsPastATrailingBareMarker(): void
    {
        $this->assertSame(
            "<figure>\n  <blockquote><p>q</p></blockquote>\n  <figcaption>cap</figcaption>\n</figure>\n",
            $this->converter->convert("> q\n>\n\n^ cap\n"),
        );
    }
}
