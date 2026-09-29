<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PART 9 §10's grouping `[label]` survives a render and an import.
 *
 * TWO HALVES, and they compose rather than overlap
 * (markup-carve/carve-php#1661, ruled at markup-carve/carve-rs#1315).
 *
 * THE BOUNDARY. A `<div>` unwraps when it carries nothing only a container can
 * hold, and markup-carve/carve#1578 wrote that test as `attrs` - a proxy for its
 * own stated rationale, *"then there IS something only the container can hold"*.
 * A grouping label has no spelling anywhere but on an opener, so the rationale
 * already reached it and the proxy was narrower than the principle. What settles
 * it is that the narrow test was not a declarable LOSS: `::: [g]` came back as a
 * `{.div-label}` paragraph, so the container was gone and the label had become
 * body content. That is an ADDITION, and an addition cannot be declared away.
 *
 * THE LIFT. Even a container that SURVIVED left its label in the body, because
 * this importer had the lift on no arm at all - `::: note [g]` came back as
 * `::: note` wrapping a paragraph. Widening the boundary alone would have made
 * that worse rather than better, dropping the label silently instead of
 * surfacing it.
 *
 * FOUR ARMS. The label is written on any colon-fence opener, so the lift runs on
 * the bare `<div>`, the container-class `<div>`, the `<aside class="admonition">`
 * and the `data-djot-admonition-type` `<div>` alike.
 *
 * THE LIFT TAKES MARKUP NOW. It refused anything but text while a label was
 * published escaped, because lifting `<strong>` would have flattened it without a
 * word. markup-carve/carve#2572 ruled the label an inline run, so the markup has a
 * spelling on the opener and the refusals are asked of the WRITTEN form instead -
 * the parser answers what an opener can carry, rather than a second copy of the
 * grammar here. A text-only paragraph still lifts VERBATIM: its label round-trips
 * byte for byte, padding included, and writing it through the writer would trim
 * the block edges.
 *
 * NO NODE BUDGET IS BYPASSED HERE. carve-rs's lift had to charge the paragraph
 * it removes, because a labelled container would otherwise cost less than its
 * unlabelled twin. This importer has no node or depth budget on the DOM walk at
 * all, so there is nothing to charge and nothing to under-charge - checked
 * rather than assumed.
 */
class AContainerLabelSurvivesAnHtmlImportTest extends TestCase
{
    private CarveConverter $converter;

    private HtmlToCarve $importer;

    protected function setUp(): void
    {
        $this->converter = CarveConverter::create();
        $this->importer = new HtmlToCarve();
    }

    private function roundTrip(string $source): string
    {
        return $this->importer->convert($this->converter->convert($source));
    }

    /**
     * Each of these came back with the label as a `{.div-label}` paragraph
     * before this change, and the first came back with no container at all.
     *
     * @return array<string, array{string}>
     */
    public static function labelledContainers(): array
    {
        return [
            // THE TICKET'S REPRO. Both halves are needed: the boundary is what
            // keeps the fence, the lift is what puts the label back on it.
            'a bare div, whose fence the label alone saves' => ["::: [g]\nBody.\n:::\n"],
            'a div that survived on an id' => ["{#foo}\n::: [g]\nBody.\n:::\n"],
            'an admonition' => ["::: note [g]\nBody.\n:::\n"],
            'a container class' => ["::: figure [g]\nBody.\n:::\n"],
            // The title is degraded to a paragraph too, and it is written AHEAD
            // of the label on the same opener - so a lift that stopped at the
            // first element it did not recognize refused every container
            // carrying both.
            'a quoted title beside the label' => ["::: figure \"T\" [g]\nBody.\n:::\n"],
            'an admonition with both' => ["::: note \"T\" [g]\nBody.\n:::\n"],
            // The label IS an inline run (ruled on markup-carve/carve#2572), so
            // the paragraph the renderer degraded it to holds elements and the
            // lift writes their source back. This is a fixed point either way,
            // which is why it passed while the lift still refused markup.
            'a label holding markup' => ["::: figure [a *b*]\nBody.\n:::\n"],
            'emphasis' => ["::: [/i/]\nBody.\n:::\n"],
            'a code span' => ["::: note [`x`]\nBody.\n:::\n"],
        ];
    }

    #[DataProvider('labelledContainers')]
    public function testTheLabelComesBackOnItsOpener(string $source): void
    {
        $this->assertSame($source, $this->roundTrip($source));
    }

    /**
     * THE REFUSALS, and each is also a control on the boundary: a refused lift
     * means a bare div kept NOTHING, so it must still unwrap. Both columns are
     * asserted for that reason - a fix that lifted too eagerly passes the first
     * and fails the second.
     *
     * @return array<string, array{string, string}>
     */
    public static function refusedLifts(): array
    {
        return [
            // `]` has no spelling inside a bracket run on an opener line. It is
            // refused off the WRITTEN form now, so a `]` that reaches the label
            // through a code span is refused with no `]` in the label's own text.
            'a bracket in the label text' => [
                '<p class="div-label">a]b</p>',
                "{.div-label}\na]b\n\nBody.\n",
            ],
            'a bracket through a code span' => [
                '<p class="div-label"><code>]</code></p>',
                "{.div-label}\n`]`\n\nBody.\n",
            ],
            // An empty code span writes the two backticks the opener reads as an
            // unclosed run, so the opener does not read the label back. The
            // parser answers that, rather than a second enumeration here.
            'an empty code span' => [
                '<p class="div-label"><code></code></p>',
                "{.div-label}\n``\n\nBody.\n",
            ],
            // A newline ends the opener line.
            'a hard break in the label paragraph' => [
                '<p class="div-label">a<br>b</p>',
                "{.div-label}\na\\\nb\n\nBody.\n",
            ],
            // carve-rs lifts and declares the loss; this importer writes source
            // text in a pass with no diagnostics channel, so the same lift would
            // be an UNDECLARED loss. Refusing keeps the attribute on the
            // paragraph the HTML actually has.
            'an attribute riding the label paragraph' => [
                '<p class="div-label" id="x">g</p>',
                "{#x .div-label}\ng\n\nBody.\n",
            ],
        ];
    }

    #[DataProvider('refusedLifts')]
    public function testARefusedLiftLeavesTheParagraphAndUnwrapsTheBareDiv(
        string $paragraph,
        string $expected,
    ): void {
        $this->assertSame(
            $expected,
            $this->importer->convert('<div>' . $paragraph . '<p>Body.</p></div>'),
            'a bare div that kept nothing must still unwrap',
        );
        $this->assertStringNotContainsString(
            '::: figure [',
            $this->importer->convert('<div class="figure">' . $paragraph . '<p>Body.</p></div>'),
            'the label was lifted where it had to be refused',
        );
    }

    /**
     * THE ORDER REFUSALS, which an element search cannot see on its own.
     *
     * The paragraph is found by scanning for the first ELEMENT, and that is not
     * the first THING in the container: visible text ahead of it would be
     * reordered behind the label on the opener, which is the one thing a lift
     * must never do. Whitespace between tags is not text an author wrote, so the
     * pretty-printed row below still lifts - and that row is what keeps this
     * refusal from becoming "never lift".
     */
    public function testALabelAfterVisibleTextIsNotLifted(): void
    {
        $this->assertSame(
            "prefix\n\n{.div-label}\ng\n\nBody.\n",
            $this->importer->convert('<div>prefix<p class="div-label">g</p><p>Body.</p></div>'),
        );
        $this->assertStringNotContainsString(
            '::: figure [',
            $this->importer->convert('<div class="figure">prefix<p class="div-label">g</p><p>Body.</p></div>'),
        );
    }

    public function testALabelShapedParagraphFurtherDownIsNotLifted(): void
    {
        $this->assertSame(
            "x\n\n{.div-label}\ng\n",
            $this->importer->convert('<div><p>x</p><p class="div-label">g</p></div>'),
        );
    }

    public function testWhitespaceBeforeTheLabelStillLiftsIt(): void
    {
        $this->assertSame(
            "{#x}\n::: [g]\nBody.\n:::\n",
            $this->importer->convert("<div id=\"x\">\n  <p class=\"div-label\">g</p>\n  <p>Body.</p>\n</div>"),
        );
    }

    /**
     * THE BOUNDARY ITSELF, unmoved where no label is involved. carve#1578's rule
     * is narrowed by nothing here: a div carrying neither an attribute nor a
     * label still unwraps, and a style declaration the map refuses still leaves
     * nothing behind.
     *
     * @return array<string, array{string, string}>
     */
    public static function stillUnwraps(): array
    {
        return [
            'nothing at all' => ['<div><p>Body.</p></div>', "Body.\n"],
            'only a refused style declaration' => ['<div style="color:red"><p>Body.</p></div>', "Body.\n"],
        ];
    }

    #[DataProvider('stillUnwraps')]
    public function testADivCarryingNothingStillUnwraps(string $html, string $expected): void
    {
        $this->assertSame($expected, $this->importer->convert($html));
    }

    /**
     * THE OTHER DIRECTION OF THE SAME LOSS, and the one a lift can cause rather
     * than cure.
     *
     * A bare `<div class="djot-content">` is a TRANSPORT wrapper, not a
     * container: it unwraps unconditionally, so a label lifted off it would
     * have no opener to land on and would vanish without a word. The lift
     * declines it, and the paragraph the HTML actually has survives - which is
     * what this asserts, against the empty `Body.` a lift that ran here
     * produces. Raised by codex review.
     *
     * @return array<string, array{string, string}>
     */
    public static function djotContentWrappers(): array
    {
        return [
            'a bare wrapper keeps the paragraph rather than losing the label' => [
                '<div class="djot-content"><p class="div-label">g</p><p>Body.</p></div>',
                "{.div-label}\ng\n\nBody.\n",
            ],
            // THE BOUND: with an id it is no longer a bare wrapper, it does not
            // unwrap, and the label reaches an opener after all.
            'a wrapper carrying an id is a container again' => [
                '<div class="djot-content" id="x"><p class="div-label">g</p><p>Body.</p></div>',
                "{#x}\n::: djot-content [g]\nBody.\n:::\n",
            ],
            'a bare wrapper with no label unwraps as it always did' => [
                '<div class="djot-content"><p>Body.</p></div>',
                "Body.\n",
            ],
        ];
    }

    #[DataProvider('djotContentWrappers')]
    public function testATransportWrapperNeverLosesALabelToTheLift(
        string $html,
        string $expected,
    ): void {
        $this->assertSame($expected, $this->importer->convert($html));
    }

    /**
     * The title's own lift already worked, and the label's arrival must not
     * disturb it.
     */
    public function testAQuotedTitleAloneStillSurvives(): void
    {
        $this->assertSame(
            "::: figure \"T\"\nBody.\n:::\n",
            $this->roundTrip("::: figure \"T\"\nBody.\n:::\n"),
        );
    }
}
