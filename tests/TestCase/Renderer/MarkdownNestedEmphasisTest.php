<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
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
}
