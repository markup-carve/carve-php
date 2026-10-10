<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use MarkupCarve\Carve\Parser\BlockParser;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use MarkupCarve\Carve\Renderer\MarkdownRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * CARVE-P11-063, PART 11 §10s: under the opt-in carrier mode the Markdown
 * target brackets every ELEMENT-LESS CONTAINER with an HTML comment carrying
 * its Carve opener and closer verbatim, so an export and a re-import return
 * the container.
 *
 * THE TWO CONTROLS ARE THE POINT: with the mode off the emitted bytes are the
 * ones this target emits today, and a document holding no element-less
 * container gains no comment either way. A carrier that moved the default
 * output would be a breaking change rather than an opt-in mode.
 *
 * TWO OF THE CLAUSE'S OWN SAMPLES CANNOT BE MEASURED. `::: wrapper {.fancy}`
 * is not a Carve opener: PART 9 §12 and the container grammar are STRICT, the
 * opener line carries no inline `{...}` attributes, so both this engine and
 * carve-js drop the brace block at PARSE time and no writer can carry what the
 * tree does not hold. An attributed container's Carve spelling is a PRECEDING
 * attribute line, and that is the line carried here.
 */
class AnElementLessContainerIsCarriedInACommentTest extends TestCase
{
    /**
     * The mode-off path never touches the new API, so the controls below are
     * controls on EVERY build rather than only on one that has the mode.
     */
    private function markdown(string $carve, bool $carry = false): string
    {
        $renderer = new MarkdownRenderer();
        if ($carry) {
            $renderer->setCarryMarkers();
        }

        return CarveConverter::create(new BlockParser(), $renderer)->convert($carve);
    }

    /**
     * The HTML a Carve source renders, which is where a caption's ROLE is
     * visible. A round trip that returns the caption's text and loses its role
     * passes a byte comparison and fails this.
     */
    private function html(string $carve): string
    {
        return CarveConverter::create(new BlockParser(), new HtmlRenderer())->convert($carve);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function carriedProvider(): array
    {
        $cases = [
            'a tab set and each of its panels' => [
                "::: tabs\n:::: tab [Overview]\nFirst panel.\n::::\n\n:::: tab [Install]\nSecond panel.\n::::\n:::\n",
                "<!-- carve: ::: tabs -->\n<!-- carve: :::: tab [Overview] -->\n**Overview**\n\nFirst panel.\n\n"
                . "<!-- carve: :::: -->\n<!-- carve: :::: tab [Install] -->\n**Install**\n\nSecond panel.\n\n"
                . "<!-- carve: :::: -->\n<!-- carve: ::: -->\n",
            ],
            'a code-group set and its panel' => [
                "::: code-group\n:::: tab [sh]\n```sh\nx\n```\n::::\n:::\n",
                "<!-- carve: ::: code-group -->\n<!-- carve: :::: tab [sh] -->\n**sh**\n\n```sh\nx\n```\n\n"
                . "<!-- carve: :::: -->\n<!-- carve: ::: -->\n",
            ],
            'a named div, whose name is what today drops' => [
                "::: wrapper\nA generic div.\n:::\n",
                "<!-- carve: ::: wrapper -->\nA generic div.\n\n<!-- carve: ::: -->\n",
            ],
            // An attributed container is TWO Carve lines, so it is two markers.
            'a named div carrying attributes' => [
                "{.fancy #w}\n::: wrapper\nA generic div.\n:::\n",
                "<!-- carve: {.fancy #w} -->\n<!-- carve: ::: wrapper -->\nA generic div.\n\n<!-- carve: ::: -->\n",
            ],
            'a div nested in a named div' => [
                "::: outer\n:::: inner\nx\n::::\n:::\n",
                "<!-- carve: ::: outer -->\n<!-- carve: :::: inner -->\nx\n\n<!-- carve: :::: -->\n<!-- carve: ::: -->\n",
            ],
            'a columns container and each column' => [
                "::: columns\n:::: column\nA\n::::\n\n:::: column\nB\n::::\n:::\n",
                "<!-- carve: ::: columns -->\n<!-- carve: :::: column -->\nA\n\n<!-- carve: :::: -->\n"
                . "<!-- carve: :::: column -->\nB\n\n<!-- carve: :::: -->\n<!-- carve: ::: -->\n",
            ],
            'a disclosure' => [
                "::: details \"Open me\"\nHidden.\n:::\n",
                "<!-- carve: ::: details \"Open me\" -->\n**Open me**\n\nHidden.\n\n<!-- carve: ::: -->\n",
            ],
            'a spoiler' => [
                "::: spoiler\nHidden.\n:::\n",
                "<!-- carve: ::: spoiler -->\nHidden.\n\n<!-- carve: ::: -->\n",
            ],
            'a composite figure group wrapper' => [
                "::: figure\n![a](a.png)\n:::\n",
                "<!-- carve: ::: figure -->\n![a](a.png)\n\n<!-- carve: ::: -->\n",
            ],
            'a typeless div carrying a label' => [
                "::: [First]\nx\n:::\n",
                "<!-- carve: ::: [First] -->\n**First**\n\nx\n\n<!-- carve: ::: -->\n",
            ],
            // THE ESCAPE, the only spelling in the payload that is not Carve
            // source read back verbatim. A quoted title takes a `-->` and has
            // no escape of its own, so it is where the case lives.
            'a payload carrying `-->`' => [
                "::: note \"a --> b\"\nBody.\n:::\n",
                "<!-- carve: ::: note \"a --\\> b\" -->\n**a \u{2192} b**\n\nBody.\n\n<!-- carve: ::: -->\n",
            ],
            'a payload already carrying a backslash before that `>`' => [
                "::: note \"a --\\> b\"\nBody.\n:::\n",
                "<!-- carve: ::: note \"a --\\\\> b\" -->\n**a --\\> b**\n\nBody.\n\n<!-- carve: ::: -->\n",
            ],
            'a label carrying `-->`' => [
                "::: wrapper [a --> b]\nx\n:::\n",
                "<!-- carve: ::: wrapper [a --\\> b] -->\n**a --> b**\n\nx\n\n<!-- carve: ::: -->\n",
            ],
        ];
        foreach (['note', 'tip', 'warning', 'danger', 'info', 'success', 'example', 'quote'] as $kind) {
            $cases["an admonition of kind $kind"] = [
                "::: $kind\nAn admonition body.\n:::\n",
                "<!-- carve: ::: $kind -->\nAn admonition body.\n\n<!-- carve: ::: -->\n",
            ];
            $cases["an admonition of kind $kind carrying a title"] = [
                "::: $kind \"A title\"\nAn admonition body.\n:::\n",
                "<!-- carve: ::: $kind \"A title\" -->\n**A title**\n\nAn admonition body.\n\n<!-- carve: ::: -->\n",
            ];
        }

        return $cases;
    }

    #[DataProvider('carriedProvider')]
    public function testTheCarrierModeWritesTheOpenerVerbatim(string $carve, string $carrier): void
    {
        $this->assertSame($carrier, $this->markdown($carve, true));
    }

    #[DataProvider('carriedProvider')]
    public function testTheCarrierModeRoundTripsTheContainer(string $carve, string $carrier): void
    {
        $this->assertSame($carve, (new MarkdownToCarve())->convert($carrier));
    }

    #[DataProvider('carriedProvider')]
    public function testTheModeOffEmitsExactlyTheBytesThisTargetEmitsToday(string $carve, string $carrier): void
    {
        $this->assertStringNotContainsString('<!-- carve:', $this->markdown($carve));
    }

    public function testTheModeOffEmitsTodaysBytes(): void
    {
        $tabs = "::: tabs\n:::: tab [Overview]\nFirst panel.\n::::\n\n:::: tab [Install]\nSecond panel.\n::::\n:::\n";
        $this->assertSame("**Overview**\n\nFirst panel.\n\n**Install**\n\nSecond panel.\n", $this->markdown($tabs));
        $this->assertSame("An admonition body.\n", $this->markdown("::: note\nAn admonition body.\n:::\n"));
        $this->assertSame("A generic div.\n", $this->markdown("::: wrapper\nA generic div.\n:::\n"));
    }

    /**
     * @var string
     */
    private const PLAIN = "# Head\n\nA paragraph with *bold* text.\n\n- one\n- two\n";

    /**
     * @var string
     */
    private const PLAIN_MARKDOWN = "# Head\n\nA paragraph with **bold** text.\n\n- one\n- two\n";

    public function testADocumentWithNoElementLessContainerGainsNoCommentWithTheModeOff(): void
    {
        $out = $this->markdown(self::PLAIN);
        $this->assertStringNotContainsString('<!-- carve:', $out);
        $this->assertSame(self::PLAIN_MARKDOWN, $out);
    }

    public function testADocumentWithNoElementLessContainerGainsNoCommentWithTheModeOn(): void
    {
        $out = $this->markdown(self::PLAIN, true);
        $this->assertStringNotContainsString('<!-- carve:', $out);
        $this->assertSame(self::PLAIN_MARKDOWN, $out);
    }

    /**
     * A container this target DOES spell is not element-less and takes no
     * marker: an attributes-only opener is a paragraph, and a list table is
     * written as a pipe table.
     *
     * @return array<string, array{0: string}>
     */
    public static function spelledProvider(): array
    {
        return [
            'an attributes-only opener' => ["::: {.warning}\nx\n:::\n"],
            'a list table' => ["{header-rows=1}\n::: list-table\n- - A\n  - B\n- - one\n  - x\n:::\n"],
        ];
    }

    #[DataProvider('spelledProvider')]
    public function testAContainerThisTargetSpellsTakesNoMarker(string $carve): void
    {
        $this->assertStringNotContainsString('<!-- carve:', $this->markdown($carve, true));
    }

    /**
     * A HOST THAT PREFIXES ITS LINES CARRIES, and byte-exactly.
     *
     * The marker stands at the host's content column or behind its `>`, and the
     * import finds it there because WHICH LINES ARE MARKERS NOW COMES FROM THE
     * BLOCK STRUCTURE this importer derives rather than from a flat scan of the
     * source lines. A flat scan cannot tell a marker at a list item's content
     * column from code text inside that item - the verbatim cases below are
     * exactly what it would have eaten - while the structure pass already
     * carries fence state, a quote's `>` prefix and an item's content column
     * (markup-carve/carve#2850).
     *
     * The two-level and quote-in-item sources are spelled TIGHT on purpose: a
     * blank line between an outer item and its nested list is lost by this
     * target whether a container is involved or not, so a loose spelling would
     * measure that instead of this.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function prefixedHostProvider(): array
    {
        return [
            'inside a list item' => [
                "- Item.\n\n  ::: note\n  Body.\n  :::\n",
                "- Item.\n\n  <!-- carve: ::: note -->\n  Body.\n\n  <!-- carve: ::: -->\n",
            ],
            'two list levels in' => [
                "- Outer.\n  - Inner.\n\n    ::: note\n    Body.\n    :::\n",
                "- Outer.\n  - Inner.\n\n    <!-- carve: ::: note -->\n    Body.\n\n    <!-- carve: ::: -->\n",
            ],
            'inside a block quote' => [
                "> ::: note\n> Body.\n> :::\n",
                "> <!-- carve: ::: note -->\n> Body.\n>\n> <!-- carve: ::: -->\n",
            ],
            'inside a block quote inside a list item' => [
                "- Item.\n  > ::: note\n  > Body.\n  > :::\n",
                "- Item.\n  > <!-- carve: ::: note -->\n  > Body.\n  >\n  > <!-- carve: ::: -->\n",
            ],
            // THE OPENER SHARES THE ITEM'S MARKER LINE here, so the placeholder
            // the import lifts the marker to stands behind a `-`. Leaving the
            // token in the output would be corruption rather than a missed
            // restore, which is why the prefix is read as whatever precedes the
            // token and not as a character class.
            'opening a list item' => [
                "- ::: note\n  Body.\n  :::\n",
                "- <!-- carve: ::: note -->\n  Body.\n\n  <!-- carve: ::: -->\n",
            ],
            // AND ITS BODY BEGINS WITH A LIST, so the closer's placeholder is a
            // lazy continuation of that inner item's paragraph and the written
            // Carve puts it at the inner content column. A closer stands at its
            // OPENER's column, which is what brings it back.
            'opening a list item, holding a list' => [
                "- ::: note\n  - one\n  - two\n  :::\n",
                "- <!-- carve: ::: note -->\n  - one\n  - two\n  <!-- carve: ::: -->\n",
            ],
            'empty, opening a list item' => [
                "- ::: note\n  :::\n",
                "- <!-- carve: ::: note -->\n  <!-- carve: ::: -->\n",
            ],
            'a figure group with a caption inside a list item' => [
                "- item\n\n  ::: figure\n  :::: panel\n  ![a](x.png)\n  ::::\n  :::\n  ^ Group caption\n",
                "- item\n\n  <!-- carve: ::: figure -->\n  <!-- carve: :::: panel -->\n  ![a](x.png)\n\n"
                    . "  <!-- carve: :::: -->\n  <!-- carve: ::: -->\n  <!-- carve: ^ Group caption -->\n  **Group caption**\n",
            ],
            // A SIBLING ITEM AFTER THE CLOSER MUST NOT GO LOOSE. A closer and
            // what follows take a blank line between them where they are
            // siblings; the item below belongs to the host above the container,
            // and a blank there would wrap `next` in a `<p>`.
            'opening a list item, with a sibling item after it' => [
                "- ::: note\n  - one\n  - two\n  :::\n- next\n",
                "- <!-- carve: ::: note -->\n  - one\n  - two\n  <!-- carve: ::: -->\n- next\n",
            ],
            // A TASK BOX IS INLINE CONTENT, so an HTML block cannot begin after
            // it: the structure pass admits this one as a task-marker line
            // rather than as an HTML block. The box is also not part of the
            // column, so the closer stands at the ITEM's content column and not
            // past the box.
            'opening a task item' => [
                "- [ ] ::: note\n  Body.\n  :::\n",
                "- [ ] <!-- carve: ::: note -->\n  Body.\n\n  <!-- carve: ::: -->\n",
            ],
            // A BLOCK QUOTE OPENING A LIST ITEM carries both prefixes on one
            // line, and the item's marker came off without the quote regex
            // running again - so the block readings take the nested `>` off.
            'a block quote opening a list item' => [
                "- > ::: note\n  > Body.\n  > :::\n",
                "- > <!-- carve: ::: note -->\n  > Body.\n  >\n  > <!-- carve: ::: -->\n",
            ],
            // TWO ITEMS OPEN ON ONE LINE here, so the block reading has to take
            // EVERY container prefix off and not only the first.
            'opening two list items at once' => [
                "- - ::: note\n    Body.\n    :::\n",
                "- - <!-- carve: ::: note -->\n    Body.\n\n    <!-- carve: ::: -->\n",
            ],
            // AND THREE, where the body stands six columns in. The content
            // column is the one EVERY prefix put the body at, not the one the
            // first marker did, or the closer would read as code.
            'opening three list items at once' => [
                "- - - ::: note\n      Body.\n      :::\n",
                "- - - <!-- carve: ::: note -->\n      Body.\n\n      <!-- carve: ::: -->\n",
            ],
            // A CAPTION HANGS ON THE CLOSING FENCE, so it stands at that
            // closer's column. With a list body its placeholder is a lazy
            // continuation of the inner item just as the closer's is, and a
            // caption at the wrong column attaches to nothing.
            'a figure caption, with a list body, in a list item' => [
                "- ::: figure\n  - one\n  - two\n  :::\n  ^ Caption\n",
                "- <!-- carve: ::: figure -->\n  - one\n  - two\n  <!-- carve: ::: -->\n"
                    . "  <!-- carve: ^ Caption -->\n  **Caption**\n",
            ],
        ];
    }

    #[DataProvider('prefixedHostProvider')]
    public function testAContainerInAPrefixedHostTakesAMarkerAtItsHostsColumn(string $carve, string $carrier): void
    {
        $this->assertSame($carrier, $this->markdown($carve, true));
    }

    #[DataProvider('prefixedHostProvider')]
    public function testAContainerInAPrefixedHostComesBack(string $carve, string $carrier): void
    {
        $restored = (new MarkdownToCarve())->convert($carrier);
        $this->assertStringNotContainsString('CARVECARRIER', $restored, 'the placeholder reached the output');
        $this->assertSame($carve, $restored);
    }

    /**
     * The bytes are the point, but so is the MEANING: a round trip that returns
     * the same text under a different element passes a byte comparison.
     */
    #[DataProvider('prefixedHostProvider')]
    public function testAPrefixedHostsRoundTripRendersTheSameHtml(string $carve, string $carrier): void
    {
        $this->assertSame($this->html($carve), $this->html((new MarkdownToCarve())->convert($carrier)));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function damagedInAPrefixedHostProvider(): array
    {
        return [
            'one marker deleted in a list item' => ["- Item.\n\n  Body.\n\n  <!-- carve: ::: -->\n"],
            'two reordered in a list item' => ["- Item.\n\n  <!-- carve: ::: -->\n  Body.\n\n  <!-- carve: ::: note -->\n"],
            'unbalanced in a block quote' => ["> <!-- carve: ::: note -->\n> <!-- carve: ::: wrapper -->\n> Body.\n>\n> <!-- carve: ::: -->\n"],
        ];
    }

    /**
     * A damaged set inside a prefixed host is still never guessed at.
     */
    #[DataProvider('damagedInAPrefixedHostProvider')]
    public function testADamagedSetInsideAPrefixedHostReportsAndReconstructsNothing(string $source): void
    {
        $result = (new MarkdownToCarve())->convertWithFidelityReport($source);
        $damaged = array_values(array_filter(
            $result->diagnostics,
            static fn ($diagnostic): bool => $diagnostic->code === 'carrier-markers-damaged',
        ));
        $this->assertCount(1, $damaged, 'a damaged marker set owes exactly one diagnostic');
        $this->assertSame('degraded', $damaged[0]->fidelity);
        $this->assertSame('fallback', $damaged[0]->confidence);
        $this->assertSame(0, preg_match('/^[ \t>]*:{3,}/m', $result->value), 'a damaged set reconstructed a container');
        $this->assertStringContainsString('```=html', $result->value);
    }

    /**
     * A TABLE CELL STILL CARRIES NOTHING: this target flattens a cell to one
     * line and the container's body with it, so there is no line for a marker
     * to stand on (markup-carve/carve#2856).
     */
    public function testAContainerInATableCellTakesNoMarkerEitherWay(): void
    {
        $source = "{header-rows=1}\n::: list-table\n- - A\n  - B\n"
            . "- - cell one\n  - ::: note\n    Body.\n    :::\n:::\n";
        $on = $this->markdown($source, true);
        $this->assertStringNotContainsString('<!-- carve:', $on);
        $this->assertSame($this->markdown($source), $on);
        $this->assertStringContainsString('| cell one | Body. |', $on);
    }

    /**
     * A COMPOSITE FIGURE'S CAPTION LINE TAKES A MARKER OF ITS OWN, directly
     * after the closer's, exactly as an attribute line above an opener takes
     * one. The caption slot hangs outside the closing fence, so the pair
     * bracketing the container cannot enclose it.
     *
     * THE ONE THING THE ATTRIBUTE-LINE PRECEDENT DOES NOT COVER: a caption also
     * renders as body text, so the import REPLACES that rendered paragraph
     * instead of appending a second copy of the caption.
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function captionProvider(): array
    {
        return [
            'a figure group with a caption' => [
                "::: figure\n:::: panel\n![a](x.png)\n::::\n:::\n^ Group caption\n",
                "<!-- carve: ::: figure -->\n<!-- carve: :::: panel -->\n![a](x.png)\n\n"
                . "<!-- carve: :::: -->\n<!-- carve: ::: -->\n<!-- carve: ^ Group caption -->\n"
                . "**Group caption**\n",
                "::: figure\n:::: panel\n![a](x.png)\n::::\n:::\n^ Group caption\n",
            ],
            // GAINS NOTHING is the control on the writer half: no caption, no
            // third marker, and the bytes are the ones the clause already had.
            'a figure group with no caption' => [
                "::: figure\n:::: panel\n![a](x.png)\n::::\n:::\n",
                "<!-- carve: ::: figure -->\n<!-- carve: :::: panel -->\n![a](x.png)\n\n"
                . "<!-- carve: :::: -->\n<!-- carve: ::: -->\n",
                "::: figure\n:::: panel\n![a](x.png)\n::::\n:::\n",
            ],
            'a caption carrying the comment terminator' => [
                "::: figure\n:::: panel\n![a](x.png)\n::::\n:::\n^ A --> B\n",
                "<!-- carve: ::: figure -->\n<!-- carve: :::: panel -->\n![a](x.png)\n\n"
                . "<!-- carve: :::: -->\n<!-- carve: ::: -->\n<!-- carve: ^ A --\\> B -->\n"
                . "**A \u{2192} B**\n",
                "::: figure\n:::: panel\n![a](x.png)\n::::\n:::\n^ A --> B\n",
            ],
            // THE CAPTION'S TEXT ALSO APPEARING AS ORDINARY BODY TEXT must not
            // be consumed: only the paragraph the marker stands directly above
            // is the one the caption replaces.
            'a caption whose text is also ordinary body text' => [
                "::: figure\n:::: panel\n![a](x.png)\n::::\n:::\n^ Group caption\n\n*Group caption*\n",
                "<!-- carve: ::: figure -->\n<!-- carve: :::: panel -->\n![a](x.png)\n\n"
                . "<!-- carve: :::: -->\n<!-- carve: ::: -->\n<!-- carve: ^ Group caption -->\n"
                . "**Group caption**\n\n**Group caption**\n",
                "::: figure\n:::: panel\n![a](x.png)\n::::\n:::\n^ Group caption\n\n*Group caption*\n",
            ],
            'a caption carrying inline strong' => [
                "::: figure\n:::: panel\n![a](x.png)\n::::\n:::\n^ A *strong* caption\n",
                "<!-- carve: ::: figure -->\n<!-- carve: :::: panel -->\n![a](x.png)\n\n"
                . "<!-- carve: :::: -->\n<!-- carve: ::: -->\n<!-- carve: ^ A *strong* caption -->\n"
                . "**A **strong** caption**\n",
                "::: figure\n:::: panel\n![a](x.png)\n::::\n:::\n^ A *strong* caption\n",
            ],
        ];
    }

    #[DataProvider('captionProvider')]
    public function testTheCarrierModeWritesTheCaptionLineVerbatim(
        string $carve,
        string $carrier,
        string $roundTrip,
    ): void {
        $this->assertSame($carrier, $this->markdown($carve, true));
    }

    #[DataProvider('captionProvider')]
    public function testTheCarrierModeRoundTripsACaptionIntoItsOwnSlot(
        string $carve,
        string $carrier,
        string $roundTrip,
    ): void {
        $this->assertSame($roundTrip, (new MarkdownToCarve())->convert($carrier));
        // The ROLE is what was lost before this: a `<figcaption>` came back as
        // emphasized body text. The HTML is where the role is visible.
        $this->assertSame($this->html($carve), $this->html($roundTrip));
    }

    #[DataProvider('captionProvider')]
    public function testACaptionComesBackAsACaptionNotAsBodyText(
        string $carve,
        string $carrier,
        string $roundTrip,
    ): void {
        // THE IMPORT'S OWN OUTPUT, not the expectation: a check on $roundTrip
        // alone would measure the HTML renderer and never the importer.
        $html = $this->html((new MarkdownToCarve())->convert($carrier));
        if (!str_contains($carve, "\n^ ")) {
            $this->assertStringNotContainsString('<figcaption>', $html);

            return;
        }
        $this->assertStringContainsString('<figcaption>', $html);
        // And not twice: the rendered paragraph was replaced, not joined.
        $this->assertSame(1, substr_count($html, '<figcaption>'));
    }

    #[DataProvider('captionProvider')]
    public function testACaptionClaimsNoDamage(
        string $carve,
        string $carrier,
        string $roundTrip,
    ): void {
        $codes = array_map(
            static fn ($diagnostic): string => $diagnostic->code,
            (new MarkdownToCarve())->convertWithFidelityReport($carrier)->diagnostics,
        );
        $this->assertNotContains('carrier-markers-damaged', $codes);
    }

    #[DataProvider('captionProvider')]
    public function testTheCaptionGainsNoCommentWithTheModeOff(
        string $carve,
        string $carrier,
        string $roundTrip,
    ): void {
        $this->assertStringNotContainsString('<!-- carve:', $this->markdown($carve));
    }

    /**
     * THE CONTROL ON THE CAPTION SPELLING. A `^ ...` line INSIDE the container
     * is not the caption slot: it is literal text, it renders as a paragraph
     * rather than a `<figcaption>`, and the carrier mode must leave it literal.
     */
    public function testACaptionLineInsideTheContainerStaysLiteral(): void
    {
        $carve = "::: figure\n:::: panel\n![a](x.png)\n::::\n^ Group caption\n:::\n";
        $this->assertStringContainsString('<p>^ Group caption</p>', $this->html($carve));
        $carrier = $this->markdown($carve, true);
        $this->assertStringNotContainsString('<!-- carve: ^', $carrier);
        $this->assertStringContainsString("<!-- carve: :::: -->\n^ Group caption\n", $carrier);
        $restored = (new MarkdownToCarve())->convert($carrier);
        $this->assertStringNotContainsString('<figcaption>', $this->html($restored));
    }

    /**
     * A caption marker whose set does not record a structure is reported, never
     * guessed: it belongs to the container the marker before it closed, so one
     * standing anywhere else has lost what it recorded.
     *
     * @return array<string, array{0: string}>
     */
    public static function damagedCaptionProvider(): array
    {
        return [
            'a caption marker with no closer before it' => [
                "<!-- carve: ^ Group caption -->\n**Group caption**\n",
            ],
            'a caption marker whose container is left open' => [
                "<!-- carve: ::: figure -->\n![a](x.png)\n\n<!-- carve: ^ Group caption -->\n"
                . "**Group caption**\n",
            ],
            'a caption marker standing above an opener' => [
                "<!-- carve: ^ Group caption -->\n<!-- carve: ::: figure -->\n![a](x.png)\n\n"
                . "<!-- carve: ::: -->\n",
            ],
        ];
    }

    #[DataProvider('damagedCaptionProvider')]
    public function testADamagedCaptionMarkerIsReportedNeverGuessed(string $source): void
    {
        $result = (new MarkdownToCarve())->convertWithFidelityReport($source);
        $damaged = array_values(array_filter(
            $result->diagnostics,
            static fn ($diagnostic): bool => $diagnostic->code === 'carrier-markers-damaged',
        ));
        $this->assertCount(1, $damaged, 'a damaged marker set owes exactly one diagnostic');
        $this->assertSame('degraded', $damaged[0]->fidelity);
        $this->assertSame('fallback', $damaged[0]->confidence);
        $this->assertSame(0, preg_match('/^:{3,}/m', $result->value), 'a damaged set reconstructed a container');
        $this->assertStringContainsString('```=html', $result->value);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function damagedProvider(): array
    {
        return [
            'one marker deleted' => ["Body.\n<!-- carve: ::: -->\n"],
            'two markers reordered' => ["<!-- carve: ::: -->\nBody.\n<!-- carve: ::: note -->\n"],
            'an unbalanced set' => ["<!-- carve: ::: note -->\n<!-- carve: ::: wrapper -->\nBody.\n<!-- carve: ::: -->\n"],
        ];
    }

    #[DataProvider('damagedProvider')]
    public function testADamagedSetImportsAsPlainMarkdownPlusOneDiagnostic(string $source): void
    {
        $result = (new MarkdownToCarve())->convertWithFidelityReport($source);
        $damaged = array_values(array_filter(
            $result->diagnostics,
            static fn ($diagnostic): bool => $diagnostic->code === 'carrier-markers-damaged',
        ));
        $this->assertCount(1, $damaged, 'a damaged marker set owes exactly one diagnostic');
        $this->assertSame('degraded', $damaged[0]->fidelity);
        $this->assertSame('fallback', $damaged[0]->confidence);
        // Never a guess: no container is reconstructed, and the markers come
        // back as the raw HTML they are.
        $this->assertSame(0, preg_match('/^:{3,}/m', $result->value), 'a damaged set reconstructed a container');
        $this->assertStringContainsString('```=html', $result->value);
    }

    /**
     * markup-carve/carve-php#3038: a code construct's payload is verbatim
     * content, so a marker-shaped line in one records no container and the
     * import must leave it where it is. The page documenting the mode holds
     * exactly such lines, and lifting one rewrote its own sample.
     *
     * @return array<string, array{0: string}>
     */
    public static function verbatimMarkerProvider(): array
    {
        return [
            'a fenced code block' => ["Prose.\n\n```markdown\n<!-- carve: ::: note -->\nBody.\n<!-- carve: ::: -->\n```\n\nTail.\n"],
            'a tilde fence' => ["Prose.\n\n~~~\n<!-- carve: ::: note -->\n~~~\n\nTail.\n"],
            'an indented code block' => ["Prose.\n\n    <!-- carve: ::: note -->\n    body\n\nTail.\n"],
            'an inline code span' => ["A `<!-- carve: ::: note -->` span.\n"],
            'a raw block' => ["Prose.\n\n```=html\n<!-- carve: ::: note -->\n```\n"],
            // THE PREFIXED SHAPES, which is what carve#2850's narrowing rests
            // on. A marker is read at column 0 only, so a marker-shaped line
            // at a list item's content column is left where it is. Reading one
            // through the prefix would eat both of these: at that point in the
            // pre-pass a marker at an item's content column cannot be told
            // apart from verbatim text in a code block inside the item.
            'a fenced code block inside a list item' => [
                "- item\n\n  ```\n  <!-- carve: ::: note -->\n  Body.\n  <!-- carve: ::: -->\n  ```\n",
            ],
            'an indented code block inside a list item' => [
                "- item\n\n      <!-- carve: ::: note -->\n      body\n",
            ],
            // A FENCE INDENTED PAST THREE COLUMNS is code text, not a fence,
            // so its own content is verbatim too.
            'a fence indented past three columns' => [
                "Prose.\n\n     ```md\n     <!-- carve: ::: note -->\n     ```\n",
            ],
            // AND A FENCE IN A QUOTE IN A LIST ITEM, which carries both host
            // prefixes. This is the shape that made the structure pass's own
            // reading have to take the nested `>` off the content: without
            // that, the fence is invisible and its payload is lifted.
            'a fence in a block quote in a list item' => [
                "- Item.\n  > ```md\n  > <!-- carve: ::: note -->\n  > Body.\n  > <!-- carve: ::: -->\n  > ```\n",
            ],
            // A LIST MARKER INSIDE AN OPEN FENCE IS VERBATIM CONTENT. Reading
            // the nested prefixes off a line inside the fence would let this
            // `- ```` read as its closer, and the comments below it would then
            // be lifted out of the payload.
            'a fence whose payload holds a marker-shaped fence line' => [
                "- ```\n  - ```\n  <!-- carve: ::: note -->\n  Body\n  <!-- carve: ::: -->\n  ```\n",
            ],
        ];
    }

    #[DataProvider('verbatimMarkerProvider')]
    public function testAMarkerShapedLineInACodeConstructComesBackVerbatim(string $source): void
    {
        $result = (new MarkdownToCarve())->convertWithFidelityReport($source);
        $this->assertStringContainsString('<!-- carve: ::: note -->', $result->value, 'the payload lost its marker-shaped line');
        $this->assertSame(0, preg_match('/^:{3,}/m', $result->value), 'a verbatim payload was read as a container');
        $codes = array_map(static fn ($diagnostic): string => $diagnostic->code, $result->diagnostics);
        $this->assertNotContains('carrier-markers-damaged', $codes);
    }

    /**
     * The control, and the diagnostic's own arithmetic: the set OUTSIDE the
     * fence is one unclosed opener, so it is damaged; the marker-shaped line
     * inside the fence is not its closer and must not balance it.
     */
    public function testADamagedSetOutsideAFenceCountsOnlyTheRealMarkers(): void
    {
        $result = (new MarkdownToCarve())->convertWithFidelityReport(
            "<!-- carve: ::: note -->\n\nBody.\n\n```md\n<!-- carve: ::: -->\n```\n",
        );
        $damaged = array_values(array_filter(
            $result->diagnostics,
            static fn ($diagnostic): bool => $diagnostic->code === 'carrier-markers-damaged',
        ));
        $this->assertCount(1, $damaged, 'the fenced line was counted as part of the set');
        $this->assertSame(0, preg_match('/^:{3,}/m', $result->value));
        $this->assertStringContainsString("```md\n<!-- carve: ::: -->\n```", $result->value);
    }

    public function testASoundSetReportsNoDamage(): void
    {
        $result = (new MarkdownToCarve())->convertWithFidelityReport(
            "<!-- carve: ::: note -->\nBody.\n\n<!-- carve: ::: -->\n",
        );
        $codes = array_map(static fn ($diagnostic): string => $diagnostic->code, $result->diagnostics);
        $this->assertNotContains('carrier-markers-damaged', $codes);
        $this->assertSame("::: note\nBody.\n:::\n", $result->value);
    }
}
