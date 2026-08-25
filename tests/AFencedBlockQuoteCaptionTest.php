<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * markup-carve/carve#1718 and the caption clause in markup-carve/carve#1742.
 * A fenced quote IS a block quote, so it captions like one: the slot hangs on
 * its CLOSING fence and a captioned quote is a figure either way. Asserted
 * against the prefixed spelling rather than pinned HTML, since the point is
 * that the two agree.
 */
class AFencedBlockQuoteCaptionTest extends TestCase
{
    private CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = CarveConverter::create();
    }

    private function html(string $source): string
    {
        return trim($this->converter->convert($source));
    }

    public function testWrapsItInAFigureExactlyAsThePrefixedSpellingDoes(): void
    {
        $this->assertSame(
            $this->html("> Stay hungry.\n^ Steve Jobs\n"),
            $this->html("::: >\nStay hungry.\n:::\n^ Steve Jobs\n"),
        );
    }

    public function testStillAllowsOneBlankLineBetweenTheCloserAndTheCaption(): void
    {
        $this->assertSame(
            $this->html("> Stay hungry.\n\n^ Steve Jobs\n"),
            $this->html("::: >\nStay hungry.\n:::\n\n^ Steve Jobs\n"),
        );
    }
}
