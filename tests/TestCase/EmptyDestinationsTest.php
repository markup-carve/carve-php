<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use MarkupCarve\Carve\Lint\ReferenceLinter;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Node\Inline\Image;
use MarkupCarve\Carve\Node\Inline\Link;
use MarkupCarve\Carve\Node\Inline\Text;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use MarkupCarve\Carve\Renderer\MarkdownRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EmptyDestinationsTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string}>
     */
    public static function inlineShapes(): array
    {
        return [
            'link' => ['[t]()', '<p><a href="">t</a></p>', '[t]()'],
            'empty label' => ['[]()', '<p><a href=""></a></p>', '[]()'],
            'space padding' => ['[t]( )', '<p><a href="">t</a></p>', '[t]()'],
            'title' => ['[t]( "T")', '<p><a href="" title="T">t</a></p>', '[t]( "T")'],
            'image' => ['![a]()', '<img src="" alt="a">', '![a]()'],
            'image title' => ['![a]( "T")', '<img src="" alt="a" title="T">', '![a]( "T")'],
        ];
    }

    #[DataProvider('inlineShapes')]
    public function testNativeShapes(string $source, string $html, string $canonical): void
    {
        $converter = new CarveConverter();
        self::assertSame($html, trim($converter->convert($source)));
        $writer = new CarveRenderer();
        self::assertSame($canonical, trim($writer->render($converter->parse($source))));
        self::assertSame($canonical, trim($writer->render($converter->parse($canonical))));
    }

    public function testResolvedEmptyReferencesSurviveAstJson(): void
    {
        $converter = new CarveConverter();
        $codec = new AstCodec();
        foreach (['[t][r]\n\n[r]:', '![a][r]\n\n[r]:'] as $source) {
            $source = str_replace('\\n', "\n", $source);
            $document = $converter->parse($source);
            $decoded = $codec->decodeJson($codec->encodeJson($document));
            self::assertSame($converter->render($document), $converter->render($decoded));
        }
        self::assertSame('<p>[t][r]</p>', trim($converter->convert('[t][r]')));
    }

    public function testImportsAndMarkdownWriter(): void
    {
        self::assertSame('[t]()' . "\n", (new HtmlToCarve())->convert('<a href="">t</a>'));
        self::assertSame('![a]()' . "\n", (new HtmlToCarve())->convert('<img src="" alt="a">'));
        self::assertSame('[t]()', (new MarkdownToCarve())->convert('[t](<>)'));
        self::assertSame("[t][r]\n\n[r]:", (new MarkdownToCarve())->convert("[t][r]\n\n[r]: <>"));
        $converter = new CarveConverter();
        $writer = new MarkdownRenderer();
        self::assertSame('[t](<> "T")', trim($writer->render($converter->parse('[t]( "T")'))));
        self::assertSame('![a](<> "T")', trim($writer->render($converter->parse('![a]( "T")'))));
    }

    public function testHtmlImportDistinguishesEmptyFromMissingDestinations(): void
    {
        $importer = new HtmlToCarve();
        self::assertSame('t', trim($importer->convert('<a>t</a>')));
        self::assertSame('a', trim($importer->convert('<img alt="a">')));
        self::assertSame('t', trim($importer->convert('<a href=" &#9; ">t</a>')));
        self::assertSame('a', trim($importer->convert('<img src=" &#10; " alt="a">')));
        self::assertSame('[t]()', trim($importer->convert('<a href="">t</a>')));
        self::assertSame('![a]()', trim($importer->convert('<img src="" alt="a">')));
    }

    public function testHtmlImportIgnoresFootnoteCarriersOnEmptyLinks(): void
    {
        $importer = new HtmlToCarve(importMode: 'roundtrip');
        foreach (['data-carve-footnote-label="1"', 'data-carve-inline-footnote-html="&lt;p&gt;n&lt;/p&gt;"'] as $carrier) {
            self::assertSame('[t]()', trim($importer->convert('<a href="" ' . $carrier . '>t</a>')));
        }
    }

    public function testHardenedEmptyLinkDoesNotRecreateDeniedUrl(): void
    {
        $html = (new CarveConverter())->convert('[t](javascript:alert(1))');
        self::assertStringContainsString('href=""', $html);
        self::assertSame('[t]()', trim((new HtmlToCarve())->convert($html)));
    }

    public function testBareDefinitionWarnsWithoutMarkingItsReferenceUnresolved(): void
    {
        $warnings = (new ReferenceLinter())->lint("[t][r]\n\n[r]:");
        self::assertContains('empty-reference-definition', array_map(static fn ($warning): string => $warning->rule, $warnings));
        self::assertNotContains('unresolved-reference-link', array_map(static fn ($warning): string => $warning->rule, $warnings));
    }

    public function testResolvedEmptyReferenceSurvivesAClone(): void
    {
        $converter = new CarveConverter();
        $document = $converter->parse("[t][r]\n\n[r]:");
        self::assertSame('<p><a href="">t</a></p>', trim($converter->render(clone $document)));
    }

    public function testEmptyTailStopsAtANewlineAndAcceptsTitlePadding(): void
    {
        $converter = new CarveConverter();
        self::assertSame("<p>[t](\n)</p>", trim($converter->convert("[t](\n)")));
        self::assertSame('<p><a href="" title="T">t</a></p>', trim($converter->convert('[t]( "T" )')));
    }

    public function testCaretLedBareDefinitionsRemainLiteral(): void
    {
        $converter = new CarveConverter();
        self::assertSame('<p>[^]:</p>', trim($converter->convert('[^]:')));
        self::assertSame('<p><a href="/u">t</a></p>', trim($converter->convert("[^]: /u\n\n[t][^]")));
    }

    public function testMultilineTitleOnEmptyDestinationIsReported(): void
    {
        $document = new Document();
        $paragraph = new Paragraph();
        $link = new Link('', "x\ny");
        $link->appendChild(new Text('a'));
        $paragraph->appendChild($link);
        $paragraph->appendChild(new Text(' '));
        $paragraph->appendChild(new Image('', 'b', "x\ny"));
        $document->appendChild($paragraph);
        $writer = new CarveRenderer();
        $writer->beginConversionDiagnosticCollection();
        self::assertSame('[a]() ![b]()' . "\n", $writer->render($document));
        $report = $writer->finishConversionDiagnosticCollection();
        self::assertSame(['field-unspellable', 'field-unspellable'], array_column($report['diagnostics'], 'code'));
    }

    public function testMarkdownImportReportsDroppedMultilineTitle(): void
    {
        foreach (
            [
                "[a](<> \"x\ny\")" => '[a]()',
                "[a](<> (x\ny))" => '[a]()',
                "![a](<> (x\ny))" => '![a]()',
                "[a](<>  \"x\ny\")" => '[a]()',
                "[a](<>\n\"x\ny\")" => '[a]()',
                "[a [b] c](<> \"x\ny\")" => '[a [b] c]()',
                "[a\nb](<> \"x\ny\")" => "[a\nb]()",
            ] as $source => $expected
        ) {
            $result = (new MarkdownToCarve())->convertWithFidelityReport($source);
            self::assertSame($expected, trim($result->value), $source);
            self::assertContains('structure-unspellable', array_map(static fn ($diagnostic): string => $diagnostic->code, $result->diagnostics), $source);
        }
    }

    public function testMarkdownImportKeepsAReferenceWithAMultilineTitle(): void
    {
        $result = (new MarkdownToCarve())->convertWithFidelityReport("[a][r]\n\n[r]: <> \"x\ny\"");
        self::assertSame("[a][r]\n\n[r]:", trim($result->value));
        self::assertContains('structure-unspellable', array_map(static fn ($diagnostic): string => $diagnostic->code, $result->diagnostics));
    }
}
