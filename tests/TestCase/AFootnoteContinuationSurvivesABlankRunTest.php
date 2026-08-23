<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * An indented continuation after a run of blank lines stays INSIDE the footnote
 * definition (markup-carve/carve#1620).
 *
 * The definition skipper's blank-line test read only the line AFTER the blank,
 * so at two blanks the line it looked at was itself blank - neither indented nor
 * a `+` marker - and the definition ended there. The continuation was then
 * ejected to a top-level paragraph, and not to where it was written either: it
 * landed ahead of the endnotes section, so the content moved backward past
 * unrelated blocks.
 *
 * A blank run does not end an indented block anywhere else in Carve. A list
 * item, a quote and a container all keep an indented continuation across one,
 * and nothing in PART 9 §16 says a footnote definition differs.
 *
 * NOT PART 9 §11 N1a. That fires at three or more blank lines and only before a
 * LIST MARKER. This fired at two, and for a plain paragraph as readily as for a
 * list, so the hard boundary settled in markup-carve/carve#1430 is untouched -
 * and the case at the bottom shows it still firing inside a note body, because
 * the run is pushed through INTACT rather than collapsed.
 *
 * THE RULE HAD THREE SPELLINGS IN ONE FILE. The container collector scanned the
 * whole run, this skipper peeked one line, and the pre-pass sidestepped the
 * question - so a body one pass claimed another released. They now share
 * `footnoteBodyResumesAfter()`, which is why the container cases below are here
 * too: they exercise the same helper by the other caller.
 */
class AFootnoteContinuationSurvivesABlankRunTest extends TestCase
{
    private function render(string $source): string
    {
        return (new CarveConverter())->convert($source);
    }

    /**
     * What the note's own list item holds, so a case can ask what ended up
     * inside it rather than merely somewhere in the document.
     */
    private function noteBody(string $html): string
    {
        $start = mb_strpos($html, '<li id="fn1">', 0, 'UTF-8');
        $this->assertNotFalse($start, "no footnote rendered:\n" . $html);

        return mb_substr($html, (int)$start, null, 'UTF-8');
    }

    private static function sourceWithBlanks(int $count): string
    {
        return "See[^1].\n\n[^1]: a\n" . str_repeat("\n", $count) . "    b\n";
    }

    /**
     * @return array<string, array{int}>
     */
    public static function blankRunProvider(): array
    {
        return [
            // One blank always worked; two is where it broke. Three and four are
            // here because the ruling asked whether an engine ejects at three or
            // more for a DIFFERENT reason - it does not, it was this one defect
            // at every count above one.
            'one blank line' => [1],
            'two blank lines' => [2],
            'three blank lines' => [3],
            'four blank lines' => [4],
        ];
    }

    #[DataProvider('blankRunProvider')]
    public function testEveryBlankRunLengthKeepsTheContinuationInTheNote(int $count): void
    {
        $html = $this->render(self::sourceWithBlanks($count));

        $this->assertStringContainsString('<p>b', $this->noteBody($html), $html);
        // AND NOT WHERE IT WOULD HAVE LANDED. The eject did not merely move the
        // block out of the note, it moved it BACKWARD past the endnotes, so a
        // test that only asked whether the note held it would pass on a fix that
        // left the block duplicated or misplaced.
        $this->assertStringNotContainsString("<p>b</p>\n<section", $html, $html);
    }

    /**
     * THE BOUND. A run of blanks keeps the body open only for a line that could
     * continue it. A flush-left line still ends the definition, at two blanks
     * exactly as at one.
     */
    public function testAFlushLeftLineAfterABlankRunStillEndsTheDefinition(): void
    {
        $html = $this->render("See[^1].\n\n[^1]: a\n\n\nb\n");

        $this->assertStringContainsString('<p>b</p>', $html);
        $this->assertStringNotContainsString('<p>b', $this->noteBody($html), $html);
    }

    /**
     * The blank run reaches the parser as the author wrote it, so a genuine
     * §11 N1a boundary INSIDE a note body still fires: three blanks between two
     * sub-lists keep them two lists rather than one.
     *
     * This is the near miss for the fix. Collapsing the run to a single blank
     * would satisfy every case above and silently join these two lists.
     */
    public function testAHardListBoundaryInsideANoteBodyStillFires(): void
    {
        // THE SHAPE MATTERS, and getting it wrong makes this case vacuous. The
        // continuation sits at the note's OWN content column, so `- b` is a
        // SIBLING list rather than a sublist of `- a` - which is corpus
        // 410-a-footnote-continuation-survives-a-blank-run-4. Written at four
        // columns it nests either way, and the case then cannot tell a
        // collapsed run from an intact one: the first draft of this test used
        // four columns and passed against a deliberately collapsed run.
        $html = $this->render("See[^1].\n\n[^1]: - a\n\n\n\n  - b\n");

        $this->assertSame(2, mb_substr_count($this->noteBody($html), '<ul>'), $html);
        // AND THEY STAY TIGHT. Joining the two lists also loosens them, so this
        // pins the second half of what collapsing the run destroys.
        $this->assertStringNotContainsString('<li><p>a</p></li>', $html, $html);
    }

    /**
     * The other caller of the shared helper: a definition collected inside a
     * column container. That path already read the whole run before the helper
     * existed, so this is pinned to keep the extraction from quietly changing
     * it, not because it was broken.
     *
     * Verified against carve-js `main` while writing this: both engines put
     * `more` inside the note here.
     */
    public function testAContainerNestedDefinitionKeepsItsContinuationToo(): void
    {
        $html = $this->render("See[^a].\n\n- [^a]: note\n\n\n    more\n");

        $this->assertStringContainsString('<p>more', $this->noteBody($html), $html);
    }
}
