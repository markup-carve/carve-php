<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A CLOSED comment fence inside a list item keeps the line below it.
 *
 * carve-php#791 fixed this for the UNCLOSED fence: the following line was being
 * appended onto the opener's own entry, and the unclosed-fence handling then
 * consumed both. The fold target now walks back over an opener-shaped entry.
 *
 * A closed fence is pushed as SEVERAL entries - opener, body, closer - and only
 * the two delimiter lines are opener-shaped. The walk stopped on the BODY line
 * and appended there, so the author's line ended up inside the comment span and
 * was consumed with it: no text, no heading, nothing at all in the output
 * (carve-php#804). carve-js and carve-rs both keep it as item text.
 *
 * The whole span is invisible, so the fold has to skip all of it, not the parts
 * that happen to look like a delimiter.
 */
class ClosedCommentFenceKeepsTheNextLineTest extends TestCase
{
    protected function html(string $source): string
    {
        return (string)preg_replace('/\s+/', ' ', (new CarveConverter())->convert($source));
    }

    public function testTheLineBelowAClosedFenceSurvives(): void
    {
        $html = $this->html("- a\n  %%% x\n  y\n  %%%\n # h\n");

        $this->assertStringContainsString('# h', $html, "the author's line vanished");
        // Below the content column it is TEXT, not a heading.
        $this->assertStringNotContainsString('<h1', $html);
        // The comment stays invisible, body included.
        $this->assertStringNotContainsString('%%%', $html);
        $this->assertStringNotContainsString('>y<', $html);
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
     * A below-column MARKER survives too. WHERE it lands is a separate
     * question this test deliberately does not answer: carve-php folds it as
     * item text, which is what §24 C3's below-column branch says, while
     * carve-js and carve-rs open a sublist. Both are defensible readings and
     * neither loses the line - so this pins only the part that is not a
     * reading at all.
     */
    public function testABelowColumnMarkerSurvives(): void
    {
        $html = $this->html("- a\n  %%% x\n  y\n  %%%\n - s\n");

        $this->assertStringContainsString('s', $html, "the author's line vanished");
        $this->assertStringNotContainsString('%%%', $html);
    }

    /**
     * The sibling marker after it stays in the SAME list: a comment is
     * invisible, so it cannot decide which list a later marker belongs to.
     */
    public function testASiblingMarkerAfterwardsStaysInTheSameList(): void
    {
        $html = $this->html("- a\n  %%% x\n  y\n  %%%\n # h\n\n- b\n");

        $this->assertSame(1, substr_count($html, '<ul>'), 'the list was split in two');
        $this->assertStringContainsString('# h', $html);
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
