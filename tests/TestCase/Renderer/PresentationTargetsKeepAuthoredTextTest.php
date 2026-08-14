<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * docs/graceful-degradation.md states the floor as a MUST: a renderer may drop a
 * construct's INTERACTION but not its WORDS. "A reader of the Markdown export
 * should see every panel's heading. Losing the click is fine; losing the words
 * is not."
 *
 * Three kinds of authored text were dropped outright while the whole suite
 * stayed green: nothing asserted that a caption or a fence header reached the
 * non-HTML targets, so the loss was invisible to every existing test. The
 * assertions below are written against the FLOOR rather than the exact bytes, so
 * a renderer may keep changing HOW it presents these and still be held to
 * keeping them.
 *
 * carve-js and carve-rs dropped exactly the same three, so a cross-engine
 * comparison could never have caught it - agreement is not correctness
 * (carve#1179).
 *
 * PART 11 section 10e then ruled WHERE each of the three goes, which the
 * containment assertions above deliberately do not pin. The byte-exact cases
 * below carry that half, and the corpus sidecars (09-tables.md,
 * 11-fenced-code-5/6/7 .txt and .ansi) carry the rest.
 */
class PresentationTargetsKeepAuthoredTextTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function authoredTextProvider(): array
    {
        return [
            // The three that were dropped.
            'table caption' => ["|= H |\n| a |\n^ Table caption\n", 'Table caption'],
            'fence header' => ["``` js \"src/app.js\"\nlet a = 1\n```\n", 'src/app.js'],
            'grouping label' => ["``` js [Node]\na\n```\n", 'Node'],
            // Controls that already held. They are what makes the three above a
            // defect rather than a limitation of the targets: an image caption
            // and a listing caption survive the same target the table's did not.
            'image caption' => ["![alt](i.png)\n^ Figure caption\n", 'Figure caption'],
            'listing caption' => ["``` js\nlet a = 1\n```\n^ Listing caption\n", 'Listing caption'],
            'admonition title' => ["::: note \"Title\"\nbody\n:::\n", 'Title'],
        ];
    }

    #[DataProvider('authoredTextProvider')]
    public function testAuthoredTextReachesEveryPresentationTarget(string $source, string $authored): void
    {
        foreach (['markdown', 'plainText', 'ansi'] as $target) {
            $converter = match ($target) {
                'markdown' => CarveConverter::markdown(),
                'plainText' => CarveConverter::plainText(),
                default => CarveConverter::ansi(),
            };

            $this->assertStringContainsString(
                $authored,
                $converter->convert($source),
                sprintf(
                    'the %s target dropped authored text %s - see docs/graceful-degradation.md: '
                        . 'a target may drop interaction, never words',
                    $target,
                    var_export($authored, true),
                ),
            );
        }
    }

    /**
     * PART 11 section 10e T2. The caption follows the table as BODY TEXT,
     * SEPARATED BY ONE BLANK LINE - the shape an image and a listing caption
     * already use here.
     *
     * The blank line is the assertion, not the formatting. Written directly under
     * the last row the caption is read as ANOTHER ROW, so the words come back as
     * a fabricated data cell that no reader can tell from an authored one.
     * Measured through a third-party GFM reader rather than this repo's own
     * importer: `| a |` then `Table caption` yields `<td>Table caption</td>`,
     * while the blank line yields `<p>Table caption</p>`.
     */
    public function testTheTableCaptionIsSeparatedFromTheTableByABlankLine(): void
    {
        $markdown = CarveConverter::markdown()->convert("|= H |\n| a |\n^ Table caption\n");

        $this->assertSame("| H |\n| --- |\n| a |\n\nTable caption\n", $markdown);
    }

    /**
     * A table with no caption is unchanged: the fix adds a line only where the
     * author wrote one.
     */
    public function testAnUncaptionedTableIsUnchanged(): void
    {
        $markdown = CarveConverter::markdown()->convert("|= H |\n| a |\n");

        $this->assertSame("| H |\n| --- |\n| a |\n", $markdown);
    }

    /**
     * A following block keeps its blank-line separation, so the caption cannot
     * swallow it.
     */
    public function testABlockAfterACaptionedTableStaysSeparate(): void
    {
        $markdown = CarveConverter::markdown()->convert("|= H |\n| a |\n^ Cap\n\nafter\n");

        $this->assertSame("| H |\n| --- |\n| a |\n\nCap\n\nafter\n", $markdown);
    }

    /**
     * PART 11 section 10e T1. On the terminal the title and the label are a BOLD
     * STANDALONE LINE EACH above the block, title first - the shape a fenced div
     * already uses for the same two tokens - and the LANGUAGE KEEPS THE RULE LINE
     * TO ITSELF.
     *
     * The escape sequences are spelled out rather than stripped because the two
     * new lines carry the div's bold, and the rule line's trailing space sits
     * inside its own dim run. A stripped comparison would pass on an unstyled
     * line and on a lost trailing space alike.
     */
    public function testTheTerminalWritesTheFenceTitleAndLabelAboveTheRuleLine(): void
    {
        $ansi = CarveConverter::ansi()->convert("``` js \"src/app.js\" [Node]\nlet a = 1\n```\n");

        $expected = "\033[1m" . 'src/app.js' . "\033[0m\n"
            . "\n"
            . "\033[1m" . 'Node' . "\033[0m\n"
            . "\n"
            . "\033[2m" . "\u{250C}\u{2500}\u{2500} js" . ' ' . "\033[0m\n"
            . "\033[97m" . '  let a = 1' . "\033[0m\n";

        $this->assertSame($expected, $ansi);
    }

    /**
     * The control for the clause's own reasoning. Folding the two tokens into the
     * rule line was rejected because THE RULE LINE EXISTS ONLY WHEN THE FENCE HAS
     * A LANGUAGE: a titled fence without one would have needed a header invented
     * for it. So this case has to keep the title and draw no rule line, which is
     * the shape the fold could not have produced. The corpus reaches none of it -
     * all three of its fence sidecars carry a language.
     */
    public function testATitledFenceWithNoLanguageKeepsTheTitleAndDrawsNoRuleLine(): void
    {
        $source = "``` \"src/app.js\"\nlet a = 1\n```\n";

        $this->assertSame("src/app.js\n\nlet a = 1\n", CarveConverter::plainText()->convert($source));

        $ansi = CarveConverter::ansi()->convert($source);
        $this->assertSame(
            "\033[1m" . 'src/app.js' . "\033[0m\n"
                . "\n"
                . "\033[97m" . '  let a = 1' . "\033[0m\n",
            $ansi,
        );
        $this->assertStringNotContainsString("\u{250C}", $ansi);
    }

    /**
     * The other control: a fence carrying NEITHER token is untouched on both
     * targets. The rule line still carries the language alone, and nothing is
     * written above the block - so the two lines above are added only where the
     * author wrote the tokens.
     */
    public function testAFenceWithNeitherTokenIsUnchanged(): void
    {
        $source = "``` js\nlet a = 1\n```\n";

        $this->assertSame("let a = 1\n", CarveConverter::plainText()->convert($source));
        $this->assertSame(
            "\033[2m" . "\u{250C}\u{2500}\u{2500} js" . ' ' . "\033[0m\n"
                . "\033[97m" . '  let a = 1' . "\033[0m\n",
            CarveConverter::ansi()->convert($source),
        );
    }

    /**
     * PART 11 section 10d (renumbered from 10c by the ruling this pin carries,
     * which repaired a collision with the existing 10c). The attribution is the
     * quotation's SOURCE, so every
     * target keeps it ATTACHED. It used to follow as a sibling separated by a
     * blank line: the words survived, the relationship did not, and a round trip
     * produced a blockquote with no attribution at all.
     */
    public function testTheAttributionStaysAttachedToItsQuote(): void
    {
        $src = "> q\n^ Attr\n";

        // Markdown: a <footer> element inside the quote. Through a CommonMark
        // reader that opens an HTML block rather than being wrapped in a
        // paragraph, so the rendered HTML matches the HTML target's.
        $this->assertSame("> q\n>\n> <footer>Attr</footer>\n", CarveConverter::markdown()->convert($src));

        // Plain text: adjacency. No blank line, and no invented punctuation.
        $this->assertSame("\"q\"\nAttr\n", CarveConverter::plainText()->convert($src));

        // Terminal: the quote bar carried onto the attribution line, which keeps
        // its italic-dim caption styling.
        $ansi = CarveConverter::ansi()->convert($src);
        $stripped = preg_replace('/\033\[[0-9;]*m/', '', $ansi) ?? '';
        $this->assertSame("\u{2502} q\n\u{2502}\n\u{2502} Attr\n", $stripped);
    }

    /**
     * A quote with no attribution is untouched: the change adds a line only
     * where the author wrote one.
     */
    public function testAQuoteWithoutAnAttributionIsUnchanged(): void
    {
        $this->assertSame("> q\n", CarveConverter::markdown()->convert("> q\n"));
        $this->assertSame("\"q\"\n", CarveConverter::plainText()->convert("> q\n"));
    }

    /**
     * A block after an attributed quote keeps its separation.
     */
    public function testABlockAfterAnAttributedQuoteStaysSeparate(): void
    {
        $this->assertSame(
            "> q\n>\n> <footer>A</footer>\n\nafter\n",
            CarveConverter::markdown()->convert("> q\n^ A\n\nafter\n"),
        );
    }
}
