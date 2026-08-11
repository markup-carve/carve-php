<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\Test\LegacyCarveConverter as CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * A comment inside a list item renders nothing, but it DOES end the open
 * paragraph: the line after it is the item's SECOND paragraph, not a
 * continuation of the first. The executable spec, carve-js and carve-rs all agree.
 *
 * This engine did neither. The line after a comment was FOLDED, and where it
 * landed decided which of two wrong answers came out:
 *
 *  - onto the comment's own entry, giving one collected line `%% x\nh`, which the
 *    comment handling then consumed whole - the author's line vanished from the
 *    document entirely (carve-php#791 for the fence spelling, and still broken
 *    for the line spelling)
 *  - past it onto the preceding paragraph, running the two source lines together
 *    (carve-php#800)
 *
 * The fix pushes it as its own entry with ONE leading space. The space is
 * load-bearing: the item body is dedented, so a block-shaped line like `# h`
 * would re-parse as a real HEADING at column 0, where §24 C3's BELOW branch says
 * it is text. One column reaches no content column at any depth, which is the
 * same guard carve-js uses here.
 */
class CommentEndsTheParagraphInAnItemTest extends TestCase
{
    protected function html(string $source): string
    {
        return (string)preg_replace('/\s+/', ' ', (new CarveConverter())->convert($source));
    }

    public function testALineCommentEndsTheParagraph(): void
    {
        // The blank before `- b` makes the list loose, so the paragraph structure
        // is visible in the output rather than implied.
        $html = $this->html("- a\n  %% x\n # h\n\n- b\n");

        $this->assertStringContainsString('<p>a</p>', $html);
        $this->assertStringContainsString('<p># h</p>', $html, 'the line vanished or was folded');
        $this->assertStringNotContainsString('<h1', $html, 'below the content column it is text');
    }

    public function testACommentFenceEndsTheParagraph(): void
    {
        $html = $this->html("- a\n  %%% x\n # h\n\n- b\n");

        $this->assertStringContainsString('<p>a</p>', $html);
        $this->assertStringContainsString('<p># h</p>', $html);
        $this->assertStringNotContainsString('%%%', $html);
    }

    public function testAClosedFenceEndsTheParagraphAndKeepsOneList(): void
    {
        $html = $this->html("- a\n  %%% x\n  y\n  %%%\n b\n\n- c\n");

        $this->assertStringContainsString('<p>a</p>', $html);
        $this->assertStringContainsString('<p>b</p>', $html);
        $this->assertStringNotContainsString('y', $html, 'the comment body must not render');
        // One list, two items - a comment may not decide which list a sibling
        // marker joins.
        $this->assertSame(1, substr_count($html, '<ul>'));
    }

    public function testTheLineIsItsOwnParagraphNotAppendedToTheFirst(): void
    {
        // The carve-php#800 symptom: `a` and `# h` in ONE paragraph. Counting
        // paragraphs is the discriminating form - asserting the absence of the
        // literal `a# h` is not, because the folded output contains a newline
        // between them and so never matches that string either way.
        $html = $this->html("- a\n  %%% x\n # h\n\n- b\n");

        // Three paragraphs: `a`, `# h`, and `b` in the sibling item.
        $this->assertSame(3, substr_count($html, '<p>'));
    }

    public function testAPlainFollowerWasAlreadyRightAndStaysRight(): void
    {
        // Nothing block-shaped, so the guard space is not what makes this work -
        // this shape was unanimous before the fix and must stay so.
        $html = $this->html("- a\n  %% x\n b\n\n- c\n");

        $this->assertStringContainsString('<p>a</p>', $html);
        $this->assertStringContainsString('<p>b</p>', $html);
    }

    public function testNoCommentMeansAPlainLazyFold(): void
    {
        // The boundary the fix must not move: with no comment in between, a
        // below-column line still CONTINUES the paragraph rather than starting a
        // new one.
        $html = $this->html("- a\n b\n\n- c\n");

        $this->assertStringContainsString('a b', $html);
        $this->assertStringNotContainsString('<p>b</p>', $html);
    }
}
