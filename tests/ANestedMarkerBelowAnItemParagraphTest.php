<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ANestedMarkerBelowAnItemParagraphTest extends TestCase
{
    public static function paragraphCases(): iterable
    {
        yield 'plain paragraph' => ["* k\n\n    q\n   - m\n"];
        yield 'empty quoted link definition' => ["* k\n\n  > [n]:\n    q\n   - m\n"];
    }

    #[DataProvider('paragraphCases')]
    public function testFirstNestedMarkerOpensAList(string $source): void
    {
        $html = CarveConverter::create()->convert($source);

        self::assertSame(2, substr_count($html, '<ul>'), $source);
        self::assertStringContainsString('<li>m</li>', $html);
        self::assertStringNotContainsString('q\n- m', $html);
    }
}
