<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\Parser\BlockParser;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use PHPUnit\Framework\TestCase;

class TableBodySourceTest extends TestCase
{
    public function testBodiesRoundTripWithoutMutatingTheInput(): void
    {
        foreach (['body-rows=1,1', 'body-rows=0,1 body-header-rows=0,1 body-header-cols=,0', 'header-rows=1 footer-rows=1 body-rows=""'] as $attrs) {
            $parser = new BlockParser();
            $codec = new AstCodec();
            $wire = $codec->encode($parser->parse('{' . $attrs . "}\n| a | b |\n| c | d |\n"));
            $groups = $wire['children'][0]['rowGroups'];
            unset($wire['children'][0]['attrs']);
            $doc = $codec->decode($wire);
            $before = $codec->encode($doc);
            $renderer = new CarveRenderer();
            $renderer->beginConversionDiagnosticCollection();
            $source = $renderer->render($doc);
            $this->assertSame(0, $renderer->finishConversionDiagnosticCollection()['totalDiagnostics']);
            $reparsed = $parser->parse($source);
            $this->assertSame($groups, $codec->encode($reparsed)['children'][0]['rowGroups'], $source);
            $this->assertSame($before, $codec->encode($doc));
            $this->assertSame((new HtmlRenderer())->render($doc), (new HtmlRenderer())->render($reparsed));
            $this->assertSame($source, $renderer->render($reparsed));
        }
    }

    public function testInvalidListsRemainOrdinaryAttributes(): void
    {
        foreach (['body-rows=1', 'body-rows=1,1 body-header-rows=0', 'body-rows=1,1 body-header-cols=x,0', 'body-rows=1,-1', 'body-header-rows=1', 'body-rows=9007199254740992', 'header-rows=1 body-rows=x', 'header-rows=1 body-header-rows=1'] as $attrs) {
            $doc = (new BlockParser())->parse('{' . $attrs . "}\n| a | b |\n| c | d |\n");
            $this->assertArrayNotHasKey('rowGroups', (new AstCodec())->encode($doc)['children'][0], $attrs);
            $this->assertStringContainsString('body-', (new HtmlRenderer())->render($doc));
        }
    }

    public function testConflictIsReportedOnceAndWireKeyOrderIsIrrelevant(): void
    {
        $codec = new AstCodec();
        $wire = $codec->encode((new BlockParser())->parse("{body-rows=1,1}\n| a |\n| b |\n"));
        $wire['children'][0]['rowGroups']['bodies'][0] = ['bodyRows' => 1, 'headRows' => 0];
        unset($wire['children'][0]['attrs']);
        $renderer = new CarveRenderer();
        $renderer->beginConversionDiagnosticCollection();
        $renderer->render($codec->decode($wire));
        $this->assertSame(0, $renderer->finishConversionDiagnosticCollection()['totalDiagnostics']);
        $wire['children'][0]['attrs'] = ['keyValues' => ['body-rows' => '2']];
        $renderer->beginConversionDiagnosticCollection();
        $renderer->render($codec->decode($wire));
        $report = $renderer->finishConversionDiagnosticCollection();
        $this->assertSame(1, $report['totalDiagnostics']);
        $this->assertSame('rowGroups', $report['diagnostics'][0]['field']);
    }

    public function testEmptyBodiesAndPromotedRowHeadersRender(): void
    {
        $parser = new BlockParser();
        $html = (new HtmlRenderer())->render($parser->parse("{body-rows=0,1 body-header-rows=0,1 body-header-cols=,0}\n| H | G |\n| a | b |\n"));
        $this->assertSame(2, substr_count($html, '<tbody>'));
        $html = (new HtmlRenderer())->render($parser->parse("{body-rows=2,1 body-header-rows=0,1 body-header-cols=1,0}\n| a | b |\n| ^ | c |\n| ^ | D |\n| e | f |\n"));
        $this->assertStringContainsString('<th scope="row" rowspan="3">a</th>', $html);
        $this->assertStringContainsString('<th scope="col">D</th>', $html);
    }

    public function testHeaderOnlyImplicitAndExplicitEmptyBodiesStayDistinct(): void
    {
        $parser = new BlockParser();
        $codec = new AstCodec();
        foreach (['header-rows=2', 'header-rows=2 body-rows=0'] as $attrs) {
            $wire = $codec->encode($parser->parse('{' . $attrs . "}\n| a | b |\n| c | d |\n"));
            $this->assertCount(str_contains($attrs, 'body-rows') ? 1 : 0, $wire['children'][0]['rowGroups']['bodies']);
            unset($wire['children'][0]['attrs']);
            $doc = $codec->decode($wire);
            $source = (new CarveRenderer())->render($doc);
            $this->assertSame((new HtmlRenderer())->render($doc), (new HtmlRenderer())->render($parser->parse($source)));
        }
    }
}
