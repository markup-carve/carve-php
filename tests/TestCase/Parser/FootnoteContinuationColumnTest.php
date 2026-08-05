<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * A footnote continuation's indent is a COLUMN claim, not a character pattern.
 *
 * PART 9 §16 asks a continuation line for >= 2 columns, and §24 C1 gives a tab
 * a column value: it advances to the next multiple of 4 from wherever it
 * starts. So `<SPACE><TAB>` reaches column 4 and continues the note exactly as
 * two literal spaces or a bare tab do (spec carve#796, carve-php#887).
 *
 * This engine matched `/^(?:[ ]{2}|\t)/` - two spaces or a tab, never the
 * mixture. carve-js and carve-rs had the complementary half (a space then any
 * whitespace, so they took the mixture and refused a bare tab). Three engines,
 * three readings, and no two agreed on the pair.
 *
 * A refused continuation does not indent differently: it LEAVES the note and
 * becomes a top-level paragraph above the reference, moving content out of the
 * endnote and into the document body. That is why each row here asserts where
 * the text ENDED UP rather than how it was spaced.
 */
class FootnoteContinuationColumnTest extends TestCase
{
    protected function continues(string $indent, bool $blank = true): bool
    {
        $source = "[^a]: note\n" . ($blank ? "\n" : '') . $indent . "more\n\nsee[^a]\n";
        $html = (new CarveConverter())->convert($source);

        // Inside the note's <li>, rather than as a document-level paragraph.
        return !str_contains($html, '<p>more</p>') && str_contains($html, 'more');
    }

    public function testTwoSpacesContinueTheNote(): void
    {
        $this->assertTrue($this->continues('  '), 'two spaces reach column 2');
    }

    public function testABareTabContinuesTheNote(): void
    {
        $this->assertTrue($this->continues("\t"), 'a tab reaches column 4');
    }

    public function testASpaceThenATabContinuesTheNote(): void
    {
        $this->assertTrue($this->continues(" \t"), 'a space then a tab also reaches column 4');
    }

    public function testABareTabWithNoBlankLineBeforeItContinuesTheNote(): void
    {
        $this->assertTrue($this->continues("\t", false));
    }

    public function testASpaceThenATabWithNoBlankLineBeforeItContinuesTheNote(): void
    {
        $this->assertTrue($this->continues(" \t", false));
    }

    public function testOneSpaceIsStillNotAContinuation(): void
    {
        $this->assertFalse($this->continues(' '), 'one space reaches only column 1');
    }

    public function testAFlushLeftLineIsStillNotAContinuation(): void
    {
        $this->assertFalse($this->continues(''));
    }

    public function testTheDedentIsByColumnNotByCharacterCount(): void
    {
        // The body's own column is 2. A tab reaching column 4 leaves two
        // residual columns, which the body's blocks read themselves - so the
        // paragraph keeps no leading tab and no code block appears.
        $html = (new CarveConverter())->convert("[^a]: note\n\n\tmore\n\nsee[^a]\n");

        $this->assertStringNotContainsString('<pre>', $html);
        $this->assertMatchesRegularExpression('/<p>\s*more/', $html);
    }
}
