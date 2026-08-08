<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Node\Inline\SoftBreak;
use MarkupCarve\Carve\Node\Inline\Text;
use MarkupCarve\Carve\Parser\BlockParser;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use MarkupCarve\Carve\Renderer\SoftBreakMode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PART 9 §29 T1 ON THE HTML TARGET, AND THE SENTINEL COLLISION UNDER IT.
 *
 * The HTML renderer marked inline line boundaries with the FIXED control bytes
 * U+0000 and U+0001, on the claim that "control bytes never appear in escaped
 * HTML output". §29 T1 says this target does NOT strip a non-whitespace C0
 * control, so an author's U+0001 reached the output, was indistinguishable from
 * the soft-break guard, and came back out as whatever SoftBreakMode replaces -
 * a newline by default, a `<br>` in Break mode. The reader saw a line break the
 * author did not write (markup-carve/carve-php#1077).
 *
 * THE ASSERTION HAS TO BE ON THE BYTES. The character was never DROPPED, it was
 * SUBSTITUTED, so "the control is absent from the output" passed against the
 * broken renderer. Every row below asserts the character is PRESENT.
 *
 * The guards are now chosen per render from private-use code points the
 * document does not contain, the shape carve#678 settled for the canonical
 * writer, so they cannot collide by construction. That moves the fix to the
 * sentinel pair rather than to the constructs, and the construct rows below are
 * there to prove the choice holds for a whole document rather than for one
 * paragraph.
 *
 * carve-js `62e0e5a` and carve-rs `39e6968` both emit U+0001 unchanged on this
 * target and are the oracle for every row.
 */
class HtmlBreakGuardsAreNotFixedControlBytesTest extends TestCase
{
    /**
     * The whole class: C0 minus the three whitespace characters in it.
     *
     * U+0000 is absent here for a reason that is not a strip: NUL is replaced
     * by U+FFFD while the SOURCE is read, so no parsed document can carry one.
     * It has its own row below, through the door that skips the parser.
     *
     * @return array<string, array{int}>
     */
    public static function nonWhitespaceC0Controls(): array
    {
        $rows = [];
        for ($cp = 0x01; $cp <= 0x1F; $cp++) {
            if ($cp === 0x09 || $cp === 0x0A || $cp === 0x0D) {
                continue;
            }
            $rows[sprintf('U+%04X', $cp)] = [$cp];
        }

        return $rows;
    }

    /**
     * Every construct that carries author text to an output byte.
     *
     * All 29 diverged from the sibling engines before the fix and none of the
     * other 27 controls did, which is what a whole-class sweep is for: the
     * defect is ONE substitution at the render exit, reachable from every
     * construct, not 29 independent strips.
     *
     * @return array<string, array{string}>
     */
    public static function constructs(): array
    {
        return [
            'paragraph' => ["a%sb\n"],
            'leading in paragraph' => ["%sab\n"],
            'trailing in paragraph' => ["ab%s\n"],
            'heading' => ["# a%sb\n"],
            'trailing in heading' => ["# ab%s\n"],
            'code span' => ["`a%sb`\n"],
            'trailing in a code span' => ["`ab%s`\n"],
            'fenced code' => ["```\na%sb\n```\n"],
            'trailing in fenced code' => ["```\nab%s\n```\n"],
            'emphasis' => ["/a%sb/\n"],
            'link text' => ["[a%sb](/u)\n"],
            'link destination' => ["[t](/u%sv)\n"],
            'link title' => ["[t](/u \"a%sb\")\n"],
            'image alt' => ["![a%sb](i.png)\n"],
            'blockquote' => ["> a%sb\n"],
            'leading in a blockquote' => ["> %sab\n"],
            'trailing in a blockquote' => ["> ab%s\n"],
            'list item' => ["- a%sb\n"],
            'trailing in a list item' => ["- ab%s\n"],
            'table cell' => ["| a%sb |\n"],
            'trailing in a table cell' => ["| ab%s |\n"],
            'footnote body' => ["x[^f]\n\n[^f]: a%sb\n"],
            'trailing in a footnote body' => ["x[^f]\n\n[^f]: ab%s\n"],
            'definition term' => [":: a%sb\n: d\n"],
            'trailing in a definition body' => [":: t\n: ab%s\n"],
            'caption' => ["::: figure\n![a](i.png)\n^ a%sb\n:::\n"],
            'trailing in a caption' => ["::: figure\n![a](i.png)\n^ ab%s\n:::\n"],
            'math' => ["\$`a%sb`\n"],
            'line block' => ["| %sab\n"],
        ];
    }

    #[DataProvider('nonWhitespaceC0Controls')]
    public function testTheHtmlTargetEmitsTheWholeClassInEveryConstruct(int $codepoint): void
    {
        $char = (string)mb_chr($codepoint, 'UTF-8');
        foreach (self::constructs() as $name => [$template]) {
            $this->assertStringContainsString(
                $char,
                (new CarveConverter())->convert(sprintf($template, $char)),
                sprintf('U+%04X did not survive the %s construct on the HTML target', $codepoint, $name),
            );
        }
    }

    #[DataProvider('softBreakModes')]
    public function testAnAuthoredU0001IsNotReadAsASoftBreakInAnyMode(SoftBreakMode $mode, string $expected): void
    {
        // The harm, spelled per mode: the guard is replaced ACCORDING TO the
        // mode, so the same collision showed up as a newline, a space and a
        // `<br>`. The `<br>` row is the loudest - a control byte in the middle
        // of a word became a visible line break.
        $renderer = new HtmlRenderer();
        $renderer->setSoftBreakMode($mode);
        $html = $renderer->render((new BlockParser())->parse("a\x01b\nc\n"));

        $this->assertStringContainsString("\x01", $html);
        $this->assertSame($expected, $html);
    }

    /**
     * @return array<string, array{0: \MarkupCarve\Carve\Renderer\SoftBreakMode, 1: string}>
     */
    public static function softBreakModes(): array
    {
        return [
            'newline' => [SoftBreakMode::Newline, "<p>a\x01b\nc</p>\n"],
            'space' => [SoftBreakMode::Space, "<p>a\x01b c</p>\n"],
            'break' => [SoftBreakMode::Break, "<p>a\x01b<br>\nc</p>\n"],
        ];
    }

    public function testTheNeutralGuardIsReachableThroughTheHostApi(): void
    {
        // The ticket asked whether the OTHER guard is reachable at all, since
        // the parser rewrites an input NUL to U+FFFD. It is: a host that builds
        // a tree through the node API never passes that rewrite, and before the
        // fix this document rendered `a`, a newline and `b`. Answering it by
        // parsing source would have said "unreachable" and left a guard that
        // corrupts anyway - the probe would have gone through the wrong door.
        $document = new Document();
        $paragraph = new Paragraph();
        $paragraph->appendChild(new Text("a\x00b"));
        $paragraph->appendChild(new SoftBreak());
        $paragraph->appendChild(new Text('c'));
        $document->appendChild($paragraph);

        $this->assertSame("<p>a\x00b\nc</p>\n", (new HtmlRenderer())->render($document));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function guardCandidates(): array
    {
        return [
            'U+E001' => ["\u{E001}"],
            'U+E002' => ["\u{E002}"],
            'U+E003' => ["\u{E003}"],
            'U+E004' => ["\u{E004}"],
        ];
    }

    #[DataProvider('guardCandidates')]
    public function testAnAuthoredPrivateUseCharacterSurvivesAndTheSoftBreakStillApplies(string $char): void
    {
        // A guard chosen per document has to be PROVED against a document that
        // contains the obvious candidates: moving the collision from U+0001 to
        // U+E001 would be no fix at all. The document also carries a soft break,
        // so a run that only checked survival could not pass by abandoning the
        // guard mechanism.
        $html = (new CarveConverter())->convert('a' . $char . "b\nc\n");

        $this->assertSame('<p>a' . $char . "b\nc</p>\n", $html);
    }

    #[DataProvider('guardCandidates')]
    public function testAFragmentRenderedOnItsOwnPicksItsOwnGuards(string $char): void
    {
        // The fragment entries are top-level renders too when no outer render is
        // active, and each has to pick. Without that, an extension rendering an
        // isolated run kept whatever pair the previous document left behind.
        $renderer = new HtmlRenderer();
        $html = $renderer->renderInlineNodesFragment([
            new Text('a' . $char . 'b'),
            new SoftBreak(),
            new Text('c'),
        ]);

        $this->assertSame('a' . $char . "b\nc", $html);
    }

    public function testU00E0IsUnchangedAndStaysTheOtherHalfOfCarve678(): void
    {
        // A CONTROL against over-reach, and a boundary the picker is written to
        // respect. U+E000 is the parser's in-band carrier for a non-breaking
        // space, shared across the renderers, so an authored one is already
        // conflated with a parsed nbsp before any guard runs. That is carve#678's
        // remaining half and NOT this change: the behavior below is the same
        // before and after.
        //
        // The guards start at U+E001 so they can never be the carrier, and that
        // is intent rather than protection: a mutation starting the run at
        // U+E000 SURVIVES the whole suite, because the carrier is a string in
        // the tree and the scan therefore moves the run off it in every document
        // that has one. Recorded so the next reader does not mistake the
        // constant for the thing keeping this row green.
        $this->assertSame(
            "<p>a&nbsp;b\nc</p>\n",
            (new CarveConverter())->convert("a\u{E000}b\nc\n"),
        );
    }
}
