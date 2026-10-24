<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\Table;
use MarkupCarve\Carve\Node\Block\TableCell;
use MarkupCarve\Carve\Node\Block\TableRow;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TableSpanPaddingRulingTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function spanForms(): iterable
    {
        foreach (['<', '^'] as $glyph) {
            foreach (['', ' '] as $before) {
                foreach (['', ' '] as $after) {
                    yield json_encode([$glyph, $before, $after]) => ["| a |{$before}{$glyph}{$after}|\n"];
                }
            }
        }
    }

    #[DataProvider('spanForms')]
    public function testLoneGlyphIsASecondCellSpanWithAnyPadding(string $source): void
    {
        $document = (new CarveConverter())->parse($source);
        $cell = $document->getChildren()[0]->getChildren()[0]->getChildren()[1];
        self::assertContains($cell->getSpanMarker(), ['<', '^']);
    }

    public function testLiteralGlyphsAreEscapedOnWrite(): void
    {
        self::assertSame("| \\< | \\^ |\n", CarveConverter::toCarve("| \\< | \\^ |\n"));
    }

    public function testEmptyBodyAlignmentIsDropped(): void
    {
        [$document] = self::tableWithEmptyCell(false, false);
        self::assertSame("| |\n", (new CarveRenderer())->render($document));
    }

    public function testEmptyHeaderDefaultUsesExistingDelimiter(): void
    {
        [$document] = self::tableWithEmptyCell(true, true);
        $writer = new CarveRenderer();
        $writer->beginConversionDiagnosticCollection();
        self::assertSame("| |\n|:---|\n", $writer->render($document));
        self::assertSame([], $writer->finishConversionDiagnosticCollection()['diagnostics']);
    }

    public function testEmptyHeaderDefaultWithoutDelimiterReportsLoss(): void
    {
        [$document] = self::tableWithEmptyCell(true, false);
        $writer = new CarveRenderer();
        $writer->beginConversionDiagnosticCollection();
        self::assertSame("|= |\n", $writer->render($document));
        self::assertSame('field-unspellable', $writer->finishConversionDiagnosticCollection()['diagnostics'][0]['code']);
    }

    /**
     * @return array{\MarkupCarve\Carve\Node\Document}
     */
    private static function tableWithEmptyCell(bool $header, bool $delimiter): array
    {
        $document = new Document();
        $table = new Table();
        $row = new TableRow($header);
        $row->appendChild(new TableCell($header, TableCell::ALIGN_LEFT));
        $table->appendChild($row);
        if ($delimiter) {
            $table->setSeparatorWidths([3]);
        }
        $document->appendChild($table);

        return [$document];
    }
}
