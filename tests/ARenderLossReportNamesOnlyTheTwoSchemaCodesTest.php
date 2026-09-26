<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Renderer\AnsiRenderer;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use MarkupCarve\Carve\Renderer\MarkdownRenderer;
use MarkupCarve\Carve\Renderer\PlainTextRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `CARVE-P2-024`'s `code` enum is CLOSED at `raw-format-dropped` and
 * `ruby-flattened` (PART 11 §1d, carve#2252 restored by carve#2344), and each
 * names a whole node one selected renderer dropped. A dropped FIELD goes to the
 * conversion-diagnostics channel as `field-unspellable`, which is the only one
 * of the two reports that can name a field.
 *
 * THE BAR IS THE PUBLISHED SCHEMA, NOT THIS FILE'S LIST. The permitted codes are
 * read out of the vendored `render-loss-report.schema.json`, so a third code
 * added to either side fails here without anyone editing an assertion.
 */
class ARenderLossReportNamesOnlyTheTwoSchemaCodesTest extends TestCase
{
    /**
     * A table whose head, foot and single body each carry attributes: three
     * fields that Carve, Markdown, plain and ANSI all lack a spelling for.
     */
    private static function sectionAttributedTable(): Document
    {
        return (new AstCodec())->decode(['type' => 'document', 'srcByteLength' => 0, 'children' => [self::tableWire()]]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function tableWire(): array
    {
        return [
            'type' => 'table',
            'rows' => [],
            'rowGroups' => [
                'headRows' => 0,
                'footRows' => 0,
                'headAttrs' => ['id' => 'head'],
                'footAttrs' => ['classes' => ['foot']],
                'bodies' => [['headRows' => 0, 'bodyRows' => 0, 'attrs' => ['id' => 'body']]],
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private static function schemaCodes(): array
    {
        $schema = json_decode(
            (string)file_get_contents(__DIR__ . '/spec/resources/render-loss-report.schema.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        return $schema['properties']['losses']['items']['properties']['code']['enum'];
    }

    /**
     * @return array<string, array{0: class-string}>
     */
    public static function textTargets(): array
    {
        return [
            'carve' => [CarveRenderer::class],
            'markdown' => [MarkdownRenderer::class],
            'plain' => [PlainTextRenderer::class],
            'ansi' => [AnsiRenderer::class],
        ];
    }

    public function testTheSchemaStillClosesTheEnumAtTwoCodes(): void
    {
        self::assertSame(['raw-format-dropped', 'ruby-flattened'], self::schemaCodes());
    }

    #[DataProvider('textTargets')]
    public function testASectionAttributedTableIsNoRenderLoss(string $renderer): void
    {
        $report = (new CarveConverter(renderer: new $renderer()))->renderWithReport(self::sectionAttributedTable());

        self::assertSame(0, $report->totalLosses, 'a dropped field is not a render loss');
        self::assertSame([], $report->losses);
    }

    /**
     * A document carrying all three: a dropped raw block, a flattened ruby and
     * the three section-attribute fields. The union over the four writers must be
     * exactly the schema's two codes - an empty union would pass a subset
     * assertion while proving nothing.
     */
    public function testEveryEmittedCodeIsOneTheSchemaPermits(): void
    {
        $codec = new AstCodec();
        $document = $codec->decode([
            'type' => 'document',
            'srcByteLength' => 0,
            'children' => [
                ['type' => 'raw_block', 'format' => 'html', 'content' => '<b>x</b>'],
                [
                    'type' => 'paragraph',
                    'children' => [
                        [
                            'type' => 'ruby',
                            'pairs' => [['base' => [['type' => 'text', 'value' => 'a']], 'annotation' => [['type' => 'text', 'value' => 'b']]]],
                        ],
                    ],
                ],
                self::tableWire(),
            ],
        ]);
        $seen = [];
        foreach (self::textTargets() as $target => [$renderer]) {
            $report = (new CarveConverter(renderer: new $renderer()))->renderWithReport($document);
            foreach ($report->losses as $loss) {
                self::assertContains($loss['code'], self::schemaCodes(), $target . ' emitted a code outside the schema enum');
                $seen[$loss['code']] = true;
            }
            self::assertSame(count($report->losses), $report->totalLosses, $target);
        }

        ksort($seen);
        self::assertSame(self::schemaCodes(), array_keys($seen));
    }

    #[DataProvider('textTargets')]
    public function testTheDroppedFieldsReachTheConversionDiagnosticsChannel(string $renderer): void
    {
        $writer = new $renderer();
        $writer->beginConversionDiagnosticCollection();
        $writer->render(self::sectionAttributedTable());
        $report = $writer->finishConversionDiagnosticCollection();

        self::assertSame(3, $report['totalDiagnostics']);
        self::assertSame(
            ['rowGroups.headAttrs', 'rowGroups.footAttrs', 'rowGroups.bodies[0].attrs'],
            array_column($report['diagnostics'], 'field'),
        );
        foreach ($report['diagnostics'] as $diagnostic) {
            self::assertSame('field-unspellable', $diagnostic['code']);
            self::assertSame('table', $diagnostic['node']);
        }
    }
}
