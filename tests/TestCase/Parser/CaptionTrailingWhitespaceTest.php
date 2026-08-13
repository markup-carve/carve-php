<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\Caption;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Node\Inline\Text;
use MarkupCarve\Carve\Parser\BlockParser;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use PHPUnit\Framework\TestCase;

/**
 * A caption line is a CONTENT LINE, so PART 2 NO TRAILING WHITESPACE applies to
 * it: a `whitespace` run at the end of one is DROPPED. Corpus 268 pins the rule
 * for a paragraph, a heading, a list item, a block quote and a definition; its
 * caption row (`268-…-6`) is the table caption below.
 *
 * The rule was applied to the SOURCE for every one of those constructs and to
 * NEITHER source nor AST for a caption. HTML looked right only because
 * HtmlRenderer trimmed its own output at the three places it writes a caption,
 * and that substitution is what the paragraph collector's note warns against: a
 * renderer cannot tell an authored trailing space from one a construct
 * legitimately produced. So it also ate the content of an all-space inline
 * literal, and the published AST kept the space whatever the renderer did
 * (markup-carve/carve#963).
 *
 * Both halves are asserted here, because either one alone leaves the other free
 * to regress: the AST value is what the divergence was reported on, and the
 * rendered literal is what the renderer-side trim destroyed.
 */
class CaptionTrailingWhitespaceTest extends TestCase
{
    private CarveConverter $converter;

    private AstCodec $codec;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
        $this->codec = new AstCodec();
    }

    /**
     * Every `text` node's value, in document order.
     *
     * @param string $source
     *
     * @return array<string>
     */
    private function textValues(string $source): array
    {
        $encoded = $this->codec->encode($this->converter->parse($source));
        $values = [];
        $walk = function (mixed $node) use (&$walk, &$values): void {
            if (is_array($node) && !isset($node['type'])) {
                foreach ($node as $child) {
                    $walk($child);
                }

                return;
            }
            if (!is_array($node)) {
                return;
            }
            if ($node['type'] === 'text') {
                $values[] = $node['value'];
            }
            foreach ($node as $key => $value) {
                if ($key === 'pos' || !is_array($value)) {
                    continue;
                }
                $walk($value);
            }
        };
        $walk($encoded);

        return $values;
    }

    /**
     * Corpus 268-trailing-whitespace-on-a-content-line-is-dropped-6, verbatim.
     * Its HTML fixture passed before this fix and after it; only the AST could
     * see the difference, which is why `npm run ast:check` was the finder.
     */
    public function testATableCaptionDropsItsTrailingSpace(): void
    {
        // The caption sorts before the rows on the wire, so it leads here.
        $this->assertSame(['Cap', 'a'], $this->textValues("| a |\n^ Cap \n"));
    }

    public function testAFigureCaptionDropsItsTrailingSpace(): void
    {
        $this->assertSame(['Cap'], $this->textValues("![a](/u)\n^ Cap \n"));
    }

    /**
     * The three remaining caption hosts. A code block becomes a figure with the
     * same caption and a block quote takes an attribution instead, and all five
     * `new Caption()` sites in the parser read ONE `$captionText` - so the rule
     * is applied once, where that text is built, rather than five times where
     * it is consumed.
     */
    public function testEveryCaptionHostDropsTheTrailingSpace(): void
    {
        // A code block's body is a scalar, not a text node, so the caption is
        // the whole of the first list.
        $this->assertSame(['Cap'], $this->textValues("```\ncode\n```\n^ Cap \n"));
        // A quote's caption is its ATTRIBUTION (PART 9 §4a, carve#1159), which
        // sorts before `children` on the wire - so the attribution text leads,
        // the way a table caption does.
        $this->assertSame(['Cap', 'q'], $this->textValues("> q\n\n^ Cap \n"));
    }

    /**
     * A TAB, not only a space. `whitespace = ' ' | '\t'` (PART 1), so the trim
     * takes both and nothing else.
     */
    public function testACaptionDropsATrailingTab(): void
    {
        $this->assertSame(['Cap'], $this->textValues("![a](/u)\n^ Cap\t\n"));
    }

    /**
     * EVERY line of a folded caption, not just its first. A caption folds its
     * continuation lines exactly as a paragraph does, and the paragraph rule
     * was itself once written for the final line alone - which by construction
     * could not reach an interior one (markup-carve/carve#926).
     */
    public function testAFoldedCaptionLineDropsItsTrailingSpaceToo(): void
    {
        // `one` and `two` are one text run either side of a soft break, so the
        // interior line's trailing space would show up inside the first value.
        $this->assertSame(['one', 'two'], $this->textValues("![a](/u)\n^ one \ntwo \n"));
    }

    /**
     * Only ASCII space and tab. A trailing NBSP is CONTENT and survives - the
     * row a plain-space fixture cannot see, and the one an implementation that
     * trims with a Unicode whitespace property gets wrong.
     */
    public function testATrailingNonBreakingSpaceIsCaptionContent(): void
    {
        $this->assertSame(["Cap\u{00A0}"], $this->textValues("![a](/u)\n^ Cap\u{00A0}\n"));
    }

    /**
     * The charlist, pinned by the one character that can show it.
     *
     * PHP's default `rtrim` charlist is `" \t\n\r\0\x0B"`, which is WIDER than
     * `whitespace`. Of the four extra characters, a newline cannot appear
     * inside a line, a carriage return is normalized away before the block
     * layer sees it, and a NUL is replaced with U+FFFD upstream - so a VERTICAL
     * TAB is the whole of the difference, and a fixture built from spaces and
     * tabs cannot tell the two spellings apart.
     *
     * A heading in this engine still drops it, which is the same rule spelled a
     * third way and is tracked separately (markup-carve/carve-php#1038): the
     * charlist there cannot move on its own, because the heading's emptiness
     * gate spells `whitespace` with PCRE `\S` and would then refuse a heading
     * whose whole content is a vertical tab while accepting a trailing one.
     */
    public function testAVerticalTabIsCaptionContent(): void
    {
        $verticalTab = "\u{000B}";

        // The paragraph is the construct the caption has to agree with.
        $this->assertSame(["a{$verticalTab}"], $this->textValues("a{$verticalTab}\n"));
        $this->assertSame(
            ["a{$verticalTab}"],
            $this->textValues("![a](/u)\n^ a{$verticalTab}\n"),
        );
    }

    /**
     * What the renderer-side trim destroyed. An all-space inline literal is
     * spaces the CONSTRUCT produced, not whitespace the author left at the end
     * of a line, and it renders identically inside a caption and inside a
     * paragraph.
     */
    public function testAnAllSpaceInlineLiteralSurvivesInACaption(): void
    {
        $paragraph = $this->converter->convert("x !`  `\n");
        $this->assertSame("<p>x   </p>\n", $paragraph);

        $this->assertSame(
            "<table>\n  <caption>x   </caption>\n  <tbody>\n    <tr><td>a</td></tr>\n  </tbody>\n</table>\n",
            $this->converter->convert("| a |\n^ x !`  `\n"),
        );
        $this->assertSame(
            "<figure>\n  <img src=\"/u\" alt=\"a\">\n  <figcaption>x   </figcaption>\n</figure>\n",
            $this->converter->convert("![a](/u)\n^ x !`  `\n"),
        );
    }

    /**
     * The third caption writer, reached on its own.
     *
     * `HtmlRenderer::renderCaption()` is the dispatch table's fallback for a
     * `Caption` that is not a child of a figure and not a table's - and no
     * PARSE produces one, so it does not run once across this suite. Only a
     * consumer building a tree by hand can reach it, which is why the mutation
     * that restored its trim alone survived everything else here. It is written
     * out rather than deleted, so it gets the same rule the other two writers
     * got.
     */
    public function testTheStandaloneCaptionWriterKeepsItsContentToo(): void
    {
        $document = new Document();
        $caption = new Caption();
        $caption->appendChild(new Text('x  '));
        $document->appendChild($caption);

        $this->assertSame("<figcaption>x  </figcaption>\n", (new HtmlRenderer())->render($document));
    }

    /**
     * The one thing the removed trim was load-bearing for: a hard break writes
     * `<br>` plus a NEWLINE, and trimming that newline away made a caption the
     * only place in the document where a hard break rendered differently. It
     * now ends a caption exactly as it ends a paragraph, which is also what
     * carve-js emits.
     */
    public function testAHardBreakEndsACaptionAsItEndsAParagraph(): void
    {
        $this->assertSame("<p>Cap<br>\n</p>\n", $this->converter->convert("Cap\\\n"));
        $this->assertSame(
            "<table>\n  <caption>Cap<br>\n</caption>\n  <tbody>\n    <tr><td>a</td></tr>\n  </tbody>\n</table>\n",
            $this->converter->convert("| a |\n^ Cap\\\n"),
        );
    }

    /**
     * The span follows the value. `checkPositions` asserts that a text node's
     * source slice IS its value, so a trimmed value with an untrimmed span
     * would trade one divergence for another.
     */
    public function testTheCaptionTextSpanShrinksWithTheValue(): void
    {
        // Positions are opt-in, exactly as the `--json` CLI path builds them.
        $tracking = new CarveConverter(parser: new BlockParser(false, false, false, true));
        $table = $this->codec->encode($tracking->parse("| a |\n^ Cap \n"))['children'][0];
        $pos = $table['caption'][0]['pos'];

        $this->assertSame(8, $pos['startOffset']);
        $this->assertSame(11, $pos['endOffset']);
        $this->assertSame(3, $pos['startColumn']);
        $this->assertSame(6, $pos['endColumn']);
    }
}
