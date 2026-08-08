<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PART 9R R1: a collapsed `[text][]` reaches a heading by the heading's
 * RENDERED PLAIN TEXT, and "ON THIS PATH THE LABEL ENTERS AS ITS RENDERED PLAIN
 * TEXT, the same string kind the heading side already enters as".
 *
 * The label used to be reduced by a CHARACTER CLASS over its source,
 * `[_*~^+={}`\[\]]`, which answers only for the delimiters someone remembered
 * to list. Four shapes it does not list left the reference unresolvable
 * entirely - the paragraph rendered as literal source - while `*bold*` and
 * `` `code()` ``, the two the conformance corpus samples, happened to be on the
 * list and passed (markup-carve/carve#1011):
 *
 * - EMPHASIS. Carve's emphasis delimiter is `/`, which no character class
 *   could carry: stripping it would eat every path and URL in a label.
 * - AN ESCAPE. `\_` reduces to `_` in the heading and the class deletes the
 *   `_` while leaving the backslash, so the two sides met at neither spelling.
 * - A NESTED LINK. Dropping `[` and `]` leaves the destination `(/y)` behind.
 * - A SMART APOSTROPHE. The heading holds the curly glyph and the label the
 *   typed `'`, and no character class relates them.
 *
 * The reduction is now the heading side's own extraction over the PARSED label,
 * so it needs no list: whatever the heading contributes, the label contributes.
 */
class CollapsedReferenceLabelIsRenderedTextTest extends TestCase
{
    protected function html(string $source): string
    {
        return (new CarveConverter())->convert($source);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function labelProvider(): array
    {
        return [
            'emphasis' => ['# an /em/ heading', '[an /em/ heading][]', '#an-em-heading'],
            'escape' => ['# a\_b heading', '[a\_b heading][]', '#a-b-heading'],
            'nested link' => ['# a [x](/y) b', '[a [x](/y) b][]', '#a-x-b'],
            'symbol' => ['# a :smile: b', '[a :smile: b][]', '#a-b'],
            'code beside emphasis' => ['# a `c()` /d/ e', '[a `c()` /d/ e][]', '#a-c-d-e'],
            'symbol beside emphasis' => ['# a :smile: /b/ c', '[a :smile: /b/ c][]', '#a-b-c'],
            'smart apostrophe' => ["# it's a heading", "[it's a heading][]", '#it-s-a-heading'],
            'inline literal' => ['# a !`Cat` b', '[a !`Cat` b][]', '#a-Cat-b'],
            'strong' => ['# a *b* c', '[a *b* c][]', '#a-b-c'],
            'strike beside highlight' => ['# a ~b~ =c= d', '[a ~b~ =c= d][]', '#a-b-c-d'],
            'braced superscript' => ['# a {^x^} b', '[a {^x^} b][]', '#a-x-b'],
        ];
    }

    #[DataProvider('labelProvider')]
    public function testALabelHoldingInlineMarkupReachesTheHeading(
        string $heading,
        string $reference,
        string $href,
    ): void {
        $this->assertStringContainsString(
            'href="' . $href . '"',
            $this->html($heading . "\n\n" . $reference . "\n"),
        );
    }

    /**
     * The reference is a LINK, not literal source. Asserting the href alone
     * would pass on a document that rendered the brackets literally and merely
     * happened to carry the same fragment somewhere else on the page.
     */
    #[DataProvider('labelProvider')]
    public function testTheBracketedRunIsNotLeftAsLiteralSource(
        string $heading,
        string $reference,
        string $href,
    ): void {
        unset($href);

        $this->assertStringNotContainsString(
            '<p>' . $reference . '</p>',
            $this->html($heading . "\n\n" . $reference . "\n"),
        );
    }

    /**
     * `ref` is the RESOLUTION KEY (PART 12 section 3a, ruled at carve#962), so
     * a label carrying markup publishes the heading's rendered text and not the
     * spelling the author typed - `rawRef` already holds that.
     */
    public function testTheResolutionKeyIsPublishedAsRef(): void
    {
        $converter = new CarveConverter(parser: new BlockParser(false, false, false, true));
        $json = (new AstCodec())->encode($converter->parse("# an /em/ heading\n\n[an /em/ heading][]\n"));
        $link = $json['children'][1]['children'][0];

        $this->assertSame('link', $link['type']);
        $this->assertArrayHasKey('ref', $link);
        $this->assertSame('an em heading', $link['ref']);
        $this->assertSame('[an /em/ heading][]', $link['rawRef']);
        $this->assertSame('#an-em-heading', $link['href']);
    }

    /**
     * THE STRIP IS SCOPED TO THE HEADING INDEX (R1). An authored
     * `[label]: url` line is matched by the label AS WRITTEN, so widening the
     * label reduction must not reach it in either direction.
     */
    public function testAnAuthoredDefinitionStillMatchesTheLabelAsWritten(): void
    {
        $this->assertStringContainsString(
            'href="/x"',
            $this->html("[*bold*]: /x\n\n[*bold*][]\n"),
        );
        $this->assertStringNotContainsString(
            'href="/x"',
            $this->html("[*bold*]: /x\n\n[bold][]\n"),
        );
        $this->assertStringNotContainsString(
            'href="/x"',
            $this->html("[bold]: /x\n\n[*bold*][]\n"),
        );
    }

    /**
     * A DEFINITION STILL BEATS A SAME-NAMED HEADING (R1's tie-break), including
     * when the label only reaches the heading through the reduction above.
     */
    public function testAnAuthoredDefinitionWinsTheTie(): void
    {
        $this->assertStringContainsString(
            'href="/x"',
            $this->html("[an /em/ heading]: /x\n\n# an /em/ heading\n\n[an /em/ heading][]\n"),
        );
    }

    /**
     * THE EXPLICIT FORM DOES NOT REACH THE INDEX (R1, carve#742). The reduction
     * is scoped to the collapsed form, so an explicit label naming no
     * definition stays unresolved even when a heading renders that text.
     */
    public function testTheExplicitFormStillDoesNotReachTheIndex(): void
    {
        $html = $this->html("# an /em/ heading\n\n[x][an em heading]\n");

        $this->assertStringNotContainsString('href="#an-em-heading">x', $html);
    }

    /**
     * A SYMBOL IS EXCLUDED FROM THE ID SLUG (syntax.md section 4.1 step 1), and
     * the exclusion is by CONSTRUCT: the id is the same whether the host
     * configured a `symbols` map or not, because a symbol's rendering is
     * processor configuration and an id is assigned in the parse pass.
     *
     * Keying on the shortcode NAME instead published `a-smile-b` for a heading
     * that, with `smile` mapped, renders `a` emoji `b` - an id naming a
     * spelling the document never rendered.
     */
    public function testASymbolDoesNotFeedTheHeadingId(): void
    {
        $source = "# a :smile: b\n\n[a :smile: b][]\n";
        $mapped = new CarveConverter(symbols: ['smile' => "\u{1F604}"]);

        $this->assertStringContainsString('<section id="a-b">', $this->html($source));
        $this->assertStringContainsString('<section id="a-b">', $mapped->convert($source));
        $this->assertStringContainsString("<h1>a \u{1F604} b</h1>", $mapped->convert($source));
    }

    /**
     * The symbol still RENDERS in a derived display label: only the id slug
     * excludes it. carve-js and carve-rs both keep it here too.
     */
    public function testASymbolSurvivesInACrossReferenceLabel(): void
    {
        $this->assertStringContainsString(
            '<a href="#h">a :smile: b</a>',
            $this->html("{#h}\n# a :smile: b\n\n</#h>\n"),
        );
    }

    /**
     * A heading whose rendered text is EMPTY is not in the index, so the
     * degenerate `[:smile:][]` finds nothing and stays literal source rather
     * than resolving to the `s` fallback id.
     */
    public function testAHeadingWithNoRenderedTextIsNotIndexed(): void
    {
        $html = $this->html("# :smile:\n\n[:smile:][]\n");

        $this->assertStringContainsString('<section id="s">', $html);
        $this->assertStringContainsString('<p>[:smile:][]</p>', $html);
    }

    /**
     * The AST-INGEST path is the second producer of this HTML, and a fix to the
     * parse path alone would leave it behind: `--from-json` renders a tree it
     * did not parse.
     */
    public function testTheAstIngestPathAgreesWithTheParsePath(): void
    {
        $source = "# an /em/ heading\n\n[an /em/ heading][]\n";
        $direct = (new CarveConverter())->convert($source);

        $codec = new AstCodec();
        $encoded = $codec->encodeJson(
            (new CarveConverter(parser: new BlockParser(false, false, false, true)))->parse($source),
        );
        $ingested = (new CarveConverter())->render($codec->decodeJson($encoded));

        $this->assertStringContainsString('href="#an-em-heading"', $direct);
        $this->assertSame($direct, $ingested);
    }
}
