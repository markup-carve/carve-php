<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Exception\RenderLossException;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Node\Inline\Text;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MarkdownUnicodeMarkerExhaustionTest extends TestCase
{
    private static function pool(): string
    {
        $pool = '';
        for ($code = 0xE000; $code <= 0xF8FF; $code++) {
            $pool .= mb_chr($code, 'UTF-8');
        }

        return $pool;
    }

    /**
     * @return array<int, array{string, string}>
     */
    public static function sources(): array
    {
        $rows = json_decode((string)file_get_contents(dirname(__DIR__, 2) . '/fixtures/markdown-unicode-marker-exhaustion.json'), true, flags: JSON_THROW_ON_ERROR);
        $pool = self::pool();
        $cases = [];
        foreach ($rows as $row) {
            $cases[] = [str_replace('FULL_PRIVATE_USE_POOL', $pool, $row['source']), str_replace('FULL_PRIVATE_USE_POOL', $pool, $row['markdown'])];
        }

        return $cases;
    }

    #[DataProvider('sources')]
    public function testFullPoolGoldensThroughSourceAndAst(string $source, string $expected): void
    {
        $writer = CarveConverter::markdown();
        $document = $writer->parse($source);
        foreach ([$writer->convertWithReport($source, strictLosses: true), $writer->renderWithReport($document, strictLosses: true)] as $result) {
            self::assertSame($expected, $result->value);
            self::assertStringContainsString(self::pool(), $result->value);
            self::assertSame([], $result->losses);
            self::assertSame(0, $result->totalLosses);
            self::assertFalse($result->truncated);
        }
        self::assertSame("ordinary\n", $writer->convert("ordinary\n"));
    }

    public function testLossCapsAndStrictCountsWithStringMarkers(): void
    {
        $pool = self::pool();
        $source = "```\n{$pool}\n```\n\n`one`{=latex} and `two`{=typst}";
        $writer = CarveConverter::markdown();
        $result = $writer->convertWithReport($source);
        self::assertStringContainsString($pool, $result->value);
        self::assertSame(['latex', 'typst'], array_column($result->losses, 'format'));
        self::assertSame(2, $result->totalLosses);
        $bounded = $writer->convertWithReport($source, maxRenderLosses: 0);
        self::assertSame([], $bounded->losses);
        self::assertSame(2, $bounded->totalLosses);
        self::assertTrue($bounded->truncated);
        try {
            $writer->convertWithReport($source, strictLosses: true, maxRenderLosses: 1);
            self::fail('Strict rendering should refuse the two raw-format losses.');
        } catch (RenderLossException $error) {
            self::assertSame(2, $error->totalLosses);
            self::assertSame(array_slice($result->losses, 0, 1), $error->losses);
            self::assertTrue($error->truncated);
        }
        self::assertSame(0, $writer->convertWithReport("ordinary\n")->totalLosses);
    }

    public function testTrailingHeadingHashesWithStringMarkers(): void
    {
        $anchor = "```\n" . self::pool() . "\n```\n\n";
        $writer = CarveConverter::markdown();
        foreach (
            [
                ["# heading \\#\n", "# heading \\#\n"],
                ["# a \\# \\#\n", "# a # \\#\n"],
                ["# company_id \\#\n", "# company_id \\#\n"],
            ] as [$source, $expected]
        ) {
            foreach ([$writer->convertWithReport($anchor . $source), $writer->renderWithReport($writer->parse($anchor . $source))] as $result) {
                self::assertSame($anchor . $expected, $result->value);
                self::assertSame(0, $result->totalLosses);
            }
        }
    }

    public function testFallbackTokensAssembledAcrossTextNodesRemainLiteral(): void
    {
        $writer = CarveConverter::markdown();
        $document = $writer->parse("ordinary\n");
        $authored = '';
        for ($nonce = 0; $nonce < 32; $nonce++) {
            $authored .= "\u{E009}m{$nonce}x0\u{E00A}";
        }
        $children = [new Text(self::pool())];
        foreach (mb_str_split($authored) as $character) {
            $children[] = new Text($character);
        }
        $children[] = new Text(' a@b.co');
        $paragraph = new Paragraph();
        $paragraph->setChildren($children);
        $document->setChildren([$paragraph]);
        $result = $writer->renderWithReport($document);
        self::assertSame(self::pool() . $authored . " a<!---->@b.co\n", $result->value);
        self::assertSame(0, $result->totalLosses);
    }

    public function testEmailOffsetsAfterStringMarkers(): void
    {
        $writer = CarveConverter::markdown();
        $document = $writer->parse("ordinary\n");
        $paragraph = new Paragraph();
        $paragraph->setChildren([new Text(self::pool() . ' _ a@b.co')]);
        $document->setChildren([$paragraph]);
        $result = $writer->renderWithReport($document);
        self::assertSame(self::pool() . " _ a<!---->@b.co\n", $result->value);
        self::assertSame(0, $result->totalLosses);
    }

    public function testTightEmphasisKeepsItsFlanksWithStringMarkers(): void
    {
        $writer = CarveConverter::markdown();
        $anchor = "```\n" . self::pool() . "\n```\n\n";
        foreach (['a*_x*', 'a*x!*', 'a*[x*', 'a*#x*', '*x*_b', '*x*_', 'a/*_x*/'] as $source) {
            $expected = $writer->convert($source);
            $result = $writer->convertWithReport($anchor . $source);
            self::assertSame($anchor . $expected, $result->value, $source);
            self::assertSame(0, $result->totalLosses, $source);
        }
    }

    public function testProbeTokensConsumedAsListMarkersAreNotReused(): void
    {
        $writer = CarveConverter::markdown();
        $document = $writer->parse("```\n" . self::pool() . "\n```\n");
        $token = "\u{E009}m0x8\u{E00A}";
        $children = [new Text('1')];
        foreach (mb_str_split($token) as $character) {
            $children[] = new Text($character);
        }
        $children[] = new Text(' x');
        $paragraph = new Paragraph();
        $paragraph->setChildren($children);
        $document->setChildren([$paragraph, ...$document->getChildren()]);
        $result = $writer->renderWithReport($document);
        self::assertStringContainsString('1' . $token . " x\n", $result->value);
        self::assertSame(0, $result->totalLosses);
    }
}
