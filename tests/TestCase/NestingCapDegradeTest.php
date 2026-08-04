<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\TestCase;

/**
 * PART 9 §25: past MAX_NESTING_DEPTH an opener stops recursing and becomes
 * literal paragraph text. How those lines GROUP was unstated, and the three
 * engines each chose differently - one paragraph per opener, one paragraph for
 * all of them with a trailing newline (this engine), and one without it. All
 * three satisfied the clause as written; none matched another byte for byte.
 *
 * carve#494 settled it: a flattened opener is ORDINARY paragraph text, so it
 * groups by the ordinary paragraph rule - consecutive lines form one paragraph,
 * ending at the first blank line, with no trailing whitespace carried in.
 */
class NestingCapDegradeTest extends TestCase
{
    /**
     * Three past the cap. One line would not distinguish "one paragraph each"
     * from "one paragraph for all of them".
     */
    private const OVER_CAP = BlockParser::MAX_NESTING_DEPTH + 3;

    private function openers(int $count): string
    {
        return str_repeat(":::: note\n", $count);
    }

    /**
     * @return array<string>
     */
    private function paragraphs(string $html): array
    {
        preg_match_all('#<p>.*?</p>#s', $html, $matches);

        return $matches[0];
    }

    public function testConsecutiveOverCapOpenersAndTheTextAfterThemAreOneParagraph(): void
    {
        $html = (new CarveConverter())->convert($this->openers(self::OVER_CAP) . "x\n");

        $this->assertSame(
            ['<p>:::: note' . "\n" . ':::: note' . "\n" . ':::: note' . "\n" . 'x</p>'],
            $this->paragraphs($html),
        );
    }

    public function testNoTrailingNewlineBeforeTheClosingTag(): void
    {
        $html = (new CarveConverter())->convert($this->openers(self::OVER_CAP));

        $this->assertSame(
            ['<p>:::: note' . "\n" . ':::: note' . "\n" . ':::: note</p>'],
            $this->paragraphs($html),
        );
        $this->assertStringNotContainsString("\n</p>", $html);
    }

    public function testTheFlattenedParagraphEndsAtTheFirstBlankLine(): void
    {
        $html = (new CarveConverter())->convert($this->openers(self::OVER_CAP) . "\ny\n");

        $this->assertSame(
            [
                '<p>:::: note' . "\n" . ':::: note' . "\n" . ':::: note</p>',
                '<p>y</p>',
            ],
            $this->paragraphs($html),
        );
    }

    public function testAHeadingPastTheCapIsTextAndGroupsWithTheRun(): void
    {
        $html = (new CarveConverter())->convert($this->openers(self::OVER_CAP) . "# h\n");

        $this->assertSame(
            ['<p>:::: note' . "\n" . ':::: note' . "\n" . ':::: note' . "\n" . '# h</p>'],
            $this->paragraphs($html),
        );
    }

    /**
     * Silent content loss does not announce itself in a shape assertion, and it
     * is the failure this degrade path has actually shipped elsewhere: one
     * engine emitted byte-identical output whether 5 or 7800 openers sat past
     * the cap.
     */
    public function testEveryOverCapOpenerSurvivesAsText(): void
    {
        $html = (new CarveConverter())->convert($this->openers(self::OVER_CAP) . "x\n");

        $this->assertSame(
            self::OVER_CAP,
            substr_count($html, 'note'),
            'container titles plus the flattened lines',
        );
    }

    public function testTheContainersBelowTheCapAreStillContainers(): void
    {
        $html = (new CarveConverter())->convert($this->openers(self::OVER_CAP) . "x\n");

        $this->assertSame(BlockParser::MAX_NESTING_DEPTH, substr_count($html, '<aside'));
    }
}
