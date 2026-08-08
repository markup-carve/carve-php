<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\Heading;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Node\Inline\SoftBreak;
use MarkupCarve\Carve\Node\Inline\Text;
use MarkupCarve\Carve\Renderer\AnsiRenderer;
use MarkupCarve\Carve\Renderer\MarkdownRenderer;
use MarkupCarve\Carve\Renderer\PlainTextRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PART 9 §29, C0 CONTROLS ON THE RENDER TARGETS.
 *
 * After markup-carve/carve#963 the whitespace of this language is exactly
 * U+0020, U+0009, U+000A and U+000D. EVERY other C0 control - U+0000..U+0008,
 * U+000B, U+000C, U+000E..U+001F - is ordinary CONTENT, and §29 then answers
 * per target: HTML emits (T1), Markdown emits (T2), plain text emits (T3), the
 * terminal strips (T4).
 *
 * THE SUBJECT IS A CLASS, NOT TWO CHARACTERS. The ticket named the vertical tab
 * and the form feed because those are the two the whitespace clause happens to
 * mention; the class is 28 characters wide once NUL is set aside, and every one of
 * them is asserted here, built from an escape and compared on bytes.
 *
 * Expectations are carve-rs `cdac42c` (which landed §29 as carve-rs#822),
 * measured construct by construct. carve-js `76dadb6` still strips on all three
 * non-HTML targets and is the remaining engine.
 */
class MarkdownAndPlainEmitTheC0ControlsTest extends TestCase
{
    /**
     * The whole class: C0 minus the three whitespace characters in it.
     *
     * U+0000 is absent for a reason that is not a strip: NUL is replaced by
     * U+FFFD while the source is read, in every engine, so no target ever sees
     * it. Naming that here keeps its absence from reading as a 28th exception.
     *
     * @return list<array{int}>
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
     * Every construct that carries author text to an output byte. A single
     * paragraph would have passed the whole class while a table cell, a footnote
     * body and a caption still deleted it - each reaches the output through its
     * own trim.
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
            'heading across a soft wrap' => ["# a%s\nb\n"],
            'code span' => ["`a%sb`\n"],
            'trailing in a code span' => ["`ab%s`\n"],
            'fenced code' => ["```\na%sb\n```\n"],
            'trailing in fenced code' => ["```\nab%s\n```\n"],
            'emphasis' => ["/a%sb/\n"],
            'link text' => ["[a%sb](/u)\n"],
            'link destination' => ["[t](/u%sv)\n"],
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
    public function testTheMarkdownTargetEmitsTheWholeClass(int $codepoint): void
    {
        $char = mb_chr($codepoint, 'UTF-8');
        foreach (self::constructs() as $name => [$template]) {
            $this->assertStringContainsString(
                $char,
                CarveConverter::markdown()->convert(sprintf($template, $char)),
                sprintf('U+%04X was deleted from the %s construct', $codepoint, $name),
            );
        }
    }

    #[DataProvider('nonWhitespaceC0Controls')]
    public function testThePlainTargetEmitsTheWholeClass(int $codepoint): void
    {
        $char = mb_chr($codepoint, 'UTF-8');
        foreach (self::constructs() as $name => [$template]) {
            // Plain text drops a link TITLE and a link DESTINATION entirely -
            // it emits neither - so those two say nothing about §29 here.
            if ($name === 'link destination') {
                continue;
            }
            $this->assertStringContainsString(
                $char,
                CarveConverter::plainText()->convert(sprintf($template, $char)),
                sprintf('U+%04X was deleted from the %s construct', $codepoint, $name),
            );
        }
    }

    #[DataProvider('nonWhitespaceC0Controls')]
    public function testTheTerminalTargetStillStripsTheWholeClass(int $codepoint): void
    {
        // T4, and the row that must NOT move: the terminal is the one consumer
        // that ACTS on the character. A form feed feeds or clears, and U+001B
        // introduces a sequence that can move the cursor, rewrite earlier output
        // or reach the clipboard.
        $char = mb_chr($codepoint, 'UTF-8');
        $this->assertStringNotContainsString($char, CarveConverter::ansi()->convert(sprintf("a%sb\n", $char)));
    }

    public function testTheCarriageReturnIsWHITESPACEAndStaysStripped(): void
    {
        // CONTROL against over-reach. U+000D is not in the class: carve#963 made
        // it whitespace, so §29 excludes it and the narrowing was written so it
        // could not be swept in with the others.
        //
        // Built as a NODE, not parsed. The parser normalizes line endings, so a
        // carriage return in SOURCE is a newline long before a renderer sees it
        // - which means a parse-based probe passes whether or not the strip
        // covers it, and a mutation removing U+000D survived one. The reachable
        // door is the one a host uses: a tree built through the API, which is how
        // NonHtmlRendererSecurityTest reaches every other leaf field too.
        $document = new Document();
        $paragraph = new Paragraph();
        $paragraph->appendChild(new Text("a\rb"));
        $document->appendChild($paragraph);

        foreach ([new MarkdownRenderer(), new PlainTextRenderer(), new AnsiRenderer(useColors: false)] as $renderer) {
            $this->assertStringNotContainsString("\r", $renderer->render($document));
        }
    }

    public function testDELAndTheC1ControlsStayStrippedOnEveryNonHtmlTarget(): void
    {
        // §29 T5 puts DEL and the C1 controls OUTSIDE that section, so the
        // narrowing must not reach them: CSI (U+009B) and OSC (U+009D) are
        // single-character forms of the sequences §25 exists to stop. Measured
        // today, carve-rs cdac42c refuses them on Markdown and plain too - the
        // ticket's premise that this engine stands alone here is stale.
        foreach ([CarveConverter::markdown(), CarveConverter::plainText(), CarveConverter::ansi()] as $converter) {
            foreach (["\u{007F}", "\u{0080}", "\u{009B}", "\u{009D}", "\u{009F}"] as $blocked) {
                $this->assertStringNotContainsString($blocked, $converter->convert('a' . $blocked . "b\n"));
            }
        }
    }

    public function testTheVerticalTabSurvivesTheTrimAtEveryBlockEDGE(): void
    {
        // The half a narrowed strip alone does NOT deliver, and the reason the
        // change is bigger than one regex: PHP's DEFAULT trim charlist is
        // " \t\n\r\0\x0B", so every `trim()` in these two writers deleted a
        // vertical tab that landed at the start or end of a block. The strip
        // stopped removing it and the trim went on removing it, in 33 of the
        // construct/target rows measured against carve-rs.
        $vt = "\u{000B}";
        foreach (['markdown' => CarveConverter::markdown(), 'plain' => CarveConverter::plainText()] as $name => $converter) {
            foreach (["%sab\n", "ab%s\n", "> %sab\n", "> ab%s\n", "- ab%s\n", "| ab%s |\n"] as $template) {
                $this->assertStringContainsString(
                    $vt,
                    $converter->convert(sprintf($template, $vt)),
                    sprintf('the %s target trimmed an edge vertical tab from %s', $name, json_encode($template)),
                );
            }
        }
    }

    public function testTheFormFeedSurvivesPcresIdeaOfWhitespace(): void
    {
        // The other half, from the other side: PCRE's `\s` matches the form feed
        // and the vertical tab, so a pattern standing in for "whitespace" ate a
        // character the language calls content. The site is the Markdown heading
        // folder, which collapses a soft wrap with `/\s*\n\s*/`.
        //
        // Built as a NODE, and the character sits ADJACENT to the break. Neither
        // detail is decoration: after markup-carve/carve#451 a heading ends at
        // the newline, so a PARSED heading never spans lines and the folder is
        // unreachable from source at all; and a character away from the break is
        // not what `\s*` is looking at. A probe that parsed `# a<FF>\nb` and put
        // the character anywhere else passed with the pattern restored.
        foreach (["\u{000C}", "\u{000B}"] as $char) {
            $document = new Document();
            $heading = new Heading(1);
            $heading->appendChild(new Text('a'));
            $heading->appendChild(new SoftBreak());
            $heading->appendChild(new Text($char . 'b'));
            $document->appendChild($heading);

            $this->assertStringContainsString($char, (new MarkdownRenderer())->render($document));
        }
    }
}
