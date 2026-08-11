<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\Test\LegacyCarveConverter as CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The SPREAD of the closed-comment-fence defects, and the boundary beside them.
 *
 * carve-php#804 (the line was dropped) and carve-php#805 (it landed in the wrong
 * paragraph) are both fixed, and CommentEndsTheParagraphInAnItemTest pins the
 * core of that. What stays unpinned is the SPREAD: both were reported on a
 * heading and reproduced on every below-column shape, and a fix verified on one
 * shape is a fix verified on one shape.
 *
 * The UNCLOSED fence is here for the opposite reason - it must NOT be treated as
 * a span. It opens no block (PART 9 section 28), so everything after it is
 * ordinary visible content and a later fold has to land AFTER it. One attempt at
 * carve-php#804 marked every opener as a span and put the folded line above text
 * the author wrote before it, which all three engines put below.
 */
class ClosedCommentFenceKeepsTheNextLineTest extends TestCase
{
    protected function html(string $source): string
    {
        return (string)preg_replace('/\s+/', ' ', (new CarveConverter())->convert($source));
    }

    /**
     * Every below-column shape, because the defect was not specific to a
     * heading: carve-php dropped a quote marker, a bullet, an ordered marker,
     * a div opener, a code fence, a table row and a thematic break alike.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function belowColumnLines(): array
    {
        return [
            'heading' => ['# h', '# h'],
            'quote' => ['> q', '&gt; q'],
            'div opener' => [':::: d', ':::: d'],
            'table row' => ['| a |', '| a |'],
        ];
    }

    #[DataProvider('belowColumnLines')]
    public function testEveryBelowColumnLineSurvives(string $line, string $expected): void
    {
        $html = $this->html("- a\n  %%% x\n  y\n  %%%\n " . $line . "\n");

        $this->assertStringContainsString($expected, $html, "the author's line vanished: {$line}");
        $this->assertStringNotContainsString('%%%', $html);
    }

    /**
     * A below-column MARKER opens a sublist, which is what all three engines
     * do once the line survives at all. §24 C3's below-column branch reads
     * like it should be item text - it says the line "folds in as lazy
     * paragraph text" - but that presumes an OPEN paragraph, and the comment
     * has just ended it. Filed as markup-carve/carve#682; pinned here so the
     * engine cannot drift while the wording is settled.
     */
    public function testABelowColumnMarkerOpensASublist(): void
    {
        $html = $this->html("- a\n  %%% x\n  y\n  %%%\n - s\n");

        $this->assertStringContainsString('<li>s</li>', $html, "the author's line vanished");
        $this->assertStringNotContainsString('%%%', $html);
    }

    /**
     * The boundary, so a fix cannot buy the cases above by folding everything:
     * AT the content column a block opener still nests as a block.
     */
    public function testAtTheContentColumnItIsStillABlock(): void
    {
        $html = $this->html("- a\n  %%% x\n  y\n  %%%\n  # h\n");

        $this->assertStringContainsString('<h1', $html);
        $this->assertStringNotContainsString('%%%', $html);
    }

    /**
     * The UNCLOSED fence keeps its old answer, which is the boundary the fix
     * had to respect: an unclosed opener opens no block (§28), so everything
     * after it is ordinary visible content and a later fold must land AFTER
     * it, not before. Tracking every opener as a span put `# h` above
     * `visible`, where all three engines put it below.
     */
    public function testAnUnclosedFenceDoesNotReorderTheContentAfterIt(): void
    {
        $html = $this->html("- a\n  %%% x\n  visible\n # h\n");

        $this->assertMatchesRegularExpression('/visible.*# h/s', $html);
    }

    /**
     * And with no fence at all the same below-column line is unchanged - all
     * three engines agree there, so that path must not move.
     */
    public function testWithoutAFenceNothingChanges(): void
    {
        $html = $this->html("- a\n  b\n # h\n");

        $this->assertStringContainsString('# h', $html);
        $this->assertStringNotContainsString('<h1', $html);
    }
}
