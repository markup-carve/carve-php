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
     * The caption goes on its own line under the table rather than being glued to
     * the last row - the shape an image and a listing caption already use here.
     */
    public function testTheTableCaptionIsItsOwnLine(): void
    {
        $markdown = CarveConverter::markdown()->convert("|= H |\n| a |\n^ Table caption\n");

        $this->assertSame("| H |\n| --- |\n| a |\nTable caption\n", $markdown);
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

        $this->assertSame("| H |\n| --- |\n| a |\nCap\n\nafter\n", $markdown);
    }

    /**
     * The terminal joins the header and label to the rule line it already draws,
     * so a captioned fence still reads as one block rather than three.
     */
    public function testTheTerminalRuleCarriesTheHeaderAndLabel(): void
    {
        $ansi = CarveConverter::ansi()->convert("``` js \"src/app.js\" [Node]\nlet a = 1\n```\n");
        $plain = preg_replace('/\033\[[0-9;]*m/', '', $ansi) ?? '';

        $this->assertStringContainsString('┌── js src/app.js [Node]', $plain);
    }

    /**
     * PART 11 section 10c. The attribution is the quotation's SOURCE, so every
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
