<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use MarkupCarve\Carve\Exception\AstDecodeException;
use MarkupCarve\Carve\Renderer\AnsiRenderer;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use MarkupCarve\Carve\Renderer\MarkdownRenderer;
use MarkupCarve\Carve\Renderer\PlainTextRenderer;
use PHPUnit\Framework\TestCase;

class TableSectionAttributesTest extends TestCase
{
    public function testEmptySectionsRoundTripAndRenderSafely(): void
    {
        $wire = [

            'type' => 'document',
            'srcByteLength' => 0,
            'children' => [
                [
                    'type' => 'table',
                    'rows' => [], 'rowGroups' => [
                        'headRows' => 0,
                        'footRows' => 0,
                        'headAttrs' => ['id' => 'head', 'keyValues' => ['onclick' => 'evil()']],
                        'footAttrs' => ['classes' => ['foot']],
                        'bodies' => [['headRows' => 0, 'bodyRows' => 0, 'attrs' => ['id' => 'body']]],
                    ],
                ],
            ],
        ];
        $codec = new AstCodec();
        $doc = $codec->decode($wire);
        $this->assertEquals($wire, $codec->encode($doc));
        $html = (new HtmlRenderer())->render($doc);
        $this->assertStringContainsString('<thead id="head">', $html);
        $this->assertStringContainsString('<tbody id="body">', $html);
        $this->assertStringContainsString('<tfoot class="foot">', $html);
        $this->assertStringNotContainsString('onclick', $html);
    }

    public function testMismatchedPartitionIsRejected(): void
    {
        $this->expectException(AstDecodeException::class);
        (new AstCodec())->decode([

            'type' => 'document',
            'srcByteLength' => 0,
            'children' => [
                [
                    'type' => 'table',
                    'rows' => [],
                    'rowGroups' => ['headRows' => 1, 'footRows' => 0, 'bodies' => []],
                ],
            ],
        ]);
    }

    public function testTextTargetsReportLossesAndEmptyAttributesRemainObjects(): void
    {
        $codec = new AstCodec();
        $doc = $codec->decode([

            'type' => 'document',
            'srcByteLength' => 0,
            'children' => [
                [
                    'type' => 'table',
                    'rows' => [],
                    'rowGroups' => ['headRows' => 0, 'footRows' => 0, 'bodies' => [], 'headAttrs' => ['id' => 'h'], 'footAttrs' => []],
                ],
            ],
        ]);
        $this->assertStringContainsString('"footAttrs":{}', $codec->encodeJson($doc));
        foreach ([new PlainTextRenderer(), new MarkdownRenderer(), new AnsiRenderer()] as $renderer) {
            $converter = new CarveConverter(renderer: $renderer);
            $report = $converter->renderWithReport($doc);
            $this->assertSame(1, $report->totalLosses);
            $this->assertSame('table-section-attributes-dropped', $report->losses[0]['code']);
        }
    }

    public function testHtmlImportKeepsSectionAttributes(): void
    {
        $ast = (new HtmlToCarve())->convertToAst('<table><thead id="h"><tr><th>H</th></tr></thead><tbody id="b"><tr><td>B</td></tr></tbody><tfoot id="f"><tr><td>F</td></tr></tfoot></table>');
        $groups = $ast['children'][0]['rowGroups'];
        $this->assertSame('h', $groups['headAttrs']['id']);
        $this->assertSame('b', $groups['bodies'][0]['attrs']['id']);
        $this->assertSame('f', $groups['footAttrs']['id']);
    }

    public function testSharedSectionRenderingFixtures(): void
    {
        $cases = json_decode((string)file_get_contents(__DIR__ . '/../../spec/tests/fixtures/table-section-attributes.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($cases as $case) {
            $codec = new AstCodec();
            $doc = $codec->decode($case['ast']);
            $this->assertSame($case['html'], rtrim((new HtmlRenderer())->render($doc)));
            $this->assertEquals($case['ast'], $codec->encode($doc));
        }
    }
}
