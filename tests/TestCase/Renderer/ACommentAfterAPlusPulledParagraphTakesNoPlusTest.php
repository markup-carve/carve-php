<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A `+` before a comment is refused and publishes as text, so the writer puts
 * the comment at the item's content column (corpus row 930, carve#4770).
 * carve#5012 erases a `%%` line comment, so that one is now written at column 0.
 */
class ACommentAfterAPlusPulledParagraphTakesNoPlusTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function provider(): array
    {
        return [
            'closerless comment opener in a nested item' => [
                ". a\n  . %%%\n    n\n  zz\n%%%\n",
                ". a\n  . %%%\n    n\n    %%%\n  zz\n  %%%\n  %%%\n",
            ],
            'line comment after a pulled paragraph' => [
                ". a\n  . b\n    n\n+\nzz\n%% c\n",
                ". a\n  . b\n    n\n+\nzz\n\n%% c\n",
            ],
        ];
    }

    #[DataProvider('provider')]
    public function testTheCommentIsWrittenAtTheContentColumn(string $source, string $expected): void
    {
        $formatted = CarveConverter::toCarve($source);

        $this->assertSame($expected, $formatted);
        $this->assertSame($formatted, CarveConverter::toCarve($formatted));
        $this->assertSame(CarveConverter::create()->convert($source), CarveConverter::create()->convert($formatted));
    }
}
