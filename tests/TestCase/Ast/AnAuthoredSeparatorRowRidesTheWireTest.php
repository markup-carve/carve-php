<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\Ast\AstEnvelope;
use MarkupCarve\Carve\Ast\AstSchema;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use MarkupCarve\Carve\Exception\AstEnvelopeVersionException;
use MarkupCarve\Carve\Node\Block\Table;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Parser\BlockParser;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\TestCase;

/**
 * `table.separatorWidths` is a PUBLISHED author-choice field (PART 12 §3,
 * PART 11 §6h `CARVE-P11-066`, markup-carve/carve#2894).
 *
 * Its PRESENCE is the larger half of what it records: `|= a |= b |` and a plain
 * row above `|---|---|` parse to the same rows and the same alignments, so
 * without the field a writer cannot tell which of the two the author wrote. The
 * entries are the second half, the dash count per separator cell with the
 * alignment colons excluded.
 */
class AnAuthoredSeparatorRowRidesTheWireTest extends TestCase
{
    private AstCodec $codec;

    protected function setUp(): void
    {
        $this->codec = new AstCodec();
    }

    private function parse(string $source): Document
    {
        return (new BlockParser())->parse($source);
    }

    public function testTheAuthoredWidthsArePublished(): void
    {
        $ast = $this->codec->encode($this->parse("| a | b |\n|:-----|---:|\n| 1 | 2 |\n"));

        $this->assertSame([5, 3], $ast['children'][0]['separatorWidths']);
    }

    /**
     * The colons are NOT counted. `:-----` is five dashes and a marker, not six
     * cells' worth of width, and a reader that counted the whole run would
     * write one dash too many back.
     */
    public function testAlignmentColonsAreExcludedFromTheCount(): void
    {
        $ast = $this->codec->encode($this->parse("| a | b | c |\n|:--:|:---|----:|\n| 1 | 2 | 3 |\n"));

        $this->assertSame([2, 3, 4], $ast['children'][0]['separatorWidths']);
    }

    public function testTheFieldIsAbsentWhenTheAuthorWroteNoSeparatorRow(): void
    {
        $ast = $this->codec->encode($this->parse("|= a |= b |\n| 1 | 2 |\n"));

        $this->assertArrayNotHasKey('separatorWidths', $ast['children'][0]);
    }

    public function testTheFieldSurvivesADecode(): void
    {
        $source = "| a | b |\n|:-----|---:|\n| 1 | 2 |\n";
        $decoded = $this->codec->decode($this->codec->encode($this->parse($source)));
        $table = $decoded->getChildren()[0];

        $this->assertInstanceOf(Table::class, $table);
        $this->assertSame([5, 3], $table->getSeparatorWidths());
    }

    public function testTheSchemaNamesTheFieldAsBoundedIntegers(): void
    {
        $table = AstSchema::schema()['$defs']['table']['properties']['separatorWidths'];

        $this->assertSame('array', $table['type']);
        $this->assertSame('integer', $table['items']['type']);
        $this->assertSame(1, $table['items']['minimum']);
    }

    /**
     * A zero is refused rather than silently floored: the field says what the
     * author SPELLED, and a separator cell with no dash is not a separator cell.
     */
    public function testAWidthBelowOneIsNotAValidPayload(): void
    {
        $payload = [
            'type' => 'document',
            'children' => [
                [
                    'type' => 'table',
                    'separatorWidths' => [0],
                    'rows' => [['type' => 'table_row', 'cells' => [['type' => 'table_cell', 'children' => []]]]],
                ],
            ],
        ];

        $this->assertNotNull(AstSchema::firstViolation($payload));
    }

    // =========================================================================
    // The writer reproduces both halves
    // =========================================================================

    private function write(string $source): string
    {
        return (new CarveRenderer())->render($this->codec->decode($this->codec->encode($this->parse($source))));
    }

    public function testTheWriterReproducesTheAuthoredSeparatorRow(): void
    {
        $this->assertSame("| a | b |\n|:-----|---:|\n| 1 | 2 |\n", $this->write("| a | b |\n|:-----|---:|\n| 1 | 2 |\n"));
    }

    public function testTheWriterKeepsASingleDashCell(): void
    {
        $this->assertSame("| a | b |\n|-|:-:|\n| 1 | 2 |\n", $this->write("| a | b |\n|-|:-:|\n| 1 | 2 |\n"));
    }

    /**
     * Without the field the header is spelled with `|=` cells, which is the
     * other authored form for the same rows.
     */
    public function testTheWriterSpellsHeaderCellsWithoutTheField(): void
    {
        $this->assertSame("|= a |= b |\n| 1 | 2 |\n", $this->write("|= a |= b |\n| 1 | 2 |\n"));
    }

    /**
     * A tree built by hand can carry a count that disagrees with the row. The
     * separator is spelled for the cells that exist and no further, and a width
     * below 1 writes one dash rather than an empty cell no parser reads back.
     */
    public function testAnEntryCountDisagreeingWithTheRowIsSpelledForTheCellsThatExist(): void
    {
        $source = "| a | b |\n|---|---|\n| 1 | 2 |\n";
        $document = $this->parse($source);
        $table = $document->getChildren()[0];
        $this->assertInstanceOf(Table::class, $table);
        $table->setSeparatorWidths([4, 2, 9, 7]);

        $this->assertSame("| a | b |\n|----|--|\n| 1 | 2 |\n", (new CarveRenderer())->render($document));
    }

    /**
     * The HTML importer sets the PUBLISHED field. It used to stash the carrier
     * in a private attribute only this engine's own writer could read, which
     * made the widths unreachable for every other consumer of the imported
     * tree.
     */
    public function testTheHtmlImporterSetsThePublishedField(): void
    {
        $html = '<table data-carve-col-widths="5,3">'
            . '<thead><tr><th style="text-align: left;">a</th><th style="text-align: right;">b</th></tr></thead>'
            . '<tbody><tr><td>1</td><td>2</td></tr></tbody></table>';
        $ast = (new HtmlToCarve(trustedRoundTrip: true))->convertToAst($html);

        $this->assertSame([5, 3], $ast['children'][0]['separatorWidths']);
    }

    // =========================================================================
    // The contract version the field arrived with
    // =========================================================================

    public function testTheEnvelopeAnnouncesContractVersionOneOne(): void
    {
        $envelope = (new AstEnvelope($this->codec))->encode($this->parse("| a |\n|---|\n| 1 |\n"));

        $this->assertSame('1.1', $envelope['astVersion']);
    }

    /**
     * A HIGHER MINOR IS REFUSED, not accepted-and-ignored. The node schema is
     * closed, so the field a minor adds is one this build's validator rejects
     * outright: accepting the envelope and then failing on the tree reported the
     * wrong problem. The old rule could not be reached by any payload.
     */
    public function testAHigherMinorIsRefusedWithBothVersionsNamed(): void
    {
        $envelope = (new AstEnvelope($this->codec))->encode($this->parse("| a |\n|---|\n| 1 |\n"));
        $envelope['astVersion'] = '1.2';

        try {
            (new AstEnvelope($this->codec))->decode($envelope);
        } catch (AstEnvelopeVersionException $e) {
            $this->assertSame('1.2', $e->found);
            $this->assertSame('1.1', $e->implemented);
            $this->assertStringContainsString('1.2', $e->getMessage());
            $this->assertStringContainsString('1.1', $e->getMessage());

            return;
        }

        $this->fail('a higher minor was accepted');
    }

    public function testItsOwnVersionAndALowerOneAreAccepted(): void
    {
        foreach (['1.1', '1.0'] as $version) {
            $envelope = (new AstEnvelope($this->codec))->encode($this->parse("| a |\n|---|\n| 1 |\n"));
            $envelope['astVersion'] = $version;

            $this->assertCount(1, (new AstEnvelope($this->codec))->decode($envelope)->getChildren(), $version);
        }
    }
}
