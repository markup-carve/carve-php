<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Exception\RenderLossException;
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
}
