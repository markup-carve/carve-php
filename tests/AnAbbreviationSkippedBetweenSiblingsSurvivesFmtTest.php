<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve#4716: an abbreviation definition between list or description siblings
 * is skipped, and the writer still writes it, in order with later definitions.
 */
final class AnAbbreviationSkippedBetweenSiblingsSurvivesFmtTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function documents(): array
    {
        return [
            'between list items' => ["- a\n*[A]: x\n- b A\n", "- a\n- b A\n\n*[A]: x\n"],
            'between descriptions' => [":: t\n: x\n*[A]: y\n: d A\n", ":: t\n: x\n: d A\n\n*[A]: y\n"],
            'before a later definition' => ["- a\n*[A]: x\n- b A\n\n*[A]: z\n", "- a\n- b A\n\n*[A]: x\n\n*[A]: z\n"],
        ];
    }

    #[DataProvider('documents')]
    public function testTheDefinitionIsWrittenAndRendersTheSame(string $source, string $formatted): void
    {
        self::assertSame($formatted, CarveConverter::toCarve($source));
        self::assertSame(CarveConverter::create()->convert($source), CarveConverter::create()->convert($formatted));
    }
}
