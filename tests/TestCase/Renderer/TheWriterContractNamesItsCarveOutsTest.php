<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
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
            // NOT declared yet, and pinned so the gap is visible rather than
            // silent. The importer's predicate reads the `<p>`'s DIRECT children,
            // so an `<img>` behind a wrapper is not recognized even though the
            // writer normalizes it identically. carve-rs reports this one,
            // because its predicate reads the built inline run instead. Filed as
            // its own ticket; when it is fixed this expectation flips to true.
            'a lone image inside a picture' => [
                '<p><picture><img src="g.jpg" alt="G"></picture></p>',
                false,
            ],
        ];
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
