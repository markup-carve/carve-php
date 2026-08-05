<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * A footnote continuation's indent is a COLUMN claim, not a character count
 * (carve-php#887, spec markup-carve/carve#796).
 *
 * PART 9 §16 asks a continuation line for >= 2 columns, and §24 C1 gives a tab a
 * column value: it advances to the next multiple of 4 from wherever it starts.
 * So a bare tab reaches column 4 and continues the note exactly as two literal
 * spaces do.
 *
 * This engine matched `/^(?:[ ]{2}|\t)/` - two spaces or a bare tab, never the
 * mixture - in THREE places. carve-js and carve-rs had the complementary half
 * (a space then any whitespace, so the mixture but not the bare tab). Three
 * engines, three readings, no two agreeing on the pair.
 *
 * The failure is not cosmetic: a rejected continuation does not render with
 * different spacing, it LEAVES the note and becomes a top-level paragraph above
 * the reference, so the content moves out of the endnote and into the document
 * body.
 */
class FootnoteContinuationColumnTest extends TestCase
{
    /**
     * Did `more` stay inside the note, rather than escaping into the body?
     *
     * @param string $indent The continuation line's leading whitespace
     * @param bool $blank Whether a blank line separates the definition from it
     *
     * @return bool
     */
    private function continues(string $indent, bool $blank = true): bool
    {
        $source = "[^a]: note\n" . ($blank ? "\n" : '') . $indent . "more\n\nsee[^a]\n";
        $html = (new CarveConverter())->convert($source);

        return !str_contains($html, "<p>more</p>\n<p>see") && str_contains($html, 'more');
    }

    public function testTwoSpacesContinueTheNote(): void
    {
        // The shape every engine already agreed on - here to show the floor did
        // not move, rather than that it widened.
        $this->assertTrue($this->continues('  '));
    }

    public function testABareTabContinuesTheNote(): void
    {
        $this->assertTrue($this->continues("\t"));
    }

    public function testASpaceThenATabContinuesTheNote(): void
    {
        // The shape this engine used to refuse: neither two spaces nor a bare
        // tab, but it reaches column 4 all the same.
        $this->assertTrue($this->continues(" \t"));
    }

    public function testASpaceThenATabContinuesTheNoteWithNoBlankLine(): void
    {
        // The skip loop is a THIRD copy of the same test, and a line skipped
        // there without being collected in the body vanishes from both.
        $this->assertTrue($this->continues(" \t", blank: false));
    }

    public function testOneSpaceStillFallsShort(): void
    {
        // Column 1. This is what keeps the rule from being "any indent at all".
        $this->assertFalse($this->continues(' '));
    }

    public function testAFlushLeftLineStillEndsTheNote(): void
    {
        $this->assertFalse($this->continues(''));
    }

    public function testTheDedentTakesColumnsNotCharacters(): void
    {
        $html = (new CarveConverter())->convert("[^a]: note\n\n \tmore\n\nsee[^a]\n");

        // Asserted against the NOTE BODY, not the whole document. A bare
        // `<p>more` appears either way - inside the note when the rule works,
        // and as a top-level paragraph when the continuation is refused - so
        // matching the document would pass without the fix.
        preg_match('/<li id="fn1">(.*?)<\/li>/s', $html, $body);
        $note = $body[1] ?? '';

        // The old capture group stripped exactly two spaces or exactly one tab.
        // Stripping COLUMNS means the space AND the tab both go, so the body's
        // first line is `more` - not `<TAB>more`, which would have carried the
        // tab into the rendered text.
        $this->assertStringContainsString('<p>more', $note);
        $this->assertStringNotContainsString("<p>\tmore", $note);
        $this->assertStringNotContainsString('<pre', $html);
    }
}
