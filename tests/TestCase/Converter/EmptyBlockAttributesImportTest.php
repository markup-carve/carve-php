<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class EmptyBlockAttributesImportTest extends TestCase
{
    public function testAttributedEmptyParagraphIsDroppedWithOneRow(): void
    {
        $result = (new HtmlToCarve())->convertWithReport(
            '<p>a</p><p class="mw-empty-elt" id="x"></p><p>b</p><p id="s"><span></span></p><p>c</p>',
        );

        self::assertSame("a\n\nb\n\nc", trim($result->value));
        self::assertSame(
            [
                ['element-dropped', 'Dropped <p> holding no content', '/p[2]'],
                ['element-dropped', 'Dropped <p> holding no content', '/p[4]'],
                ['element-dropped', 'Dropped empty <span> element', '/p[4]/span[1]'],
            ],
            array_map(
                static fn (array $row): array => [$row['code'], $row['message'], $row['path']],
                $result->report()['diagnostics'],
            ),
        );
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
    public function testAttributedEmptyParagraphLeavesNoNodeOnEitherExit(string $html, string $message): void
    {
        foreach (['safe', 'semantic', 'roundtrip'] as $mode) {
            $importer = new HtmlToCarve(importMode: $mode);
            $result = $importer->convertWithReport($html);
            self::assertStringNotContainsString('{#x}', $result->value);
            self::assertStringNotContainsString('[]{}', $result->value);
            $rows = array_column($result->report()['diagnostics'], 'message');
            self::assertContains($message, $rows);
            self::assertNotContains('Dropped unsupported attribute id on <p>', $rows);
            $ast = $importer->convertToAstWithReport($html);
            self::assertSame([], self::nodesWithId($ast->value, 'x'));
        }
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function emptyParagraphs(): array
    {
        $empty = 'Dropped <p> holding no content';

        return [
            'alone' => ['<p id="x"></p>', $empty],
            'before attributes' => ['<p id="x"></p><p id="y">b</p>', $empty],
            'consecutive' => ['<p id="x"></p><p id="y"></p>', $empty],
            'whitespace' => ["<p id=\"x\"> \n </p><p>b</p>", 'Dropped whitespace-only <p> holding no content character'],
            'before a break' => ['<p id="x"></p><hr id="h">', $empty],
            'in a list' => ['<ul><li><p id="x"></p></li></ul>', $empty],
            'in a quote' => ['<blockquote><p id="x"></p></blockquote>', $empty],
            'before a heading' => ['<p id="x"></p><h2>b</h2>', $empty],
        ];
    }

    public function testRoleOnAnEmptyParagraphGoesWithIt(): void
    {
        $result = (new HtmlToCarve())->convertWithReport('<p role="note"></p><hr role="separator">');
        self::assertSame("{role=separator}\n---", trim($result->value));
        self::assertSame(['Dropped <p> holding no content'], array_column($result->report()['diagnostics'], 'message'));
    }

    public function testThematicBreakMarkerHintsAreConsumed(): void
    {
        foreach (['-', '*', '_'] as $marker) {
            $html = '<hr id="h" data-char="' . $marker . '">';
            $importer = new HtmlToCarve(false, [], false, 'roundtrip');
            $result = $importer->convertWithReport($html);
            self::assertSame("{#h}\n" . str_repeat($marker, 3), trim($result->value));
            self::assertSame([], $result->diagnostics);
            $ast = $importer->convertToAstWithReport($html);
            self::assertSame('thematic_break', $ast->value['children'][0]['type']);
            self::assertSame('h', $ast->value['children'][0]['attrs']['id']);
            self::assertArrayNotHasKey('data-char', $ast->value['children'][0]['attrs']['keyValues'] ?? []);
        }
    }

    public function testFlattenedEmptyParagraphsReportTheirAttributes(): void
    {
        $inputs = [
            '<dl><dt>t</dt><dd><p id="x"></p></dd></dl>' => 'Dropped <p> holding no content',
            '<table><tr><td><p id="x"></p></td></tr></table>' => 'Dropped unsupported attribute id on <p>',
            '<figure><img src="a.png"><figcaption><p id="x"></p></figcaption></figure>' => 'Dropped unsupported attribute id on <p>',
        ];
        foreach (['safe', 'semantic', 'roundtrip'] as $mode) {
            foreach ([false, true] as $listTable) {
                foreach ($inputs as $html => $message) {
                    $importer = new HtmlToCarve(listTableForBlockCells: $listTable, importMode: $mode);
                    $result = $importer->convertWithReport($html);
                    self::assertStringNotContainsString('{#x}', $result->value);
                    self::assertContains($message, array_column($result->report()['diagnostics'], 'message'));
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
