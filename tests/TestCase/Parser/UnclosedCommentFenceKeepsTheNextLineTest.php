<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * §28: an unclosed `%%%` fence opens no block. §24 C3: a comment is invisible at
 * any column. Together they make the opener render nothing while leaving the
 * item open, so a following line below the content column folds in as lazy text.
 *
 * The opener line is therefore pushed as its own entry rather than folded. What
 * went wrong is what happened NEXT: the following line was appended onto that
 * entry, producing a single collected line holding `%%% x\n# h`, and the
 * unclosed-fence handling then consumed the whole thing. Both the fence AND the
 * author's line vanished from the document - not item text, not a heading, not a
 * paragraph (carve-php#791).
 *
 * A fold belongs on the last VISIBLE entry: the paragraph the invisible line
 * sits after.
 */
class UnclosedCommentFenceKeepsTheNextLineTest extends TestCase
{
    protected function html(string $source): string
    {
        return (string)preg_replace('/\s+/', ' ', (new CarveConverter())->convert($source));
    }

    public function testTheLineBelowAnUnclosedFenceSurvives(): void
    {
        $html = $this->html("- a\n  %%% x\n # h\n");

        $this->assertStringContainsString('# h', $html, "the author's line vanished");
        // Below the content column it is TEXT, not a heading.
        $this->assertStringNotContainsString('<h1', $html);
        // And the fence itself stays invisible.
        $this->assertStringNotContainsString('%%%', $html);
    }

    public function testTheSameOneNestingLevelIn(): void
    {
        $html = $this->html("- - a\n    %%% x\n   # h\n");

        $this->assertStringContainsString('# h', $html, "the author's line vanished");
        $this->assertStringNotContainsString('<h1', $html);
        $this->assertStringNotContainsString('%%%', $html);
    }

    public function testAnUnclosedFenceAtTheTopLevelIsUnchanged(): void
    {
        // The boundary: at the top level there is no content column below which
        // to fold, so `# h` really is a heading. All engines agree here and this
        // path must not move.
        $html = $this->html("a\n%%% x\n# h\n");

        $this->assertStringContainsString('<h1', $html);
        $this->assertStringNotContainsString('%%%', $html);
    }

    public function testAPlainLazyFoldIsUnchanged(): void
    {
        // No fence involved, so the fold target is the only entry there is.
        $html = $this->html("- a\nb\n");

        $this->assertStringContainsString('a b', $html);
    }

    public function testAClosedFenceInsideAnItemStillRendersNothing(): void
    {
        // A fence that CLOSES is collected as a span before this code runs, so
        // the fold-target search must not disturb it.
        $html = $this->html("- a\n  %%% x\n  body\n  %%%\n  tail\n");

        $this->assertStringNotContainsString('body', $html);
        $this->assertStringNotContainsString('%%%', $html);
        $this->assertStringContainsString('tail', $html);
    }
}
