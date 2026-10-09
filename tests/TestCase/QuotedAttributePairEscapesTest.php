<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class QuotedAttributePairEscapesTest extends TestCase
{
    /**
     * @return array<string, array{string, string, bool}>
     */
    public static function boundaries(): array
    {
        $rows = json_decode(file_get_contents(__DIR__ . '/../fixtures/quoted-attribute-pair-escapes.json'), true, flags: JSON_THROW_ON_ERROR);
        $cases = [];
        foreach ($rows as $row) {
            $cases[$row['name']] = [$row['source'], $row['html'], $row['roundTrip']];
        }

        return $cases;
    }

    #[DataProvider('boundaries')]
    public function testBoundary(string $source, string $html, bool $roundTrip): void
    {
        $converter = new CarveConverter();
        self::assertSame($html, trim($converter->convert($source)));
        if ($roundTrip) {
            self::assertSame($html, trim($converter->convert(CarveConverter::toCarve($source))));
        }
    }

    public function testQuotedValueOverridesRemainActiveInsideEnclosures(): void
    {
        $writer = new class extends CarveRenderer {
            public int $quoteCalls = 0;

            protected function quoteAttrValue(string $value, bool $forceQuotes = false): string
            {
                $this->quoteCalls++;

                return "'" . $this->escapeQuotedSlot($value, "'|") . "'";
            }
        };
        $converter = new CarveConverter();
        foreach (
            [
                '{,a [b]{title="x\\]"} c,}',
                '{=a [b]{title="x\\]"} c=}',
                '[a [b]{title="x\\]"} c]{}',
                '^[a [b]{title="x\\]"} c]',
            ] as $source
        ) {
            $formatted = $writer->render($converter->parse($source));
            self::assertStringContainsString("title='x\\]'", $formatted);
            self::assertSame($converter->convert($source), $converter->convert($formatted));
        }
        self::assertGreaterThan(0, $writer->quoteCalls);
    }
}
