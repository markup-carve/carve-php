<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * An element that names no destination the source can carry builds no node.
 *
 * Carve spells a link's destination and an image's source in the same slot and
 * has NO spelling for an empty one - `[t]()` and `![t]()` are literal text - so
 * an importer writing the empty slot has not written a link. It has written
 * four punctuation characters the HTML never held, into the middle of the
 * prose. The rule (`docs/html-import.md`, markup-carve/carve#1609) is over the
 * DESTINATION rather than over the reason it is missing: an `<a>` with no
 * `href`, an `<a href="">` and an `<img>` whose `src` is either are ONE shape.
 *
 * THE SECURITY HALF IS WHY THE DESTINATION IS NOT REBUILT, and it is what the
 * cases below are here for. This is not hypothetical input - it is what Carve's
 * own renderer EMITS: PART 9 §25's URL sink denylist blanks a destination whose
 * scheme is dangerous and writes no provenance for it, keeping the visible
 * text, because what is blanked is the destination and not the text. An
 * importer reading a hardened render therefore turned the renderer's deliberate
 * half-measure into visible punctuation, on the documents whose output had been
 * thought about hardest.
 *
 * So any route from a `title`, from the anchor's own text, or from a
 * `data-djot-*` provenance attribute in `roundtrip` mode back to a destination
 * would reconstruct the exact value a security rule removed, making the
 * importer a way AROUND PART 9 §25 rather than a reader of its output. The
 * shared contract fixture `html-import/destination-less-link` pins the plain
 * shape in `safe` mode; every route this class asserts on is one the fixture
 * cannot see, because `roundtrip` mode and a surviving `title` are outside it.
 */
class ADestinationCarveCannotCarryIsNotADestinationTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function destinationLessInputs(): array
    {
        return [
            'an empty href' => ['<p><a href="">click here</a></p>', 'click here'],
            'no href at all' => ['<p><a>click here</a></p>', 'click here'],
            // EMPTY IS A PROPERTY OF THE STRING, read the way an HTML URL
            // attribute is read: zero length once leading and trailing ASCII
            // whitespace is stripped, because that is what a URL parser strips
            // before resolving one.
            'an all-whitespace href' => ['<p><a href="  &#9;  ">click here</a></p>', 'click here'],
            'an empty src' => ['<img src="" alt="logo">', 'logo'],
            'no src at all' => ['<img alt="logo">', 'logo'],
            'an all-whitespace src' => ['<img src=" &#10; " alt="logo">', 'logo'],
        ];
    }

    #[DataProvider('destinationLessInputs')]
    public function testTheEmptySlotIsNeverWritten(string $html, string $expected): void
    {
        $carve = (new HtmlToCarve())->convert($html);

        $this->assertSame($expected, trim($carve));
        $this->assertStringNotContainsString('](', $carve);
    }

    /**
     * A value that is merely UNUSUAL is not empty, and is kept.
     */
    public function testAnUnusualDestinationIsStillADestination(): void
    {
        $this->assertSame(
            '[click here](#)',
            trim((new HtmlToCarve())->convert('<p><a href="#">click here</a></p>')),
        );
    }

    /**
     * The `title` is not a destination in hiding.
     */
    public function testATitleDoesNotBecomeTheDestination(): void
    {
        $carve = (new HtmlToCarve())->convert('<p><a href="" title="javascript:alert(1)">t</a></p>');

        $this->assertStringNotContainsString('javascript:alert(1)](', $carve);
        $this->assertStringNotContainsString('](javascript', $carve);
    }

    /**
     * Neither is a stored round-trip source, on a converter that trusts it.
     *
     * `roundtrip` mode is the mode that honors `data-djot-*`, so it is the one
     * mode with a provenance record to be tempted by. The hardened render wrote
     * none for the blanked destination - that is the point of §25 - and an
     * importer that went looking anyway would be reading a channel the renderer
     * deliberately left empty.
     *
     * The `title` still rides the span, which is the surviving-attribute half
     * of the same clause: a `title` is not a URL sink and keeping it is not
     * rebuilding a destination. What must not appear is a destination SLOT.
     */
    public function testRoundtripModeRebuildsNoDestinationEither(): void
    {
        $carve = (new HtmlToCarve(trustedRoundTrip: true))->convert(
            '<p><a href="" data-djot-ref="danger" title="vbscript:x">t</a></p>',
        );

        $this->assertSame('[t]{title=vbscript:x}', trim($carve));
        $this->assertStringNotContainsString('](', $carve);
        $this->assertStringNotContainsString('[danger]', $carve);
    }

    /**
     * A surviving attribute rides a SPAN, not an empty link slot.
     *
     * This is the shape the clause rules out by name: `[t](){#k}` re-renders
     * with its `#k` read as a TAG rather than as the element's id, so the
     * anchor's name is lost and a span the author never wrote appears in its
     * place.
     */
    public function testASurvivingAttributeRidesASpan(): void
    {
        $carve = trim((new HtmlToCarve())->convert('<p><a id="k">a named one</a></p>'));

        $this->assertSame('[a named one]{#k}', $carve);
        $this->assertStringNotContainsString('](){#k}', $carve);
        $this->assertSame(
            "<p><span id=\"k\">a named one</span></p>\n",
            (new CarveConverter())->convert($carve),
        );
    }

    /**
     * The whole shape a hardened render actually produces, end to end.
     */
    public function testAHardenedRenderImportsAsItsText(): void
    {
        $hardened = (new CarveConverter())->convert("[click here](javascript:alert(1))\n");
        $this->assertStringContainsString('href=""', $hardened);

        $this->assertSame('click here', trim((new HtmlToCarve())->convert($hardened)));
    }

    /**
     * The loss is observable: a link that comes back as prose says so.
     *
     * It is not the bare `<div>`'s case, where nothing was lost because nothing
     * was carried - an anchor has a slot for a destination, and this one is
     * standing empty.
     */
    public function testTheUnwrapIsReported(): void
    {
        $result = (new HtmlToCarve())->convertWithReport(
            '<p><a href="">click here</a></p><img src="" alt="logo">',
        );

        $this->assertSame(
            ['element-unwrapped', 'element-unwrapped'],
            array_column($result->report()['diagnostics'], 'code'),
        );
        $this->assertSame(
            ['Unwrapped <a> with no destination', 'Unwrapped <img> with no source'],
            array_column($result->report()['diagnostics'], 'message'),
        );
    }

    /**
     * An image's alt text is its CONTENT here, so it is not also a loss.
     *
     * The value is in the emitted document as prose rather than in an attribute
     * position, and the report's oracle asks the OUTPUT for a surviving
     * `alt=` - so without this the report claimed the alt text was dropped
     * while the reader can see it in the paragraph.
     */
    public function testTheAltTextIsNotReportedAsDropped(): void
    {
        $result = (new HtmlToCarve())->convertWithReport('<img src="" alt="logo">');

        $this->assertNotContains('attribute-dropped', array_column($result->report()['diagnostics'], 'code'));
    }
}
