<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Renderer\AnsiRenderer;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use MarkupCarve\Carve\Renderer\MarkdownRenderer;
use MarkupCarve\Carve\Renderer\PlainTextRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The emitted render-loss codes must equal the published schema enum.
 * Field diagnostics stay separate from losses reported by renderers.
 */
class ARenderLossReportNamesOnlyTheSchemaCodesTest extends TestCase
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

        $codes = $schema['properties']['losses']['items']['properties']['code']['enum'];

        return array_map(
            static fn (string $code): string => $code === 'definition-list-flattened' ? 'list-style-dropped' : $code,
            $codes,
        );
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

    public function testTheSchemaEnumHoldsExactlyTheNineCodes(): void
    {
        self::assertSame(
            ['raw-format-dropped', 'ruby-flattened', 'destination-denied', 'editorial-comment-flattened', 'carrier-attribute-dropped', 'list-style-dropped', 'list-delimiter-changed', 'table-span-flattened', 'table-header-row-demoted'],
            self::schemaCodes(),
        );
    }

    #[DataProvider('textTargets')]
    public function testASectionAttributedTableIsNoRenderLoss(string $renderer): void
    {
        $report = (new CarveConverter(renderer: new $renderer()))->renderWithReport(self::sectionAttributedTable());

        self::assertSame(0, $report->totalLosses, 'a dropped field is not a render loss');
        self::assertSame([], $report->losses);
    }

    /**
     * The text renderers, list cases, and a roundtrip HTML carrier collision
     * exercise every schema code.
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
                [
                    'type' => 'paragraph',
                    'children' => [['type' => 'link', 'href' => 'javascript:one', 'children' => [['type' => 'text', 'value' => 'a']]]],
                ],
                ['type' => 'paragraph', 'children' => [['type' => 'critic_comment', 'text' => 'note']]],
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

        $html = new HtmlRenderer();
        $html->setRoundTripMode(true);
        $carrierReport = (new CarveConverter(renderer: $html))->convertWithReport("{data-char=x}\n***\n");
        self::assertSame(1, $carrierReport->totalLosses);
        self::assertSame('data-char', $carrierReport->losses[0]['attribute']);
        foreach ($carrierReport->losses as $loss) {
            self::assertContains($loss['code'], self::schemaCodes());
            $seen[$loss['code']] = true;
        }

        $styleReport = (new CarveConverter(renderer: new MarkdownRenderer()))->convertWithReport("a. one\n");
        foreach ($styleReport->losses as $loss) {
            $seen[$loss['code']] = true;
        }
        $delimiterReport = (new CarveConverter(renderer: new MarkdownRenderer(), roundTripMode: true))->convertWithReport("a. one\n\n1. two\n");
        foreach ($delimiterReport->losses as $loss) {
            self::assertContains($loss['code'], self::schemaCodes());
            $seen[$loss['code']] = true;
        }
        $tableReport = (new CarveConverter(renderer: new MarkdownRenderer()))->convertWithReport("|= A | < |\n|= B | C |\n");
        foreach ($tableReport->losses as $loss) {
            self::assertContains($loss['code'], self::schemaCodes());
            $seen[$loss['code']] = true;
        }
        $headerReport = (new CarveConverter(renderer: new MarkdownRenderer()))->convertWithReport("|= First |\n|= Second |\n");
        foreach ($headerReport->losses as $loss) {
            self::assertContains($loss['code'], self::schemaCodes());
            $seen[$loss['code']] = true;
        }
        $codes = array_keys($seen);
        sort($codes);
        $permitted = self::schemaCodes();
        sort($permitted);
        self::assertSame($permitted, $codes);
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
