<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * A `+` continuation marker works whatever the item already holds
 * (carve-php#925).
 *
 * §17 L3 conditions the marker on ONE thing: "a line whose only content is
 * `+`, at the current container's MARKER COLUMN". Nothing in it depends on
 * whether the item is tight, or on what was written above.
 *
 * This engine recognized it in a tight item and ignored it once the item held
 * a blank line, so the same construct read two ways depending on unrelated
 * context above it - and the marker came out as literal text inside the
 * paragraph it was meant to end.
 *
 * The cause was one collector short of a case: the post-blank item-body loop
 * had no `+` branch, where `collectListContinuationBlock()` two functions over
 * already stops on exactly `$trimmed === '+'`.
 */
class AContinuationMarkerWorksInALooseItemTest extends TestCase
{
    protected function html(string $source): string
    {
        return (new CarveConverter())->convert($source);
    }

    public function testTheMarkerAttachesAfterABlankLineInTheItem(): void
    {
        $html = $this->html("- a\n\n  b\n+\nc\n\nx\n");

        $this->assertStringContainsString('<p>c</p>', $html);
        $this->assertStringNotContainsString('+', $html);
    }

    public function testTheAttachedBlockIsNotFoldedIntoTheParagraphAboveIt(): void
    {
        // The failure this replaced: `b`, the marker and `c` came back as ONE
        // paragraph, so asserting only on `<p>c</p>` would not have caught it.
        $html = $this->html("- a\n\n  b\n+\nc\n\nx\n");

        $this->assertStringContainsString('<p>b</p>', $html);
    }

    public function testTheMarkerStillAttachesInATightItem(): void
    {
        // The control: the shape that already worked must keep working.
        $html = $this->html("- a\n+\nb\n\nx\n");

        $this->assertStringNotContainsString('+', $html);
    }

    public function testANonMarkerLineAfterABlankStillFoldsAsLazyText(): void
    {
        // The boundary. Breaking out of the collector for every base-column
        // line would end the item early and detach ordinary lazy continuation.
        $html = $this->html("- a\n\n  b\nc\n\nx\n");

        $this->assertStringContainsString("<p>b\nc</p>", $html);
    }

    public function testAPlusWithContentIsNotAMarkerAndNotABullet(): void
    {
        // §11 N1: `+` is not a Carve bullet, which is what makes a LONE `+`
        // unambiguous as the continuation marker. `+ one` is therefore neither
        // - it is ordinary paragraph text, in all three engines.
        $html = $this->html("+ one\n+ two\n");

        $this->assertStringNotContainsString('<li>', $html);
        $this->assertStringContainsString('+ one', $html);
    }
}
