<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Renderer\MarkdownRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A delimiter run this target writes must not sit against whitespace, because
 * a run that does is not left-flanking and the reader hands the asterisks back
 * as literal text (CommonMark 6.2).
 *
 * The padding repair was there; the CLASS it read was `" \t\n\r"`, the four
 * bytes `trim()` takes as a charlist. Every other character a reader counts -
 * general category Zs above ASCII, a NO-BREAK SPACE first among them - walked
 * straight through it, and so did the U+E000 sentinel an authored `\ ` carries
 * until the very end of render(). Measured on 3dcecf3: 45 of 66 inline rows
 * and 22 of 45 wrapper rows came back without their mark (carve-php#1962,
 * ported from markup-carve/carve-js#1692).
 *
 * Three of those wrapper rows were worse than a lost mark. A whitespace-only
 * admonition title or container label wrote a bare `** **` line, which is a
 * THEMATIC BREAK: the title vanished and a horizontal rule nobody wrote stood
 * in the document.
 *
 * Every expectation below was read back through league/commonmark 2.10.1
 * before it was written down; the sweep is in the pull request.
 */
final class APaddedRunMovesEveryWhitespaceAReaderCountsTest extends TestCase
{
    /**
     * Content whose outer whitespace has to leave the delimiters.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function padded(): iterable
    {
        yield 'a plain space before a strong run' => ["a{* b*}c\n", "a **b**c\n"];
        yield 'a nbsp before a strong run' => ["a{*\u{00A0}b*}c\n", "a\u{00A0}**b**c\n"];
        yield 'a nbsp after a strong run' => ["a{*b\u{00A0}*}c\n", "a**b**\u{00A0}c\n"];
        yield 'a nbsp before an em run' => ["a{/\u{00A0}b/}c\n", "a\u{00A0}*b*c\n"];
        yield 'a nbsp before a strike run' => ["a{~\u{00A0}b~}c\n", "a\u{00A0}~~b~~c\n"];
        yield 'a form feed before a strong run' => ["a{*\u{000C}b*}c\n", "a\u{000C}**b**c\n"];
        yield 'an en quad before a strong run' => ["a{*\u{2000}b*}c\n", "a\u{2000}**b**c\n"];
        yield 'an em space before a strong run' => ["a{*\u{2003}b*}c\n", "a\u{2003}**b**c\n"];
        yield 'an ogham space before a strong run' => ["a{*\u{1680}b*}c\n", "a\u{1680}**b**c\n"];
        yield 'a narrow nbsp before a strong run' => ["a{*\u{202F}b*}c\n", "a\u{202F}**b**c\n"];
        yield 'a medium mathematical space before a strong run' => ["a{*\u{205F}b*}c\n", "a\u{205F}**b**c\n"];
        yield 'an ideographic space before a strong run' => ["a{*\u{3000}b*}c\n", "a\u{3000}**b**c\n"];
    }

    /**
     * An authored `\ ` is the same question one step removed: it reaches this
     * renderer as U+E000 and only becomes U+00A0 after the whole document is
     * written, so the flanking test never sees a space unless the sentinel is
     * in the class too.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function authoredEscape(): iterable
    {
        yield 'before a strong run' => ["a{*\\ b*}c\n", "a\u{00A0}**b**c\n"];
        yield 'after a strong run' => ["a{*b\\ *}c\n", "a**b**\u{00A0}c\n"];
    }

    /**
     * Content that is ONLY padding has no delimiter form at all, so it falls
     * back to inline HTML - the spelling this renderer already uses for
     * underline, sub, super and highlight.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function onlyPadding(): iterable
    {
        yield 'a nbsp is the whole strong' => ["a{*\u{00A0}*}c\n", "a<strong>\u{00A0}</strong>c\n"];
        yield 'an authored escape is the whole strong' => ["a{*\\ *}c\n", "a<strong>\u{00A0}</strong>c\n"];
        yield 'a whitespace-only admonition title' => ["::: note \" \"\nbody\n:::\n", "<strong> </strong>\n\nbody\n"];
        yield 'a whitespace-only container label' => ["::: [ ]\nbody\n:::\n", "<strong> </strong>\n\nbody\n"];
    }

    /**
     * The five lines that spell a delimiter run themselves instead of calling
     * the helper. Each one carried the defect independently of the three
     * inline arms, which is why routing them through the helper is half the
     * repair rather than a tidy-up.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function wrappers(): iterable
    {
        yield 'a definition term padded with nbsp' => [
            ":: \u{00A0}t\n: d\n",
            "\u{00A0}**t**\n: d\n",
        ];

        yield 'an admonition title padded with nbsp' => [
            "::: note \"\u{00A0}t\"\nbody\n:::\n",
            "\u{00A0}**t**\n\nbody\n",
        ];

        yield 'a container label padded with nbsp' => [
            "::: [\u{00A0}t]\nbody\n:::\n",
            "\u{00A0}**t**\n\nbody\n",
        ];

        yield 'a figure panel caption padded with nbsp' => [
            "::: figure\n![a](i.png)\n^ \u{00A0}p1\n\n![b](i.png)\n^ p2\n:::\n",
            "![a](i.png)\n\n\u{00A0}*p1*\n\n![b](i.png)\n\n*p2*\n",
        ];

        yield 'a figure group caption padded with nbsp' => [
            "::: figure\n![a](i.png)\n^ p1\n\n![b](i.png)\n^ p2\n:::\n^ \u{00A0}g\n",
            "![a](i.png)\n\n*p1*\n\n![b](i.png)\n\n*p2*\n\n\u{00A0}**g**\n",
        ];
    }

    /**
     * A wrapper sits on a line of its own, where ASCII padding is not content:
     * a line ending in one space says nothing and a line ending in two says
     * hard break. So the outer ASCII run is dropped there rather than moved,
     * which is what these rows separate from the nbsp rows above.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function wrapperLinesKeepNoAsciiPadding(): iterable
    {
        yield 'an admonition title padded with plain spaces' => [
            "::: note \" t \"\nbody\n:::\n",
            "**t**\n\nbody\n",
        ];
    }

    /**
     * A hard break is a BACKSLASH then a newline. The newline is in the class,
     * so moving it alone left the backslash against the closing delimiter and
     * escaped it - `x **a\\**` came back as `x *<em>a*</em>`, break gone and
     * emphasis invented. The backslash travels with its newline.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function hardBreakAtTheEndOfARun(): iterable
    {
        yield 'a strong run' => ["x {*a\\\n*} y\n", "x **a**\\\n y\n"];
        yield 'an em run' => ["x {/a\\\n/} y\n", "x *a*\\\n y\n"];
        yield 'a strike run' => ["x {~a\\\n~} y\n", "x ~~a~~\\\n y\n"];
    }

    /**
     * The class is CommonMark's, not PHP's `\s` and not "anything space-like".
     *
     * ZERO WIDTH SPACE and BYTE ORDER MARK are not whitespace to a reader, so a
     * run beside one IS left-flanking and the character is author content that
     * belongs inside the emphasis it was written in. They are the control
     * against the rejected wider class: `\s`-style matching would pad here and
     * silently move a character out of the run.
     *
     * LINE SEPARATOR and VERTICAL TAB are the same call one step harder.
     * league/commonmark blocks flanking at both; CommonMark 2.1 counts neither
     * (Zl is not Zs, and the list of non-Zs additions is tab, line feed, form
     * feed, carriage return - VT is absent while FF is present, which is why
     * the two are split above and here). Padding for them would write output
     * no reader is owed, against the spec, and would diverge from carve-js,
     * which dropped exactly these when it settled the same question.
     *
     * A hard break in the MIDDLE of a run is the fourth control: nothing is at
     * an edge, so nothing moves, and it is what says the backslash rule fires
     * on position rather than on the presence of a break.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function staysInside(): iterable
    {
        yield 'a zero width space' => ["a{*\u{200B}b*}c\n", "a**\u{200B}b**c\n"];
        yield 'a byte order mark' => ["a{*\u{FEFF}b*}c\n", "a**\u{FEFF}b**c\n"];
        yield 'a line separator' => ["a{*\u{2028}b*}c\n", "a**\u{2028}b**c\n"];
        yield 'a vertical tab' => ["a{*\u{000B}b*}c\n", "a**\u{000B}b**c\n"];
        yield 'an ordinary letter' => ["a{*b*}c\n", "a**b**c\n"];
        yield 'a hard break inside a strong run' => ["{*a\\\nb*}\n", "**a\\\nb**\n"];
    }

    #[DataProvider('padded')]
    #[DataProvider('authoredEscape')]
    #[DataProvider('onlyPadding')]
    #[DataProvider('wrappers')]
    #[DataProvider('wrapperLinesKeepNoAsciiPadding')]
    #[DataProvider('hardBreakAtTheEndOfARun')]
    #[DataProvider('staysInside')]
    public function testTheWrittenMarkdown(string $source, string $expected): void
    {
        self::assertSame($expected, $this->markdown($source));
    }

    /**
     * Stated as a property so it keeps holding if a spelling is ever revisited
     * for another reason: what stands between a matched pair of delimiters -
     * the text the reader has to see as left- and right-flanking - never
     * begins or ends with a character the reader counts as whitespace.
     *
     * The controls ride along here on purpose. A wider class would pad at the
     * zero width space and the line separator, and this property would still
     * pass; only the byte expectations above catch that, which is why both
     * tests take the same rows.
     */
    #[DataProvider('padded')]
    #[DataProvider('authoredEscape')]
    #[DataProvider('wrappers')]
    #[DataProvider('wrapperLinesKeepNoAsciiPadding')]
    #[DataProvider('hardBreakAtTheEndOfARun')]
    #[DataProvider('staysInside')]
    public function testNothingInsideAPairBeginsOrEndsWithWhitespace(string $source, string $expected): void
    {
        $markdown = $this->markdown($source);

        $pairs = [
            '/\*\*(.*?)\*\*/su',
            '/(?<!\*)\*(?!\*)(.*?)(?<!\*)\*(?!\*)/su',
            '/~~(.*?)~~/su',
        ];
        foreach ($pairs as $pair) {
            preg_match_all($pair, $markdown, $matches);
            foreach ($matches[1] as $core) {
                self::assertSame(
                    '',
                    $this->whitespaceAtEitherEndOf($core),
                    var_export($core, true) . ' pads its delimiters in ' . var_export($expected, true),
                );
            }
        }
    }

    private function whitespaceAtEitherEndOf(string $core): string
    {
        preg_match('/^' . self::READER_WHITESPACE . '*/u', $core, $lead);
        preg_match('/' . self::READER_WHITESPACE . '*$/u', $core, $trail);

        return $lead[0] . $trail[0];
    }

    /**
     * The `** **` shape specifically: it is not a weaker bold, it is an `<hr>`.
     */
    #[DataProvider('onlyPadding')]
    public function testNoLineIsAThematicBreak(string $source, string $expected): void
    {
        foreach (explode("\n", $this->markdown($source)) as $line) {
            self::assertSame(
                0,
                preg_match('/^ {0,3}(?:\*[ \t]*){3,}$/', $line),
                'thematic break written as ' . var_export($line, true)
                    . ' in a document wanted as ' . var_export($expected, true),
            );
        }
    }

    /**
     * @var string
     */
    private const READER_WHITESPACE =
        '(?:[ \t\n\f\r]|\x{00A0}|\x{1680}|[\x{2000}-\x{200A}]|\x{202F}|\x{205F}|\x{3000}|\x{E000})';

    private function markdown(string $source): string
    {
        return (new MarkdownRenderer())->render((new CarveConverter())->parse($source));
    }
}
