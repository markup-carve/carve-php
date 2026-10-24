<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Exception\RenderLossException;
use MarkupCarve\Carve\Renderer\AttributeFallback;
use MarkupCarve\Carve\Renderer\MarkdownRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MarkdownNestedEmphasisTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function controls(): array
    {
        $rows = json_decode(file_get_contents(__DIR__ . '/../../fixtures/markdown-nested-emphasis.json'), true, flags: JSON_THROW_ON_ERROR);
        $controls = [];
        foreach ($rows as $index => $row) {
            $controls[(string)$index . ': ' . $row['source']] = [$row['source'], $row['markdown']['php']];
        }

        return $controls;
    }

    #[DataProvider('controls')]
    public function testMarkdownPreservesNestedEmphasis(string $source, string $expected): void
    {
        $converter = CarveConverter::markdown();
        foreach ([$converter->convertWithReport($source), $converter->renderWithReport($converter->parse($source))] as $result) {
            self::assertSame($expected, $result->value);
            self::assertSame([], $result->losses);
            self::assertSame(0, $result->totalLosses);
            self::assertFalse($result->truncated);
        }
    }

    public function testWrapperHostsAndAttributeFallbackPreserveNestedEmphasis(): void
    {
        $rows = json_decode(file_get_contents(__DIR__ . '/../../fixtures/markdown-nested-emphasis-hosts.json'), true, flags: JSON_THROW_ON_ERROR);
        $converter = CarveConverter::create(null, (new MarkdownRenderer())->setAttributeFallback(AttributeFallback::Html));
        foreach ($rows as $row) {
            $captions = str_starts_with($row['source'], '::: figure')
                ? (str_contains($row['source'], ":::\n^ ") ? 2 : 1) : 0;
            foreach ([$converter->convertWithReport($row['source']), $converter->renderWithReport($converter->parse($row['source']))] as $report) {
                self::assertSame($row['markdown'], $report->value);
                self::assertSame(array_fill(0, $captions, 'markdown-caption-flattened'), array_column($report->losses, 'code'));
                self::assertSame($captions, $report->totalLosses);
                self::assertFalse($report->truncated);
            }
        }
    }

    public function testDropModeDoesNotRetainAttributesInDelimiterFallbacks(): void
    {
        $controls = [
            ['{*x*}{#s .c}', "**x**\n"],
            ['{/x/}{#e .c}', "*x*\n"],
            ['{~x~}{#d .c}', "~~x~~\n"],
            ['a{*!x*}{.c}b', "a<strong>!x</strong>b\n"],
            ['{*a{*x*}{.c}b*}', "**a<strong>x</strong>b**\n"],
        ];
        $converter = CarveConverter::create(renderer: (new MarkdownRenderer())->setAttributeFallback(AttributeFallback::Drop));
        foreach ($controls as [$source, $expected]) {
            self::assertSame($expected, $converter->convert($source), $source);
        }
    }

    public function testRepairKeepsOrderedLossCountsAndRendererReuse(): void
    {
        $source = '{*a {/b {*x `omitted`{=latex}*} c/} d*} and `tail`{=typst}';
        $converter = CarveConverter::markdown();
        $report = $converter->convertWithReport($source);
        self::assertSame($converter->convert($source), $report->value);
        self::assertSame(['latex', 'typst'], array_column($report->losses, 'format'));
        self::assertSame([13, 45], array_column(array_column($report->losses, 'pos'), 'startColumn'));
        self::assertSame(2, $report->totalLosses);
        $bounded = $converter->convertWithReport($source, maxRenderLosses: 0);
        self::assertSame([], $bounded->losses);
        self::assertSame(2, $bounded->totalLosses);
        self::assertTrue($bounded->truncated);
        try {
            $converter->convertWithReport($source, true, 1);
            self::fail('Strict rendering should refuse the loss.');
        } catch (RenderLossException $error) {
            self::assertSame(2, $error->totalLosses);
            self::assertCount(1, $error->losses);
            self::assertTrue($error->truncated);
        }
        self::assertSame(0, $converter->convertWithReport('/clean/')->totalLosses);
    }
}
