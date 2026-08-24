<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use MarkupCarve\Carve\Node\Block\Comment;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Node\Inline\Image;
use MarkupCarve\Carve\Node\Inline\Text;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * markup-carve/carve#1658. The writer states its contract as an absolute - what
 * it returns re-reads as what it was given - while carrying an exception nothing
 * declares: a paragraph whose whole content is one image is written as a bare
 * block image and re-reads as one.
 *
 * THE DEFECT IS THE CONTRACT, NOT THE NORMALIZATION. A contract that is true
 * except quietly is worse than a narrower one that is true as written, because
 * every reader of the first is entitled to rely on it. So the ruling keeps the
 * normalization and amends the contract to name its carve-outs, and these tests
 * are what make that text load-bearing rather than decoration.
 *
 * The two rejected options are pinned as well as the chosen one, so a later
 * change that reaches for either fails here rather than in review:
 *
 * - the writer must NOT indent. It is lossless at the top level and nowhere
 *   else - inside a list item the marker absorbs the padding at every width -
 *   and it would make the writer emit meaning-bearing leading whitespace, which
 *   editors and pipelines strip.
 * - the writer must NOT refuse. It is what it already does for an empty raw
 *   inline, but it would break every import of a paragraph-wrapped image, and it
 *   contradicts `docs/html-import.md`, which says that exit REPORTS the loss.
 */
class TheWriterContractNamesItsCarveOutsTest extends TestCase
{
    /**
     * THE CHOSEN OPTION, PINNED. The writer normalizes and returns: it does not
     * indent, and it does not throw.
     */
    public function testTheWriterNormalizesTheShapeRatherThanIndentingOrRefusing(): void
    {
        $document = new Document();
        $paragraph = new Paragraph();
        $paragraph->appendChild(new Image('g.jpg', 'G'));
        $document->appendChild($paragraph);

        $written = (new CarveRenderer())->render($document);

        $this->assertSame("![G](g.jpg)\n", $written);
        $this->assertStringStartsNotWith(
            ' ',
            $written,
            'the writer must not reach for an indented spelling',
        );
    }

    /**
     * THE SECOND CARVE-OUT, and it is the one a hand-built tree reaches. An
     * empty paragraph writes nothing, so the re-read document is one block
     * shorter - and the writer neither indents nor refuses there either. No
     * source spells it: a blank line is a separator, not a block.
     *
     * The parser cannot build one, which is exactly why it has to be NAMED
     * rather than discovered - a caller handing the writer an ingested tree is
     * entitled to know before it happens (carve#1658).
     */
    public function testAnEmptyParagraphIsTheOtherCarveOut(): void
    {
        $document = new Document();
        $document->appendChild(new Paragraph());
        $kept = new Paragraph();
        $kept->appendChild(new Text('after'));
        $document->appendChild($kept);

        $written = (new CarveRenderer())->render($document);

        $this->assertSame("after\n", $written);
        $this->assertCount(
            1,
            (new CarveConverter())->parse($written)->getChildren(),
            'the empty paragraph is gone from the source, and the writer said nothing',
        );
    }

    /**
     * THE CARVE-OUT IS EXACTLY ONE SHAPE, and this is the measurement that says
     * so rather than an assumption. carve#1658 asked for the property to be
     * stated rather than the node type, and for the answer to be closed rather
     * than left open: every other single-child paragraph the importer can build
     * comes back as the paragraph it was.
     *
     * @return array<string, array{string}>
     */
    public static function survivingSingleChildProvider(): array
    {
        $cases = [
            'a link' => '<p><a href="u">t</a></p>',
            'a code span' => '<p><code>c</code></p>',
            'an emphasis' => '<p><em>e</em></p>',
            'a strong' => '<p><strong>b</strong></p>',
            'a span' => '<p><span class="x">s</span></p>',
            'plain text' => '<p>text</p>',
            'a hard break' => '<p><br></p>',
            'a quote' => '<p><q>q</q></p>',
            'a subscript' => '<p><sub>s</sub></p>',
            'a superscript' => '<p><sup>s</sup></p>',
            'a kbd span' => '<p><kbd>k</kbd></p>',
            'an abbreviation' => '<p><abbr title="t">A</abbr></p>',
            'a critic delete' => '<p><del>d</del></p>',
            'a critic insert' => '<p><ins>i</ins></p>',
            'a highlight' => '<p><mark>m</mark></p>',
            'a cite span' => '<p><cite>c</cite></p>',
            'a var span' => '<p><var>v</var></p>',
            'an underline' => '<p><u>u</u></p>',
            'a strike' => '<p><s>s</s></p>',
            // An image inside a LINK is not the shape: the paragraph's one child
            // is the link, which has a spelling of its own and keeps it.
            'a link around an image' => '<p><a href="u"><img src="g.jpg" alt="G"></a></p>',
        ];

        return array_map(static fn (string $html): array => [$html], $cases);
    }

    #[DataProvider('survivingSingleChildProvider')]
    public function testEveryOtherSingleChildParagraphSurvivesTheWriter(string $html): void
    {
        $written = (new HtmlToCarve())->convert($html);
        $kinds = array_map(
            static fn (object $node): string => $node::class,
            (new CarveConverter())->parse($written)->getChildren(),
        );

        $this->assertSame([Paragraph::class], $kinds, $written);
    }

    /**
     * The shape itself, and the same shape reached through a wrapper that writes
     * nothing of its own. Both are ONE shape as far as the writer is concerned:
     * both re-read as a block image, so both are the carve-out.
     *
     * @return array<string, array{string, bool}>
     */
    public static function normalizedProvider(): array
    {
        return [
            // Declared: the importer records a lone-image `<p>` and reports it
            // (carve-php#1667).
            'a lone image' => ['<p><img src="g.jpg" alt="G"></p>', true],
            // DECLARED TOO, and this expectation used to be `false`. The
            // importer's predicate read the `<p>`'s DIRECT children, so an
            // `<img>` behind a wrapper was not recognized even though the writer
            // normalizes it identically - the gap was pinned here so it was
            // visible rather than silent. It now reads what the paragraph WROTE,
            // which is the same question carve-rs asks of its built inline run
            // (carve-php#1673).
            'a lone image inside a picture' => [
                '<p><picture><img src="g.jpg" alt="G"></picture></p>',
                true,
            ],
        ];
    }

    /**
     * THE THIRD CARVE-OUT, and it is the one that shows why PART 11 section 1c
     * is written over the PROPERTY rather than over the image (carve-php#1678).
     *
     * A block whose whole content is one COMMENT writes the comment's own
     * spelling, which reads back as the block comment - the wrapper is lost
     * exactly as it is for the image, and the writer neither throws nor
     * reports. carve-rs took the same
     * correction in markup-carve/carve-rs#1338 and carve-js in
     * markup-carve/carve-js#1433.
     *
     * WHY THE SWEEP ABOVE COULD NOT HAVE FOUND IT. That sweep imports twenty
     * single-child paragraphs through `HtmlToCarve`, and no HTML builds
     * `paragraph[comment]` - so the shape was never in it. A carve-out list
     * derived from what an import happens to produce is derived from the wrong
     * thing, which is why the clause is normative: the list can then be STATED
     * instead of discovered.
     *
     * THE SHAPE IS LIFTED FROM SOURCE RATHER THAN HAND-ASSEMBLED, so a parser
     * change that stopped producing an inline comment fails here loudly instead
     * of leaving a fixture that silently tests a different tree.
     */
    public function testABlockHoldingOneCommentIsTheThirdCarveOut(): void
    {
        $paragraph = (new CarveConverter())->parse('zz %% c')->getChildren()[0];
        $this->assertInstanceOf(Paragraph::class, $paragraph);
        $lifted = array_values(array_filter(
            $paragraph->getChildren(),
            static fn (object $node): bool => $node instanceof Comment,
        ));
        $this->assertCount(
            1,
            $lifted,
            'no inline comment to lift - the fixture no longer builds the shape it is about',
        );

        $document = new Document();
        $only = new Paragraph();
        $only->appendChild($lifted[0]);
        $document->appendChild($only);

        // Refusing would break an editor's round trip on a tree this engine's
        // other renderers accept, which is the reason the ruling declined it.
        $written = (new CarveRenderer())->render($document);

        $this->assertSame("%% c\n", $written);
        $this->assertSame(
            [Comment::class],
            array_map(
                static fn (object $node): string => $node::class,
                (new CarveConverter())->parse($written)->getChildren(),
            ),
            'the wrapper is lost and the content spells the block',
        );

        // THE TICKET'S OWN MEASUREMENT, which writes two spaces after the marker
        // because the payload it hands the node carries a leading space of its
        // own. Pinned beside the lifted shape so that second space is visibly
        // the content's rather than something the writer added - a reader
        // comparing the two otherwise has to guess which.
        $handBuilt = new Document();
        $wrapper = new Paragraph();
        $wrapper->appendChild(new Comment(' c'));
        $handBuilt->appendChild($wrapper);

        $this->assertSame("%%  c\n", (new CarveRenderer())->render($handBuilt));
        $this->assertSame(
            [Comment::class],
            array_map(
                static fn (object $node): string => $node::class,
                (new CarveConverter())->parse((new CarveRenderer())->render($handBuilt))->getChildren(),
            ),
        );
    }

    /**
     * THE EVERY-INDENT HALF, and it is what shows the clause is not a rule about
     * indentation. `%%` opens a block comment at EVERY column, so unlike the
     * image there is no top-level escape to reach for at all - a writer could
     * not preserve this wrapper by indenting even if the ruling had allowed it.
     *
     * @return array<string, array{string}>
     */
    public static function commentIndentProvider(): array
    {
        return [
            'at column 0' => ['%% c'],
            'indented one space' => [' %% c'],
            'indented three spaces' => ['   %% c'],
            'indented seven spaces' => ['       %% c'],
        ];
    }

    #[DataProvider('commentIndentProvider')]
    public function testNoIndentSpellsAParagraphHoldingOneComment(string $carve): void
    {
        $children = (new CarveConverter())->parse($carve)->getChildren();

        $this->assertCount(1, $children);
        $this->assertInstanceOf(Comment::class, $children[0]);
        $this->assertNotInstanceOf(Paragraph::class, $children[0]);
    }

    /**
     * THE NEAR MISS. A rule read as "a paragraph holding a comment is dropped"
     * passes the case above and fails here: a comment SHARING its run is a
     * paragraph the source can spell, and it comes back as one.
     */
    public function testAParagraphTheCommentSharesKeepsItsWrapper(): void
    {
        $document = (new CarveConverter())->parse('zz %% c');
        $written = (new CarveRenderer())->render($document);

        $this->assertSame(
            [Paragraph::class],
            array_map(
                static fn (object $node): string => $node::class,
                (new CarveConverter())->parse($written)->getChildren(),
            ),
            $written,
        );
    }

    /**
     * AND THE CONTRACT SAYS SO, which is the half that makes the amendment
     * load-bearing rather than decoration. A declaration nothing reads rots the
     * moment the behavior moves, which is how the list came to be missing a
     * shape in the first place.
     *
     * @return array<string, array{string}>
     */
    public static function contractTextProvider(): array
    {
        return [
            'names the comment carve-out' => ['A BLOCK WHOSE WHOLE CONTENT IS ONE COMMENT'],
            'cites the clause that rules it' => ['PART 11 section 1c'],
            'states the rule over the PROPERTY rather than the node type' => [
                'read back as a block opener of that node\'s kind',
            ],
            'bounds the sweep to what the importer can build' => [
                'THE ONLY ONE THE IMPORTER CAN BUILD',
            ],
            'says where the unbuildable shapes come from instead' => [
                'hand-built or ingested tree',
            ],
        ];
    }

    /**
     * The class docblock, with its line furniture taken off.
     *
     * NORMALIZED, BECAUSE A DOCBLOCK WRAPS. A phrase that reads as one sentence
     * in the source is split by the block's own line furniture wherever the line
     * ended, so a literal
     * `assertStringContainsString` against the raw text fails on a sentence that
     * is present - and would pass again the moment an unrelated reflow moved the
     * break. That is a check whose answer depends on the margin rather than on
     * the content.
     */
    private function contract(): string
    {
        $source = (string)file_get_contents(dirname(__DIR__, 3) . '/src/Renderer/CarveRenderer.php');
        $end = strpos($source, 'class CarveRenderer implements');
        $this->assertNotFalse($end, 'the class declaration moved; this test can no longer find the contract');
        $start = strrpos(substr($source, 0, $end), '/**');
        $this->assertNotFalse($start);

        return $this->unwrapped(substr($source, (int)$start, $end - (int)$start));
    }

    /**
     * One line, with the docblock's leading `*` and every run of whitespace gone.
     */
    private function unwrapped(string $text): string
    {
        return (string)preg_replace('/\s+/', ' ', (string)preg_replace('/^\s*\*\s?/m', '', $text));
    }

    #[DataProvider('contractTextProvider')]
    public function testTheContractTextNamesTheThirdCarveOut(string $phrase): void
    {
        $this->assertStringContainsString($phrase, $this->contract());
    }

    /**
     * AND IT NEVER RESTATES THE INVARIANT UNQUALIFIED. The original sentence,
     * standing alone, is the absolute markup-carve/carve#1658 was about; it may
     * appear only where a carve-out is named in the same breath.
     */
    public function testTheInvariantIsNeverRestatedWithoutItsCarveOuts(): void
    {
        $source = $this->unwrapped(
            (string)file_get_contents(dirname(__DIR__, 3) . '/src/Renderer/CarveRenderer.php'),
        );
        $absolute = 're-reads as what it was given';

        $offset = 0;
        $found = 0;
        while (($at = strpos($source, $absolute, $offset)) !== false) {
            $found++;
            $this->assertMatchesRegularExpression(
                '/carve-out|section 1c|EXCEPT/',
                substr($source, $at, 300),
                'the invariant is restated with no carve-out beside it',
            );
            $offset = $at + 1;
        }

        $this->assertGreaterThan(0, $found, 'the contract no longer states its invariant at all');
    }

    #[DataProvider('normalizedProvider')]
    public function testTheLoneImageParagraphIsTheCarveOut(string $html, bool $declared): void
    {
        $written = (new HtmlToCarve())->convert($html);
        $kinds = array_map(
            static fn (object $node): string => $node::class,
            (new CarveConverter())->parse($written)->getChildren(),
        );

        $this->assertSame([Image::class], $kinds, $written);

        // AND THE LOSS IS DECLARED, which is the half that makes the carve-out
        // legitimate rather than an excuse (carve-php#1667).
        $codes = array_map(
            static fn (array $row): string => (string)$row['code'],
            (new HtmlToCarve())->convertWithReport($html)->report()['diagnostics'],
        );
        if ($declared) {
            $this->assertContains('structure-unspellable', $codes);

            return;
        }
        $this->assertNotContains('structure-unspellable', $codes);
    }
}
