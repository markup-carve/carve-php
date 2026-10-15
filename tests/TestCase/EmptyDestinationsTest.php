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
            'wrapped title' => ["[t]( \"a\nb\")", "<p><a href=\"\" title=\"a\nb\">t</a></p>", "[t]( \"a\nb\")"],
            'wrapped single-quote title' => ["[t]( 'a\nb')", "<p><a href=\"\" title=\"a\nb\">t</a></p>", "[t]( \"a\nb\")"],
            'wrapped image title' => ["![a]( \"x\ny\")", "<img src=\"\" alt=\"a\" title=\"x\ny\">", "![a]( \"x\ny\")"],
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

    /**
     * @return array<string, array{string, string}>
     */
    public static function resolvedEmptyReferences(): array
    {
        return [
            'lone image' => ["![a][r]\n\n[r]:", '<img src="" alt="a">'],
            'collapsed lone image' => ["![a][]\n\n[a]:", '<img src="" alt="a">'],
            'lone image in a list item' => ["- ![a][r]\n\n[r]:", "<ul>\n  <li>\n    <img src=\"\" alt=\"a\">\n  </li>\n</ul>"],
            'lone image in a quote' => ["> ![a][r]\n\n[r]:", "<blockquote>\n  <img src=\"\" alt=\"a\">\n</blockquote>"],
            'nested in a link' => ["[x [t][r] y](/u)\n\n[r]:", '<p><a href="/u">x t y</a></p>'],
            'cross-reference label' => ["{#h}\n# A [t][r]\n\nsee </#h>\n\n[r]:", "<section id=\"h\">\n  <h1>A <a href=\"\">t</a></h1>\n  <p>see <a href=\"#h\">A t</a></p>\n</section>"],
        ];
    }

    #[DataProvider('resolvedEmptyReferences')]
    public function testResolvedEmptyReferenceIsNotUnresolved(string $source, string $html): void
    {
        $converter = new CarveConverter();
        self::assertSame($html, trim($converter->convert($source)));
        $codec = new AstCodec();
        $decoded = $codec->decodeJson($codec->encodeJson($converter->parse($source)));
        self::assertSame($html, trim($converter->render($decoded)));
    }

    public function testMultilineTitleOnEmptyDestinationIsWritten(): void
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
        $written = $writer->render($document);
        self::assertSame("[a]( \"x\ny\") ![b]( \"x\ny\")\n", $written);
        self::assertSame([], $writer->finishConversionDiagnosticCollection()['diagnostics']);
        self::assertSame('<p><a href="" title="x' . "\n" . 'y">a</a> <img src="" alt="b" title="x' . "\n" . 'y"></p>', trim((new CarveConverter())->convert($written)));
    }

    public function testMarkdownImportKeepsAMultilineTitle(): void
    {
        foreach (
            [
                "[a](<> \"x\ny\")" => "[a]( \"x\ny\")",
                "[a](<> (x\ny))" => "[a]( \"x\ny\")",
                "![a](<> (x\ny))" => "![a]( \"x\ny\")",
                "[a](<>  \"x\ny\")" => "[a]( \"x\ny\")",
                "[a](<>\n\"x\ny\")" => "[a]( \"x\ny\")",
                "[a](<> \"x\ny\" )" => "[a]( \"x\ny\")",
                "[a [b] c](<> \"x\ny\")" => "[a [b] c]( \"x\ny\")",
                "[a\nb](<> \"x\ny\")" => "[a\nb]( \"x\ny\")",
            ] as $source => $expected
        ) {
            $result = (new MarkdownToCarve())->convertWithFidelityReport($source);
            self::assertSame($expected, trim($result->value), $source);
            self::assertNotContains('structure-unspellable', array_map(static fn ($diagnostic): string => $diagnostic->code, $result->diagnostics), $source);
        }
    }

    public function testMarkdownImportInlinesAReferenceWithAMultilineTitle(): void
    {
        $result = (new MarkdownToCarve())->convertWithFidelityReport("[a][r]\n\n[r]: <> \"x\ny\"");
        self::assertSame("[a]( \"x\ny\")", trim($result->value));
        self::assertContains('structure-unspellable', array_map(static fn ($diagnostic): string => $diagnostic->code, $result->diagnostics));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function quotedWrappedTitleProvider(): array
    {
        return [
            'quoted continuation' => ["> [t][r]\n>\n> [r]: <> \"a\n> b\"", "> [t]( \"a\n> b\")"],
            'lazy continuation' => ["> [t][r]\n>\n> [r]: <> \"a\nb\"", "> [t]( \"a\n> b\")"],
            'title on its own line' => ["> [t][r]\n>\n> [r]: <>\n> \"a\n> b\"", "> [t]( \"a\n> b\")"],
            'nested quote' => ["> > [t][r]\n> >\n> > [r]: <> \"a\n> > b\"", "> > [t]( \"a\n> > b\")"],
            'nested quote, shallower lazy line' => ["> > [t][r]\n> >\n> > [r]: <> \"a\n> b\"", "> > [t]( \"a\n> > b\")"],
            'quoted use of an unquoted definition' => ["> [t][r]\n\n[r]: <> \"a\nb\"", "> [t]( \"a\n> b\")"],
            'one-line title control' => ["> [t][r]\n>\n> [r]: <> \"a b\"", '> [t]( "a b")'],
        ];
    }

    #[DataProvider('quotedWrappedTitleProvider')]
    public function testMarkdownImportInlinesAQuotedReferenceWithAMultilineTitle(string $source, string $expected): void
    {
        $result = (new MarkdownToCarve())->convertWithFidelityReport($source);
        $carve = rtrim(trim($result->value), "> \n");
        self::assertSame($expected, $carve);
        self::assertContains('structure-unspellable', array_map(static fn ($diagnostic): string => $diagnostic->code, $result->diagnostics));
        self::assertMatchesRegularExpression('~^<blockquote>\s*(?:<blockquote>)?<p><a href="" title="a[ \\n]b">t</a></p>~', trim((new CarveConverter())->convert($result->value)));
    }

    public function testMarkdownImportInlinesEmptyDefinitionsWithUnspellableLabels(): void
    {
        foreach (
            [
                "[a\\]b]: <>\n\n[a\\]b]" => '[a\\]b]()',
                "[^]: <>\n\n[x][^]" => '[x]()',
                "[a\nb]: <> \"t\nx\"\n\n[a b]" => '[a b]()',
            ] as $source => $expected
        ) {
            $result = (new MarkdownToCarve())->convertWithFidelityReport($source);
            self::assertSame($expected, trim($result->value), $source);
            self::assertContains('structure-unspellable', array_map(static fn ($diagnostic): string => $diagnostic->code, $result->diagnostics), $source);
        }
    }
}
