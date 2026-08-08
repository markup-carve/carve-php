<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Extension\GlossaryExtension;
use MarkupCarve\Carve\Extension\HeadingNumbersExtension;
use MarkupCarve\Carve\Extension\HeadingPermalinksExtension;
use MarkupCarve\Carve\Extension\IndexExtension;
use MarkupCarve\Carve\Extension\MentionsExtension;
use MarkupCarve\Carve\Extension\TableOfContentsExtension;
use MarkupCarve\Carve\Extension\TocPlacementExtension;
use MarkupCarve\Carve\Node\Inline\Link;
use MarkupCarve\Carve\Node\Inline\Text;
use MarkupCarve\Carve\Renderer\HeadingIdTracker;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use PHPUnit\Framework\TestCase;

/**
 * PART 9R R4, DERIVED DISPLAY TEXT CLONES THE SAME NODES
 * (markup-carve/carve#957): every consumer that builds visible text out of a
 * heading clones the heading's inline NODES rather than concatenating their
 * text. A node carries the code span, the emphasis and the author's source run;
 * a string carries none of them, and no renderer downstream can recover what the
 * derivation site destroyed.
 *
 * The clause names three consumers plus the core cross-reference. This engine
 * had FIVE flattening derivation sites - the core crossref reached through two
 * producers (the renderer and the in-link resolver), the numbered
 * cross-reference title, both table-of-contents extensions, and the index term's
 * display - and one that was already correct, the glossary term reference, which
 * is why every site is measured here rather than swept.
 *
 * The expectations are carve-js's bytes, measured against `76dadb6`.
 */
class DerivedDisplayTextClonesTheNodesAtEverySiteTest extends TestCase
{
    /**
     * A heading whose markup cannot survive flattening, and a reference to it.
     *
     * @var string
     */
    private const SOURCE = "# `code()` and *bold* h\n\nSee </#code-and-bold-h>.\n";

    public function testTheCoreCrossReferenceKeepsTheHeadingsNodes(): void
    {
        $this->assertSame(
            "<section id=\"code-and-bold-h\">\n"
            . "  <h1><code>code()</code> and <strong>bold</strong> h</h1>\n"
            . "  <p>See <a href=\"#code-and-bold-h\"><code>code()</code> and <strong>bold</strong> h</a>.</p>\n"
            . "</section>\n",
            $this->html(self::SOURCE),
        );
    }

    public function testTheCrossReferenceKeepsTheNodesOnTheMarkdownTarget(): void
    {
        // Each target spells the nodes ITS own way, which is the point of
        // handing them nodes: the code span comes back as a Markdown code span.
        $this->assertSame(
            "# `code()` and **bold** h {#code-and-bold-h}\n\n"
            . "See [`code()` and **bold** h](#code-and-bold-h).\n",
            CarveConverter::markdown()->convert(self::SOURCE),
        );
    }

    public function testTheCodeSpanHeadingIsUNCHANGEDOnThePlainTarget(): void
    {
        // CONTROL, named as one: plain text spells no markup and the flattened
        // string already carried a code span's CONTENT, so this document is
        // byte-identical before and after. It cannot distinguish the two
        // behaviors; the next test can.
        $this->assertSame(
            "code() and bold h\n\nSee code() and bold h.\n",
            CarveConverter::plainText()->convert(self::SOURCE),
        );
    }

    public function testAnInlineFootnotesBODYNoLongerLeaksIntoTheLabel(): void
    {
        // The discriminator on this target, and a defect the ticket did not
        // name. An inline footnote's body is DEFERRED content: it renders once,
        // in the endnotes. The flatten had no arm for the node, so it recursed
        // into the body and republished it as the label - `See h note body x`
        // where the heading itself shows a footnote MARKER. Dropping the pointer
        // is what the footnote reference beside it already got.
        $source = "{#h}\n# h ^[note body] x\n\nSee </#h>.\n";

        $this->assertSame("h (note body) x\n\nSee h  x.\n", CarveConverter::plainText()->convert($source));
        $this->assertStringContainsString('<p>See <a href="#h">h  x</a>.</p>', $this->html($source));
    }

    public function testTheCrossReferenceStylesTheHeadingsEmphasisOnTheAnsiTarget(): void
    {
        $ansi = CarveConverter::ansi()->convert(self::SOURCE);

        // The label carries the terminal's own bold, which a flattened string
        // could not: it had already discarded the Strong node.
        $this->assertStringContainsString("See \u{1b}[4m\u{1b}[34m\u{1b}[93mcode()\u{1b}[0m and \u{1b}[1mbold\u{1b}[0m h", $ansi);
    }

    public function testANumberedCrossReferenceTitleKeepsTheHeadingsNodes(): void
    {
        // NUMBERING, PREFIXING AND JOINING REMAIN THE EXTENSION'S OWN BUSINESS:
        // the label word, the number and the separator are still its text. Only
        // the TITLE part is R4's clone.
        $this->assertSame(
            "<section id=\"code-and-bold-h\">\n"
            . "  <h1><span class=\"section-number\">1</span> <code>code()</code> and <strong>bold</strong> h</h1>\n"
            . "  <p>See <a href=\"#code-and-bold-h\">Section 1 - <code>code()</code> and <strong>bold</strong> h</a>.</p>\n"
            . "</section>\n",
            $this->html(self::SOURCE, new HeadingNumbersExtension()),
        );
    }

    public function testTheInjectedTableOfContentsEntryKeepsTheHeadingsNodes(): void
    {
        $this->assertStringContainsString(
            '<li><a href="#code-and-bold-h"><code>code()</code> and <strong>bold</strong> h</a></li>',
            $this->html("# `code()` and *bold* h\n", new TableOfContentsExtension(position: 'top')),
        );
    }

    public function testThePlacementDirectiveEntryKeepsTheHeadingsNodes(): void
    {
        // Wired to the injected nav alone, the placement directive would keep
        // flattening - they are two extensions with two entry builders, which is
        // why R4 is answered at ONE seam and both call it.
        $this->assertStringContainsString(
            '<li><a href="#code-and-bold-h"><code>code()</code> and <strong>bold</strong> h</a></li>',
            $this->html("::: toc\n:::\n\n# `code()` and *bold* h\n", new TocPlacementExtension()),
        );
    }

    public function testAnIndexTermsDisplayKeepsItsNodes(): void
    {
        $this->assertStringContainsString(
            '<li><strong>bold</strong> <code>c</code> <a href="#idx-bold-c-1" class="index-backref">',
            $this->html("x :index[*bold* `c`] y\n\n::: index\n:::\n", new IndexExtension()),
        );
    }

    public function testInsideLinkIsTheCallersContextNotAFactAboutTheNodes(): void
    {
        // A crossref label and a TOC entry are written into an `<a>` and pass
        // true; an index list item is not an anchor - only the backrefs after
        // the display are - and passes false, so a link the author wrote in the
        // term stays a link. Asserted on the seam because `:index[[a](/u)]` does
        // not parse a link (the `[` closes the marker, in carve-js too), so no
        // document reaches this branch through the index today - which is
        // exactly why the flag has to be the CALLER's and not sniffed here.
        $tracker = new HeadingIdTracker();
        $link = new Link('/u');
        $link->appendChild(new Text('a'));

        $inside = $tracker->deriveDisplayNodes([$link], true);
        $this->assertCount(1, $inside);
        $this->assertInstanceOf(Text::class, $inside[0]);

        $outside = $tracker->deriveDisplayNodes([$link], false);
        $this->assertCount(1, $outside);
        $this->assertInstanceOf(Link::class, $outside[0]);
        $this->assertSame('/u', $outside[0]->getDestination());
    }

    public function testAGlossaryTermReferenceIsUNCHANGED(): void
    {
        // CONTROL, and the reason this file measures each site instead of
        // sweeping them: this consumer renders the term's nodes IN PLACE and was
        // already correct. A fix that assumed every derivation site was broken
        // would have rewritten a working one.
        $this->assertSame(
            "<p>See <span class=\"term\"><strong>b</strong> <code>c</code></span>.</p>\n",
            $this->html("See :term[*b* `c`].\n", new GlossaryExtension()),
        );
    }

    public function testAHeadingWithNoInlineMarkupIsUNCHANGED(): void
    {
        // CONTROL, named as one: this is the shape every existing fixture and
        // every corpus cross-reference case uses, and it renders identically
        // whether the label is nodes or a string. It proves the change is
        // conservative; it cannot prove the change happened.
        $this->assertSame(
            "<section id=\"Getting-Started\">\n"
            . "  <h1>Getting Started</h1>\n"
            . "  <p>See <a href=\"#Getting-Started\">Getting Started</a>.</p>\n"
            . "</section>\n",
            $this->html("# Getting Started\n\nSee </#getting-started>.\n"),
        );
    }

    public function testACaptionTargetKeepsTheComposedString(): void
    {
        // CONTROL: a numbered caption registers its label as an already-composed
        // string ("Figure 1") and has no heading nodes behind it, so the string
        // path stays. R4's clone has nothing to clone here.
        $this->assertStringContainsString(
            '<p>See <a href="#fig-a">Figure 1</a>.</p>',
            $this->html("{#fig-a}\n![a](i.png)\n^ Figure #: a *bold* cap\n\nSee </#fig-a>.\n"),
        );
    }

    public function testAFootnoteReferenceInTheHeadingPublishesNoSecondBacklink(): void
    {
        $html = $this->html("# h[^f] x\n\nSee </#h-x>.\n\n[^f]: note\n");

        // A footnote reference is a POINTER into the endnotes, not display text:
        // a second copy would publish a duplicate `fnref1` id inside the
        // crossref's own anchor and point the backlink at whichever rendered
        // last. Exactly one `fnref1` exists.
        $this->assertSame(1, substr_count($html, 'id="fnref1"'));
        $this->assertStringContainsString('<a href="#h-x">h x</a>', $html);
    }

    public function testAnAbbreviationInTheHeadingReducesToTheAuthorsShortForm(): void
    {
        $html = $this->html("*[HTML]: HyperText\n\n# an HTML h\n\nSee </#an-HTML-h>.\n");

        // An abbreviation is an R3 RESOLUTION RESULT. Cloning the resolved node
        // republishes the whole expansion once per derived site, an
        // amplification the body renderer's budget cannot reach from here.
        $this->assertSame(1, substr_count($html, '<abbr title="HyperText">'));
        $this->assertStringContainsString('<a href="#an-HTML-h">an HTML h</a>', $html);
    }

    public function testAnIndexMarkerInTheHeadingPublishesNoSecondAnchorId(): void
    {
        $html = $this->html("# h :index[t] x\n\nSee </#h-x>.\n", new IndexExtension());

        // An `:index[term]` marker is INVISIBLE (PART 9 §8.1): it emits no
        // visible text, so it is not display text anywhere it is derived, and
        // its `idx-` anchor id is published exactly once.
        $this->assertSame(1, substr_count($html, 'idx-t-'));
    }

    public function testASectionNumberSpanIsDroppedFromTheLabelWHOEVERWroteIt(): void
    {
        // The injected span is kept out of the label by ORDERING - HeadingNumbers
        // resolves every id, and so takes the snapshot, before it prepends the
        // span - so the class-keyed rule below would be a check that cannot fail
        // if this case did not exist. It fires on an AUTHORED
        // `[v1]{.section-number}`, which this engine has always excluded from the
        // heading's derived text: the drop and the id slug read one rule
        // (HeadingIdTracker::inlineTextLeaf), so a label and the id it points at
        // cannot disagree about what the heading says.
        //
        // KNOWN DIVERGENCE, measured against carve-js `76dadb6`: carve-js keeps
        // an authored span in BOTH the id (`h-v1-x`) and the label. That is the
        // id rule's divergence and predates R4 - it is recorded here because
        // this is where it becomes visible, not introduced here.
        $this->assertSame(
            "<section id=\"h-x\">\n"
            . "  <h1>h <span class=\"section-number\">v1</span> x</h1>\n"
            . "  <p>See <a href=\"#h-x\">h  x</a>.</p>\n"
            . "</section>\n",
            $this->html("# h [v1]{.section-number} x\n\nSee </#h-x>.\n"),
        );
    }

    public function testRawInlineHtmlInTheHeadingNeverReachesTheLabel(): void
    {
        // Format-specific raw inline is excluded from a heading's derived text on
        // every target, which is also what keeps a permalink anchor emitted as
        // raw HTML out of a label.
        $this->assertSame(
            "<section id=\"h-x\">\n"
            . "  <h1>h <b>z</b> x</h1>\n"
            . "  <p>See <a href=\"#h-x\">h  x</a>.</p>\n"
            . "</section>\n",
            $this->html("# h `<b>z</b>`{=html} x\n\nSee </#h-x>.\n"),
        );
    }

    public function testALinkInTheHeadingOpensNoAnchorInsideTheCrossReferences(): void
    {
        // LINKS NEVER NEST (PART 12 §3a). The label is placed inside an `<a>`,
        // so a link in it unwraps to its display content.
        $this->assertSame(
            "<section id=\"h-a-x\">\n"
            . "  <h1>h <a href=\"/u\">a</a> x</h1>\n"
            . "  <p>See <a href=\"#h-a-x\">h a x</a>.</p>\n"
            . "</section>\n",
            $this->html("# h [a](/u) x\n\nSee </#h-a-x>.\n"),
        );
    }

    public function testAMentionInTheHeadingOpensNoAnchorInsideTheCrossReferences(): void
    {
        // A Mention IS a Link in this engine, so the unwrap above covers it -
        // which is also the answer this engine already gives for `[see @bob](/u)`.
        $html = $this->html(
            "# h @bob\n\nSee </#h-bob>.\n",
            new MentionsExtension(mentionUrl: 'https://x/{name}'),
        );

        $this->assertSame(1, substr_count($html, 'class="mention"'));
        $this->assertStringContainsString('<a href="#h-bob">h @bob</a>', $html);
    }

    public function testAPermalinkAnchorNeverReachesTheLabel(): void
    {
        // THE LABEL IS TAKEN BEFORE ANY RENDER-STAGE INJECTION. This extension
        // injects on a render EVENT, after the label snapshot exists, so the
        // anchor never enters it - and left in, a resolved `</#id>` would render
        // an `<a>` inside its own `<a>`.
        $html = $this->html("# `code()` h\n\nSee </#code-h>.\n", new HeadingPermalinksExtension());

        $this->assertSame(1, substr_count($html, 'class="permalink"'));
        $this->assertStringContainsString('<a href="#code-h"><code>code()</code> h</a>', $html);
    }

    public function testResolutionStaysOneLevel(): void
    {
        // A `</#id>` inside the target heading contributes NOTHING to the label,
        // so a cycle is structurally impossible to follow. Corpus
        // `118-cyclic-cross-reference-resolves-to-one-level` pins these bytes.
        $this->assertSame(
            "<section id=\"A\">\n  <h1>A <a href=\"#B\">B </a></h1>\n</section>\n"
            . "<section id=\"B\">\n  <h1>B <a href=\"#A\">A </a></h1>\n</section>\n",
            $this->html("# A </#b>\n\n# B </#a>\n"),
        );
    }

    public function testTheLabelAndTheHeadingAreTwoTrees(): void
    {
        // The in-link producer REWRITES the label in place (a nested link is
        // unwrapped). A shallow copy would rewrite the heading with it, so the
        // heading below would lose its own anchor. The deep copy is what makes
        // "clones the same nodes" safe to state.
        $this->assertSame(
            "<section id=\"h-a-x\">\n"
            . "  <h1>h <a href=\"/u\">a</a> x</h1>\n"
            . "  <p><a href=\"/o\">see h a x</a></p>\n"
            . "</section>\n",
            $this->html("# h [a](/u) x\n\n[see </#h-a-x>](/o)\n"),
        );
    }

    private function html(string $source, object ...$extensions): string
    {
        $converter = new CarveConverter(renderer: new HtmlRenderer());
        foreach ($extensions as $extension) {
            $converter->addExtension($extension);
        }

        return $converter->convert($source);
    }
}
