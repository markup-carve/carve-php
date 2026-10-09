<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class InlineImagePlacementTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function placements(): iterable
    {
        $cases = json_decode((string)file_get_contents(__DIR__ . '/../../fixtures/inline-image-placement.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($cases as $index => $case) {
            foreach ($case['outputs'] as $target => $expected) {
                yield $index . '-' . $target => [$case['source'], $target, $expected];
            }
        }
    }

    #[DataProvider('placements')]
    public function testAnInlineHostAddsNoImageSeparator(string $source, string $target, string $expected): void
    {
        $converter = match ($target) {
            'html' => new CarveConverter(),
            'markdown' => CarveConverter::markdown(),
            'plain' => CarveConverter::plainText(),
            'ansi' => CarveConverter::ansi(),
        };
        $this->assertSame($expected, $converter->convert($source));
    }
}
