<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AReleasedDescriptionMarkerKeepsItsListTightTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function examples(): iterable
    {
        yield 'bullet' => [":: t\n: - :: t\n    : x\n  - y\n", '<li>y</li>'];
        yield 'ordered' => [":: t\n: 1. :: t\n     : x\n  2. y\n", '<li>y</li>'];
        yield 'quote' => ["> :: t\n> : - :: t\n>     : x\n>   - y\n", '<li>y</li>'];
        yield 'authored blank stays loose' => [":: t\n: - :: t\n    : x\n\n  - y\n", '<li><p>y</p></li>'];
    }

    #[DataProvider('examples')]
    public function testReleasedMarkerDoesNotAddAnAuthoredBlank(string $source, string $expected): void
    {
        self::assertStringContainsString($expected, CarveConverter::create()->convert($source));
    }
}
