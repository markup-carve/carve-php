<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AnEmptyQuoteNeedsNoCaptionMarkerEscapeTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function emptyQuotes(): array
    {
        return [
            'adjacent marker' => [">\n^ cap\n"],
            'separated marker' => [">\n\n^ cap\n"],
            'preceding table' => ["| a |\n\n>\n\n^ cap\n"],
            'authored attributes' => ["{#empty}\n>\n^ cap\n"],
            'nested empty quote' => ["> >\n> ^ cap\n"],
        ];
    }

    #[DataProvider('emptyQuotes')]
    public function testTheWriterLeavesTheCaptionMarkerBare(string $source): void
    {
        $formatted = CarveConverter::toCarve($source);
        $converter = CarveConverter::create();
        $codec = new AstCodec();

        self::assertStringNotContainsString('\\^ cap', $formatted);
        self::assertStringContainsString('^ cap', $formatted);
        self::assertSame($converter->convert($source), $converter->convert($formatted));
        self::assertSame(
            self::withoutPositions($codec->encode($converter->parse($source))),
            self::withoutPositions($codec->encode($converter->parse($formatted))),
        );
        self::assertSame($formatted, CarveConverter::toCarve($formatted));
    }

    public function testANonemptyQuoteStillNeedsTheEscape(): void
    {
        $source = "> q\n\n\\^ cap\n";
        $formatted = CarveConverter::toCarve($source);
        self::assertStringContainsString('\\^ cap', $formatted);
        self::assertSame(CarveConverter::create()->convert($source), CarveConverter::create()->convert($formatted));
        self::assertSame($formatted, CarveConverter::toCarve($formatted));
    }

    public function testANonemptyQuoteStillTakesAnAuthoredCaption(): void
    {
        $source = "> q\n>\n\n^ cap\n";
        $formatted = CarveConverter::toCarve($source);
        self::assertStringNotContainsString('\\^ cap', $formatted);
        self::assertStringContainsString('<figcaption>cap</figcaption>', CarveConverter::create()->convert($formatted));
        self::assertSame(CarveConverter::create()->convert($source), CarveConverter::create()->convert($formatted));
    }

    /**
     * @param array<string|int, mixed> $tree
     *
     * @return array<string|int, mixed>
     */
    private static function withoutPositions(array $tree): array
    {
        unset($tree['pos'], $tree['srcByteLength']);
        foreach ($tree as $key => $value) {
            if (is_array($value)) {
                $tree[$key] = self::withoutPositions($value);
            }
        }

        return $tree;
    }
}
