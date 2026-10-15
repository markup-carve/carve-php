<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\Ast\AstEnvelope;
use MarkupCarve\Carve\Ast\AstSchema;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use MarkupCarve\Carve\Exception\AstDecodeException;
use MarkupCarve\Carve\Exception\AstEnvelopeVersionException;
use MarkupCarve\Carve\Node\Block\Table;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Parser\BlockParser;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
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
     * THE FIELD DRIVES THE SEPARATOR CELL COUNT, not the header row.
     *
     * A ragged table is authored with a separator row holding FEWER cells than
     * its header, so padding the row out to the header's width wrote a cell the
     * author never spelled and re-parsed to a different `separatorWidths`;
     * `parse(fmt(x)) == parse(x)` failed on three corpus documents. A count
     * ABOVE the header's is reproduced for the same reason: the entries are
     * what the author wrote, and dropping the surplus loses it. The drawn
     * shapes are in the two test bodies below.
     *
     * A cell past the header takes no alignment colon, there being no cell to
     * take one from.
     */
    public function testTheFieldDrivesTheSeparatorCellCount(): void
    {
        $document = $this->parse("| a | b |\n|---|---|\n| 1 | 2 |\n");
        $table = $document->getChildren()[0];
        $this->assertInstanceOf(Table::class, $table);
        $table->setSeparatorWidths([4, 2, 9]);

        $written = (new CarveRenderer())->render($document);
        $this->assertSame("| a | b |\n|----|--|---------|\n| 1 | 2 |\n", $written);
    }

    /**
     * AN ENTRY PAST THE HEADER'S LAST CELL TAKES NO ALIGNMENT, having no cell to
     * take one from: `[3, 3]` over a one-cell CENTERED header writes
     * `|:---:|---|`, the colon on the cell that has one and nothing on the cell
     * that does not.
     *
     * Pinned as its own case because it is the one shape the three engines would
     * not derive the same way, so agreement here has to be measured rather than
     * assumed (`CARVE-P11-066`). The written row re-parses to `[3, 3]` with the
     * header still centered.
     */
    public function testAnEntryPastTheHeaderTakesNoAlignment(): void
    {
        $document = $this->parse("| a |\n|:-:|\n| 1 |\n");
        $table = $document->getChildren()[0];
        $this->assertInstanceOf(Table::class, $table);
        $table->setSeparatorWidths([3, 3]);

        $written = (new CarveRenderer())->render($document);
        $this->assertSame("| a |\n|:---:|---|\n| 1 |\n", $written);

        $reparsed = $this->codec->encode($this->parse($written))['children'][0];
        $this->assertSame([3, 3], $reparsed['separatorWidths']);
        $this->assertSame('center', $reparsed['rows'][0]['cells'][0]['align']);
    }

    /**
     * NEITHER PAD NOR TRUNCATE. A count ABOVE the header's is written out in
     * full, which is the mirror of the padding defect: carve-js counted over the
     * header and truncated, and in both engines the shapes that passed were the
     * ones where the separator row is shorter than its header, where padding and
     * truncating agree. That was the only side either engine tested.
     */
    public function testAnEntryCountAboveTheHeaderIsNotTruncated(): void
    {
        $document = $this->parse("| a |\n|---|\n| 1 |\n");
        $table = $document->getChildren()[0];
        $this->assertInstanceOf(Table::class, $table);
        $table->setSeparatorWidths([2, 4, 6]);

        $written = (new CarveRenderer())->render($document);
        $this->assertSame("| a |\n|--|----|------|\n| 1 |\n", $written);
        $this->assertSame(
            [2, 4, 6],
            $this->codec->encode($this->parse($written))['children'][0]['separatorWidths'],
        );
    }

    /**
     * A SHORTER separator row than the header is kept short, which is what the
     * ragged-table corpus documents author and the case the padding broke. The
     * widths are compared rather than only the bytes, since re-parsing to the
     * same field is the property the padding violated.
     */
    public function testAShorterSeparatorRowThanTheHeaderIsKeptShort(): void
    {
        $source = "| |x |\n|---|\n| y |\n";
        $written = (new CarveRenderer())->render($this->parse($source));

        $this->assertSame("| | x |\n|---|\n| y |\n", $written);
        $this->assertSame(
            $this->codec->encode($this->parse($written))['children'][0]['separatorWidths'],
            $this->codec->encode($this->parse($source))['children'][0]['separatorWidths'],
        );
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
        $ast = (new HtmlToCarve())->convertToAst($html);

        $this->assertSame([5, 3], $ast['children'][0]['separatorWidths']);
    }

    /**
     * `minItems: 1`: AN EMPTY ARRAY IS NOT A RECORDED SEPARATOR ROW (PART 12 §3).
     * The cell count is the entry count, so zero entries ask for a row with no
     * cells, which the delimiter production cannot spell.
     *
     * Found by a codex review reading the schema rather than by any test: 99
     * cases passed over a field that admitted `[]`. The control matters more
     * than the assertion here - an earlier version of this probe failed on a
     * missing `srcByteLength` and proved nothing about `minItems`, so the
     * payload is a REAL encoded document with exactly one field changed.
     */
    public function testAnEmptyWidthListIsRefused(): void
    {
        $valid = $this->codec->encode($this->parse("| a |\n|---|\n| 1 |\n"));
        $this->assertSame([3], $valid['children'][0]['separatorWidths']);
        $this->assertNull(AstSchema::firstViolation($valid), 'the control payload must be valid');

        $empty = $valid;
        $empty['children'][0]['separatorWidths'] = [];

        $violation = AstSchema::firstViolation($empty);
        $this->assertNotNull($violation);
        $this->assertStringContainsString('separatorWidths', (string)$violation);

        $this->expectException(AstDecodeException::class);
        $this->codec->decode($empty);
    }

    /**
     * The parser cannot produce an empty list, so the refusal above guards only
     * a hand-built or foreign tree. Asserted rather than assumed, across the
     * shapes that look like they might: an empty separator cell, a bare pipe
     * pair, a colon with no dash.
     */
    public function testTheParserNeverProducesAnEmptyWidthList(): void
    {
        $shapes = [
            "| a |\n||\n| 1 |\n",
            "| a |\n|\n| 1 |\n",
            "| a |\n| |\n| 1 |\n",
            "| a |\n|  |\n| 1 |\n",
            "| a |\n|:|\n| 1 |\n",
            "| a |\n|::|\n| 1 |\n",
            "| a | b |\n||||\n| 1 | 2 |\n",
            "||\n||\n",
        ];
        foreach ($shapes as $source) {
            foreach ($this->parse($source)->getChildren() as $node) {
                if ($node instanceof Table) {
                    $this->assertNotSame([], $node->getSeparatorWidths(), $source);
                }
            }
        }
    }

    /**
     * NEITHER WRITER INVENTS A ROW FROM AN EMPTY LIST. Writing one from the
     * header's width would be the padding `CARVE-P11-066` forbids wearing a
     * presence claim nobody wrote, and the HTML side would spell
     * `data-carve-col-widths=""`, a carrier this engine's own importer refuses.
     */
    public function testNeitherWriterSpellsARowForAnEmptyWidthList(): void
    {
        $document = $this->parse("| a | b |\n|---|---|\n| 1 | 2 |\n");
        $table = $document->getChildren()[0];
        $this->assertInstanceOf(Table::class, $table);
        $table->setSeparatorWidths([]);

        $this->assertSame("|= a |= b |\n| 1 | 2 |\n", (new CarveRenderer())->render($document));

        $renderer = new HtmlRenderer();
        $renderer->setRoundTripMode(true);
        $this->assertStringNotContainsString('data-carve-col-widths', $renderer->render($document));
    }

    /**
     * PART 10 §13a: a reader REFUSES THE WHOLE CARRIER, writing no field, where
     * any entry is not a run of decimal digits, is not positive, or exceeds
     * 1000. Not a clamp: clamping to the bound keeps a separator row whose
     * widths nobody wrote, and absence of the field is a real spelling of the
     * same table.
     */
    #[DataProvider('refusedCarrierProvider')]
    public function testARefusedCarrierLeavesTheWholeFieldUnwritten(string $carrier): void
    {
        $html = '<table data-carve-col-widths="' . $carrier . '">'
            . '<thead><tr><th>a</th><th>b</th></tr></thead>'
            . '<tbody><tr><td>1</td><td>2</td></tr></tbody></table>';
        $ast = (new HtmlToCarve())->convertToAst($html);

        $this->assertArrayNotHasKey('separatorWidths', $ast['children'][0]);
        foreach (['safe', 'roundtrip'] as $mode) {
            $report = (new HtmlToCarve(importMode: $mode))->convertWithReport($html);
            $this->assertStringNotContainsString('data-carve-col-widths', $report->value, $mode);
            $this->assertContains('attribute-dropped', array_map(static fn ($row): string => $row->code, $report->diagnostics), $mode);
        }
    }

    public function testAValidWidthCarrierNeedsNoDroppedValueDiagnostic(): void
    {
        $html = '<table data-carve-col-widths="1,1000"><thead><tr><th>a</th><th>b</th></tr></thead></table>';
        foreach (['safe', 'roundtrip'] as $mode) {
            $report = (new HtmlToCarve(importMode: $mode))->convertWithReport($html);
            $this->assertNotContains('attribute-dropped', array_map(static fn ($row): string => $row->code, $report->diagnostics), $mode);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedCarrierProvider(): iterable
    {
        yield 'an empty carrier' => [''];
        yield 'whitespace only' => [' '];
        yield 'padded first entry' => [' 3,4'];
        yield 'padded later entry' => ['3, 4'];
        yield 'a trailing physical line break' => ["3\n"];
        yield 'a leading physical line break' => ["\n3"];
        yield 'a bare comma' => [','];
        yield 'not a digit run' => ['5,x'];
        yield 'no digits at all' => ['abc'];
        yield 'an empty entry' => ['5,,3'];
        yield 'a signed entry' => ['5,-3'];
        yield 'a fractional entry' => ['5,3.5'];
        yield 'zero is not positive' => ['0'];
        yield 'zero beside a readable entry' => ['5,0'];
        yield 'one past the bound' => ['1001'];
        yield 'one past the bound beside a readable entry' => ['5,1001'];
        yield 'far past the bound' => ['200000000'];
    }

    /**
     * THE BOUND IS THE CARRIER'S, NOT THE FIELD'S. A parse records what the
     * author typed and nothing amplifies there, so the schema caps no width and
     * a 1500-dash separator row rides the wire intact.
     */
    public function testTheFieldItselfHasNoUpperBound(): void
    {
        $source = '| a |' . "\n" . '|' . str_repeat('-', 1500) . '|' . "\n" . '| 1 |' . "\n";
        $ast = $this->codec->encode($this->parse($source));

        $this->assertSame([1500], $ast['children'][0]['separatorWidths']);
        $this->assertNull(AstSchema::firstViolation($ast));
    }

    /**
     * The writer half of §13a: NO CARRIER where a conforming reader would
     * refuse it, because a carrier the reader throws away is a round-trip
     * promise the writer cannot keep. The table still renders, and imports back
     * as the `|=` form.
     */
    public function testNoCarrierIsWrittenForAWidthPastTheBound(): void
    {
        $document = $this->parse("| a |\n|---|\n| 1 |\n");
        $table = $document->getChildren()[0];
        $this->assertInstanceOf(Table::class, $table);
        $table->setSeparatorWidths([1001]);

        $renderer = new HtmlRenderer();
        $renderer->setRoundTripMode(true);
        $html = $renderer->render($document);

        $this->assertStringNotContainsString('data-carve-col-widths', $html);
        $this->assertStringContainsString('<table', $html);
    }

    public function testTheCarrierIsStillWrittenAtTheBound(): void
    {
        $document = $this->parse("| a |\n|---|\n| 1 |\n");
        $table = $document->getChildren()[0];
        $this->assertInstanceOf(Table::class, $table);
        $table->setSeparatorWidths([1000]);

        $renderer = new HtmlRenderer();
        $renderer->setRoundTripMode(true);

        $this->assertStringContainsString('data-carve-col-widths="1000"', $renderer->render($document));
    }

    // =========================================================================
    // The contract version the field arrived with
    // =========================================================================

    public function testTheEnvelopeAnnouncesContractVersionTwoZero(): void
    {
        $envelope = (new AstEnvelope($this->codec))->encode($this->parse("| a |\n|---|\n| 1 |\n"));

        $this->assertSame('2.0', $envelope['astVersion']);
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
        $envelope['astVersion'] = '2.1';

        try {
            (new AstEnvelope($this->codec))->decode($envelope);
        } catch (AstEnvelopeVersionException $e) {
            $this->assertSame('2.1', $e->found);
            $this->assertSame('2.0', $e->implemented);
            $this->assertStringContainsString('2.1', $e->getMessage());
            $this->assertStringContainsString('2.0', $e->getMessage());

            return;
        }

        $this->fail('a higher minor was accepted');
    }

    public function testItsOwnVersionAndALowerOneAreAccepted(): void
    {
        foreach (['2.0', '1.1', '1.0'] as $version) {
            $envelope = (new AstEnvelope($this->codec))->encode($this->parse("| a |\n|---|\n| 1 |\n"));
            $envelope['astVersion'] = $version;

            $this->assertCount(1, (new AstEnvelope($this->codec))->decode($envelope)->getChildren(), $version);
        }
    }
}
