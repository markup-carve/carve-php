<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A reference definition's destination is `link_destination`.
 *
 * The definition is built from the same production as the inline tail, so a
 * parenthesis reaches it only through `balanced_parens` or
 * `destination_escape`. A lone one leaves content over, and CARVE-P3-005
 * anchors the line at its newline: leftover content makes the production fail
 * and the line is an ordinary paragraph.
 *
 * The reader had taken the whole run up to the first whitespace, so it defined
 * `[a]: a(b` with the unbalanced parenthesis in the href and kept the backslash
 * of `[a]: a\(b` (markup-carve/carve-php#2190).
 *
 * THE PROSE ROWS ARE ASSERTED AS WHOLE RENDERINGS. "No link" also describes an
 * engine that dropped the line, and the fallback the anchor asks for is the
 * author's line surviving as text.
 */
class ADefinitionDestinationIsALinkDestinationTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function prosaicProvider(): array
    {
        return [
            'an unclosed parenthesis' => [
                "[a]: a(b\n\n[x][a]\n",
                "<p>[a]: a(b</p>\n<p>[x][a]</p>\n",
            ],
            'a parenthesis with no opener' => [
                "[a]: a)b\n\n[x][a]\n",
                "<p>[a]: a)b</p>\n<p>[x][a]</p>\n",
            ],
            // The inner pair balances and the outer opener does not, so a depth
            // counter that only asks "did every `)` find an opener" reads this
            // as a definition. `(c)` renders as the copyright sign once the line
            // is prose, which is what the smart-typography pass does to it.
            'an outer opener left unclosed' => [
                "[a]: a(b(c)d\n\n[x][a]\n",
                "<p>[a]: a(b\u{00a9}d</p>\n<p>[x][a]</p>\n",
            ],
            // The counts match and the order does not, so a check on the final
            // depth alone reads this as a definition.
            'a closer before its opener' => [
                "[a]: )a(\n\n[x][a]\n",
                "<p>[a]: )a(</p>\n<p>[x][a]</p>\n",
            ],
            // The title slot opens only after the destination, so a run that is
            // not a destination is not rescued by what follows it.
            'an unclosed parenthesis before a title' => [
                "[a]: a(b \"T\"\n\n[x][a]\n",
                "<p>[a]: a(b \u{201c}T\u{201d}</p>\n<p>[x][a]</p>\n",
            ],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function destinationProvider(): array
    {
        return [
            'a balanced pair' => ["[a]: a(b)c\n\n[x][a]\n", 'a(b)c'],
            'nested balanced pairs' => ["[a]: a((b))c\n\n[x][a]\n", 'a((b))c'],
            'an escaped opener' => ["[a]: a\\(b\n\n[x][a]\n", 'a(b'],
            'an escaped closer' => ["[a]: a\\)b\n\n[x][a]\n", 'a)b'],
            'an escaped backslash' => ["[a]: a\\\\b\n\n[x][a]\n", 'a\\b'],
            // A backslash before anything else is an ordinary destination
            // character, so URLs full of backslashes need no doubling.
            'a backslash before an ordinary character' => ["[a]: a\\b\n\n[x][a]\n", 'a\\b'],
        ];
    }

    protected function html(string $source): string
    {
        return (new CarveConverter())->convert($source);
    }

    #[DataProvider('prosaicProvider')]
    public function testARunThatIsNotADestinationLeavesTheLineAsProse(string $source, string $expected): void
    {
        $this->assertSame($expected, $this->html($source));
    }

    #[DataProvider('destinationProvider')]
    public function testTheDefinitionCarriesTheResolvedDestination(string $source, string $href): void
    {
        $this->assertSame(
            '<p><a href="' . $href . '">x</a></p>' . "\n",
            $this->html($source),
        );
    }

    /**
     * The inline tail has always read the production, and it is the control:
     * the same run answers the same way on both sides of the language.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function inlineControlProvider(): array
    {
        return [
            'an unclosed parenthesis' => ['[t](a(b)', "<p>[t](a(b)</p>\n"],
            'an escaped opener' => ['[t](a\\(b)', '<p><a href="a(b">t</a></p>' . "\n"],
            'an escaped closer' => ['[t](a\\)b)', '<p><a href="a)b">t</a></p>' . "\n"],
        ];
    }

    #[DataProvider('inlineControlProvider')]
    public function testTheInlineTailReadsTheSameRun(string $source, string $expected): void
    {
        $this->assertSame($expected, $this->html($source));
    }

    /**
     * An image reference resolves the same entry (CARVE-P3-008), so it takes
     * the resolved destination too.
     */
    public function testAnImageReferenceTakesTheResolvedDestination(): void
    {
        $this->assertSame(
            '<img src="/i(x.png" alt="alt">' . "\n",
            $this->html("![alt][a]\n\n[a]: /i\\(x.png\n"),
        );
    }

    /**
     * The writer re-escapes what the reader resolved.
     *
     * Writing the resolved value bare would emit `[a]: a(b`, which re-parses as
     * a paragraph - the definition and every link resolving it would be lost on
     * one `fmt` pass.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function roundTripProvider(): array
    {
        return [
            'an escaped opener' => ["[x][a]\n\n[a]: a\\(b\n", "[x][a]\n\n[a]: a\\(b\n"],
            'an escaped closer' => ["[x][a]\n\n[a]: a\\)b\n", "[x][a]\n\n[a]: a\\)b\n"],
            'a balanced pair is left bare' => ["[x][a]\n\n[a]: a(b)c\n", "[x][a]\n\n[a]: a(b)c\n"],
            'an escaped opener under a title and a block' => [
                "[x][a]\n\n[a]: a\\(b \"T\" {.c}\n",
                "[x][a]\n\n[a]: a\\(b \"T\" {.c}\n",
            ],
        ];
    }

    #[DataProvider('roundTripProvider')]
    public function testTheWriterReEscapesTheDestination(string $source, string $expected): void
    {
        $written = CarveConverter::create(renderer: new CarveRenderer())->convert($source);
        $this->assertSame($expected, $written);
        $this->assertSame(
            $this->html($source),
            $this->html($written),
            'the written line resolves to the same document',
        );
    }

    public function testEveryRowIsStillCovered(): void
    {
        $this->assertCount(5, self::prosaicProvider());
        $this->assertCount(6, self::destinationProvider());
        $this->assertCount(3, self::inlineControlProvider());
        $this->assertCount(4, self::roundTripProvider());
    }
}
