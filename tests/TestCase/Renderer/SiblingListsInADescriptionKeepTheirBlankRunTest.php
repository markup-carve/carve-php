<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Two sibling lists in a description are written apart by the three-blank
 * boundary (§11 N1a), not by a column shift rule A no longer reads
 * (carve-php#4624).
 */
final class SiblingListsInADescriptionKeepTheirBlankRunTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function sources(): array
    {
        return [
            'description' => [":: t\n: - a\n\n\n\n  - b\n"],
            'three lists' => [":: t\n: - a\n\n\n\n  - b\n\n\n\n  - c\n"],
            'ordered' => [":: t\n: 1. a\n\n\n\n  1. b\n"],
            'quoted description' => ["> :: t\n> : - a\n>\n>\n>\n>   - b\n"],
            'nested description' => [":: o\n: :: t\n  : - a\n\n\n\n    - b\n"],
            'footnote description' => ["body[^n]\n\n[^n]: :: t\n  : - a\n\n\n\n    - b\n"],
        ];
    }

    #[DataProvider('sources')]
    public function testTheFormatterKeepsTheSource(string $source): void
    {
        $converter = new CarveConverter();
        $formatted = CarveConverter::toCarve($source);
        self::assertSame($source, $formatted);
        self::assertSame($converter->convert($source), $converter->convert($formatted));
    }
}
