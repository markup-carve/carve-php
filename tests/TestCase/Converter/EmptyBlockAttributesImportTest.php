<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use DOMDocument;
use DOMXPath;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class EmptyBlockAttributesImportTest extends TestCase
{
    public function testEmptyParagraphKeepsItsAttributes(): void
    {
        $result = (new HtmlToCarve())->convertWithReport(
            '<p>a</p><p class="mw-empty-elt" id="x"></p><p>b</p>',
        );

        self::assertSame("a\n\n{#x .mw-empty-elt}\n[]{}\n\nb", trim($result->value));
        self::assertSame([], $result->diagnostics);
    }

    public function testThematicBreakKeepsItsAttributes(): void
    {
        $result = (new HtmlToCarve())->convertWithReport('<hr id="h">');

        self::assertSame("{#h}\n---", trim($result->value));
        self::assertSame([], $result->diagnostics);
    }

    public function testUnattributedEmptyParagraphStillWritesNothing(): void
    {
        $result = (new HtmlToCarve())->convertWithReport('<p>a</p><p></p><p>b</p>');

        self::assertSame("a\n\nb", trim($result->value));
        self::assertSame([], $result->diagnostics);
    }

    #[DataProvider('emptyParagraphs')]
    public function testEmptyParagraphKeepsItsOwnAttributes(string $html, int $paragraphs): void
    {
        foreach (['safe', 'semantic', 'roundtrip'] as $mode) {
            $importer = new HtmlToCarve(listTableForBlockCells: true, importMode: $mode);
            $result = $importer->convertWithReport($html);
            self::assertSame([], $result->diagnostics);
            $rendered = (new CarveConverter())->convert($result->value);
            $dom = new DOMDocument();
            $dom->loadHTML($rendered, LIBXML_NOERROR | LIBXML_NOWARNING);
            $xpath = new DOMXPath($dom);
            self::assertSame($paragraphs, $dom->getElementsByTagName('p')->length);
            $empty = $xpath->query('//p[@id="x"]');
            self::assertNotFalse($empty);
            self::assertSame(1, $empty->length);
            self::assertSame('', $empty->item(0)?->textContent);
            self::assertSame($result->value, $importer->convert($rendered));
            $ast = $importer->convertToAstWithReport($html);
            self::assertSame([], $ast->diagnostics);
            $nodes = self::nodesWithId($ast->value, 'x');
            self::assertCount(1, $nodes);
            self::assertSame('paragraph', $nodes[0]['type']);
            self::assertSame([], $nodes[0]['children']);
        }
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function emptyParagraphs(): array
    {
        return [
            'alone' => ['<p id="x"></p>', 1],
            'before content' => ['<p id="x"></p><p>b</p>', 2],
            'after content' => ['<p>a</p><p id="x"></p>', 2],
            'before attributes' => ['<p id="x"></p><p id="y">b</p>', 2],
            'consecutive' => ['<p id="x"></p><p id="y"></p>', 2],
            'whitespace' => ["<p id=\"x\"> \n </p><p>b</p>", 2],
            'before a break' => ['<p id="x"></p><hr id="h">', 1],
            'in a list' => ['<ul><li><p id="x"></p></li></ul>', 1],
            'in a quote' => ['<blockquote><p id="x"></p></blockquote>', 1],
            'in a table cell' => ['<table><tr><td><p id="x"></p><p>b</p></td></tr></table>', 2],
            'before a heading' => ['<p id="x"></p><h2>b</h2>', 1],
            'before a list' => ['<p id="x"></p><ul><li>b</li></ul>', 1],
        ];
    }

    public function testRolesSurviveOnEmptyBlocks(): void
    {
        $html = '<p role="note"></p><hr role="separator">';
        $result = (new HtmlToCarve())->convertWithReport($html);
        self::assertSame("{role=note}\n[]{}\n\n{role=separator}\n---", trim($result->value));
        self::assertSame([], $result->diagnostics);
        self::assertStringContainsString('<p role="note">', (new CarveConverter())->convert($result->value));
    }

    public function testThematicBreakMarkerHintsAreConsumed(): void
    {
        foreach (['-', '*', '_'] as $marker) {
            $html = '<hr id="h" data-char="' . $marker . '">';
            $importer = new HtmlToCarve();
            $result = $importer->convertWithReport($html);
            self::assertSame("{#h}\n" . str_repeat($marker, 3), trim($result->value));
            self::assertSame([], $result->diagnostics);
            $ast = $importer->convertToAstWithReport($html);
            self::assertSame('thematic_break', $ast->value['children'][0]['type']);
            self::assertSame('h', $ast->value['children'][0]['attrs']['id']);
            self::assertArrayNotHasKey('data-char', $ast->value['children'][0]['attrs']['keyValues'] ?? []);
        }
    }

    public function testFlattenedEmptyParagraphsDoNotLeaveSpanAnchors(): void
    {
        $inputs = [
            '<dl><dt>t</dt><dd><p id="x"></p></dd></dl>',
            '<table><tr><td><p id="x"></p></td></tr></table>',
            '<figure><img src="a.png"><figcaption><p id="x"></p></figcaption></figure>',
        ];
        foreach (['safe', 'semantic', 'roundtrip'] as $mode) {
            foreach ([false, true] as $listTable) {
                foreach ($inputs as $html) {
                    $importer = new HtmlToCarve(listTableForBlockCells: $listTable, importMode: $mode);
                    $result = $importer->convertWithReport($html);
                    self::assertStringNotContainsString('[]{}', $result->value);
                    self::assertStringNotContainsString('{#x}', $result->value);
                    self::assertContains('Dropped unsupported attribute id on <p>', array_column($result->report()['diagnostics'], 'message'));
                    $rendered = (new CarveConverter())->convert($result->value);
                    self::assertSame($result->value, $importer->convert($rendered));
                }
            }
        }
    }

    /**
     * @param array<string|int, mixed> $node
     * @param string $id
     *
     * @return list<array<string|int, mixed>>
     */
    private static function nodesWithId(array $node, string $id): array
    {
        $found = ($node['attrs']['id'] ?? null) === $id ? [$node] : [];
        foreach ($node as $value) {
            if (is_array($value)) {
                array_push($found, ...self::nodesWithId($value, $id));
            }
        }

        return $found;
    }
}
