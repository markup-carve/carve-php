<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\Heading;
use MarkupCarve\Carve\Node\Inline\CaptionNumber;
use MarkupCarve\Carve\Node\Inline\Code;
use MarkupCarve\Carve\Node\Inline\Emphasis;
use MarkupCarve\Carve\Node\Inline\EscapedText;
use MarkupCarve\Carve\Node\Inline\InlineExtension;
use MarkupCarve\Carve\Node\Inline\RawInline;
use MarkupCarve\Carve\Node\Inline\SmartPunctuation;
use MarkupCarve\Carve\Node\Inline\SoftBreak;
use MarkupCarve\Carve\Node\Inline\Span;
use MarkupCarve\Carve\Node\Inline\Symbol;
use MarkupCarve\Carve\Node\Inline\Text;
use MarkupCarve\Carve\Renderer\AnsiRenderer;
use MarkupCarve\Carve\Renderer\HeadingIdTracker;
use MarkupCarve\Carve\Renderer\MarkdownRenderer;
use MarkupCarve\Carve\Renderer\PlainTextRenderer;
use MarkupCarve\Carve\Renderer\SmartTypographyMode;
use PHPUnit\Framework\TestCase;

/**
 * PART 9R R4 (markup-carve/carve#952): a resolved cross-reference's label is
 * the target heading's inline NODES cloned, not its rendered glyphs.
 *
 * R4 already said "cloned heading text"; what it did not say is that the clone
 * is of NODES rather than of a rendered string - which is the whole difference,
 * because a node carries its source run and a string does not. This engine
 * flattened the heading to a glyph string at ID-TRACKING time, so smart
 * typography's SOURCE mode could not recover it on any presentation target and
 * no renderer change could reach it: the fix has to be where the label is
 * CAPTURED, not where typography is applied.
 *
 * The four expectations are the optional corpus cases
 * `36-crossref-label-typography-source`,
 * `37-crossref-label-typography-source-markdown`,
 * `38-crossref-label-typography-source-ansi` and
 * `39-crossref-label-typography-glyphs`, byte-exact.
 */
class CrossReferenceLabelKeepsItsSourceRunTest extends TestCase
{
    /**
     * The corpus document for all four cases.
     *
     * @var string
     */
    protected const SOURCE = "# The \"quoted\" -- heading\n\nSee </#The-quoted-heading>\n";

    public function testThePlainTextTargetRecoversTheSourceRun(): void
    {
        $renderer = (new PlainTextRenderer())->setSmartTypography(SmartTypographyMode::Source);

        $this->assertSame(
            "The \"quoted\" -- heading\n\nSee The \"quoted\" -- heading\n",
            (new CarveConverter(renderer: $renderer))->convert(self::SOURCE),
        );
    }

    public function testTheMarkdownTargetRecoversTheSourceRun(): void
    {
        $renderer = (new MarkdownRenderer())->setSmartTypography(SmartTypographyMode::Source);

        $this->assertSame(
            "# The \"quoted\" -- heading {#The-quoted-heading}\n"
            . "\nSee [The \"quoted\" -- heading](#The-quoted-heading)\n",
            (new CarveConverter(renderer: $renderer))->convert(self::SOURCE),
        );
    }

    /**
     * The ANSI target carries the mode too, and its heading rule is a COLUMN
     * count of the RENDERED heading rather than of the source: 23 columns for
     * the source spelling against 22 for the glyph spelling, so the rule width
     * moves with the mode. That is what makes this more than a repeat of the
     * plain-text case.
     */
    public function testTheAnsiTargetRecoversTheSourceRunAndItsRuleFollows(): void
    {
        $renderer = (new AnsiRenderer())->setSmartTypography(SmartTypographyMode::Source);

        $this->assertSame(
            "\e[1m\e[95mThe \"quoted\" -- heading\e[0m\n"
            . "\e[95m" . str_repeat("\u{2550}", 23) . "\e[0m\n"
            . "\nSee \e[4m\e[34mThe \"quoted\" -- heading\e[0m\n",
            (new CarveConverter(renderer: $renderer))->convert(self::SOURCE),
        );
    }

    /**
     * CONTROL, and the corpus says why it exists: without it the three cases
     * above would also pass an engine that never applies typography to a
     * cross-reference label in EITHER mode. At default typography the label
     * takes the glyphs, exactly as the heading does.
     */
    public function testTheDefaultModeStillTakesTheGlyphs(): void
    {
        $this->assertSame(
            "The \u{201C}quoted\u{201D} \u{2013} heading\n\nSee The \u{201C}quoted\u{201D} \u{2013} heading\n",
            (new CarveConverter(renderer: new PlainTextRenderer()))->convert(self::SOURCE),
        );
    }

    /**
     * The SECOND producer of a crossref label. A cross-reference inside a link
     * would render as a nested anchor, so `CrossReferenceResolver` rewrites it
     * to a Text node - before any renderer sees it, which is why passing the
     * mode to the renderers alone left this path emitting glyphs while the
     * sibling path one function away emitted the run.
     */
    public function testACrossReferenceInsideALinkRecoversTheSourceRunToo(): void
    {
        $renderer = (new PlainTextRenderer())->setSmartTypography(SmartTypographyMode::Source);

        $this->assertSame(
            "The \"quoted\" -- heading\n\nSee The \"quoted\" -- heading\n",
            (new CarveConverter(renderer: $renderer))->convert(
                "# The \"quoted\" -- heading\n\nSee [</#The-quoted-heading>](/u)\n",
            ),
        );
    }

    /**
     * CONTROL for the producer above: at default typography the in-link label
     * is the glyph spelling, so the case is measuring the mode rather than the
     * flattening.
     */
    public function testACrossReferenceInsideALinkStillTakesTheGlyphsByDefault(): void
    {
        $this->assertSame(
            "The \u{201C}quoted\u{201D} \u{2013} heading\n\nSee The \u{201C}quoted\u{201D} \u{2013} heading\n",
            (new CarveConverter(renderer: new PlainTextRenderer()))->convert(
                "# The \"quoted\" -- heading\n\nSee [</#The-quoted-heading>](/u)\n",
            ),
        );
    }

    /**
     * The in-link rewrite MUTATES the document, so it must not answer the mode
     * question. Rendering one parsed document twice, in either order, gives
     * each render its own answer.
     *
     * Materializing the label as a string there made the second render inherit
     * the first render's mode: the same document came out with a curly-quoted
     * heading and a typed-quote label. Splicing the label as NODES leaves the
     * one decision a renderer owns open until the renderer runs.
     */
    public function testReRenderingOneDocumentGivesEachModeItsOwnLabel(): void
    {
        $source = "# The \"quoted\" -- heading\n\nSee [</#The-quoted-heading>](/u)\n";
        $glyphs = "The \u{201C}quoted\u{201D} \u{2013} heading\n\nSee The \u{201C}quoted\u{201D} \u{2013} heading\n";
        $runs = "The \"quoted\" -- heading\n\nSee The \"quoted\" -- heading\n";

        $sourceMode = fn (): CarveConverter => new CarveConverter(
            renderer: (new PlainTextRenderer())->setSmartTypography(SmartTypographyMode::Source),
        );
        $glyphMode = fn (): CarveConverter => new CarveConverter(renderer: new PlainTextRenderer());

        $document = (new CarveConverter())->parse($source);
        $this->assertSame($runs, $sourceMode()->render($document));
        $this->assertSame($glyphs, $glyphMode()->render($document));

        $again = (new CarveConverter())->parse($source);
        $this->assertSame($glyphs, $glyphMode()->render($again));
        $this->assertSame($runs, $sourceMode()->render($again));
    }

    /**
     * The HEADING ID is slugged from the GLYPH and does not move with the mode.
     * `Don't` has always given `Don-t`, and putting the substitution in a node
     * must not change that - so the label and the id read the two halves of the
     * same node differently, on purpose.
     */
    public function testTheHeadingIdIsUnchangedInSourceMode(): void
    {
        $renderer = (new MarkdownRenderer())->setSmartTypography(SmartTypographyMode::Source);
        $out = (new CarveConverter(renderer: $renderer))->convert("# Don't -- stop\n\nSee </#Don-t-stop>\n");

        $this->assertStringContainsString('{#Don-t-stop}', $out);
        $this->assertStringContainsString("See [Don't -- stop](#Don-t-stop)", $out);
    }

    /**
     * The label is captured as a CLONE, so a heading the tracker has already
     * seen does not change it afterwards. The glyph path already had this via
     * its cached string; the node path has to hold it too, or the fix would
     * trade one materialization bug for another - and an extension appending to
     * a heading after id tracking (HeadingPermalinksExtension adds a permalink
     * symbol) is the shape that would otherwise leak in.
     */
    public function testTheLabelIsCapturedAndDoesNotFollowALaterMutation(): void
    {
        $emphasis = new Emphasis();
        $emphasis->appendChild(new Text('em'));

        $heading = new Heading(1);
        $heading->appendChild(new Text('Intro'));
        $heading->appendChild(new SmartPunctuation('en_dash', '--'));
        $heading->appendChild($emphasis);

        $tracker = new HeadingIdTracker();
        $id = $tracker->getIdForHeading($heading);

        // Both shapes a later pass can take: appending to the HEADING, which an
        // array copy of its children would already survive, and appending
        // INSIDE one of them, which only a clone does. The second is what makes
        // this case able to fail - with the children stored live, the label
        // picks the nested text up.
        $heading->appendChild(new Text(' APPENDED'));
        $emphasis->appendChild(new Text('DEEP'));

        $this->assertSame('Intro--em', $tracker->getTextForId($id, SmartTypographyMode::Source));
        $this->assertSame("Intro\u{2013}em", $tracker->getTextForId($id));
    }

    /**
     * A heading whose text holds a CONTAINER (emphasis here) recurses into it
     * for the label, and the run inside it survives the same way. Nothing about
     * the rule is confined to a top-level smart-typography node.
     */
    public function testANestedContainerInTheHeadingKeepsItsRun(): void
    {
        $renderer = (new PlainTextRenderer())->setSmartTypography(SmartTypographyMode::Source);
        $source = "# A *bold -- run* heading\n\nSee </#A-bold-run-heading>\n";

        $this->assertSame(
            "A bold -- run heading\n\nSee A bold -- run heading\n",
            (new CarveConverter(renderer: $renderer))->convert($source),
        );
    }

    /**
     * The same heading reached through the IN-LINK producer, which walks the
     * cloned nodes rather than the string. The label stays FLAT - the emphasis
     * does not come with it - because a cross-reference label is plain text on
     * every target.
     */
    public function testANestedContainerFlattensOnTheInLinkPath(): void
    {
        $source = "# A *bold -- run* heading\n\nSee [</#A-bold-run-heading>](/u)\n";

        $this->assertSame(
            "<section id=\"A-bold-run-heading\">\n"
            . "  <h1>A <strong>bold \u{2013} run</strong> heading</h1>\n"
            . "  <p>See <a href=\"/u\">A bold \u{2013} run heading</a></p>\n"
            . "</section>\n",
            (new CarveConverter())->convert($source),
        );
    }

    /**
     * An UNRESOLVED cross-reference inside a link keeps its literal source, as
     * it does everywhere else. There is no id, so there are no nodes to ask for.
     */
    public function testAnUnresolvedCrossReferenceInsideALinkKeepsItsSource(): void
    {
        $this->assertSame(
            "<p>See <a href=\"/u\">&lt;/#nope&gt;</a></p>\n",
            (new CarveConverter())->convert("See [</#nope>](/u)\n"),
        );
    }

    /**
     * A NUMBERED CAPTION id reached through the in-link producer: no heading
     * behind it, so it falls back to the composed string. The two branches of
     * that fallback are the reason this is not the same case as the plain
     * numbered-caption one above.
     */
    public function testANumberedCaptionInsideALinkUsesTheStringPath(): void
    {
        $source = "{#fig-sun}\n![A sunset](sun.jpg)\n^ Figure #: A sunset\n\nSee [</#fig-sun>](/u).\n";

        $this->assertStringContainsString(
            '<a href="/u">Figure 1</a>',
            (new CarveConverter())->convert($source),
        );
    }

    /**
     * The LEAF TABLE itself, through the public entry point every label and
     * every id slug goes through. Each row is a separate decision - an
     * `:index[]` marker and a section-number span contribute nothing, a raw
     * inline is excluded, a caption number contributes its digits - and they
     * are asserted together because they are one table.
     */
    public function testThePlainTextLeafTableCoversEveryInlineKind(): void
    {
        $number = new CaptionNumber();
        $number->setNumber(7);

        $emphasis = new Emphasis();
        $emphasis->appendChild(new Text('deep'));

        $sectionNumber = new Span();
        $sectionNumber->setAttribute('class', 'section-number');
        $sectionNumber->appendChild(new Text('1.2'));

        $index = new InlineExtension('index');
        $index->appendChild(new Text('term'));

        $heading = new Heading(1);
        foreach (
            [
                new Text('a'),
                new EscapedText('*'),
                $number,
                new SoftBreak(),
                new Code('c'),
                new Symbol('rocket'),
                new RawInline('<b>', 'html'),
                $sectionNumber,
                $index,
                $emphasis,
            ] as $child
        ) {
            $heading->appendChild($child);
        }

        $this->assertSame('a*7 c:rocket:deep', (new HeadingIdTracker())->getPlainText($heading));
    }

    /**
     * A NUMBERED CAPTION id has no nodes behind it - its label is composed as a
     * string ("Figure 1") while the numbers are resolved - so it keeps the
     * string path in both modes. Without this the source branch would have to
     * answer for an id it never captured.
     */
    public function testANumberedCaptionLabelIsUnaffected(): void
    {
        $renderer = (new PlainTextRenderer())->setSmartTypography(SmartTypographyMode::Source);
        $source = "{#fig-sun}\n![A sunset](sun.jpg)\n^ Figure #: A sunset\n\nSee </#fig-sun>.\n";

        $this->assertStringContainsString(
            'See Figure 1.',
            (new CarveConverter(renderer: $renderer))->convert($source),
        );
    }
}
