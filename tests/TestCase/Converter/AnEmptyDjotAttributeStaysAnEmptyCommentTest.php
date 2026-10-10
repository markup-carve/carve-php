<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Converter\DjotToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AnEmptyDjotAttributeStaysAnEmptyCommentTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function spellings(): array
    {
        return [
            'block level' => ["{}\nhi\n", "%%\nhi\n"],
            'multi-line comment above a paragraph' => ["{% a\n  b %}\nParagraph.\n", "%%\nParagraph.\n"],
            'between strong spans' => ["*a*{}*b*\n", "*a*{%%}*b*\n"],
            'between emphasis spans' => ["_a_{}_b_\n", "/a/{%%}/b/\n"],
            'doubled' => ["*a*{}{}*b*\n", "*a*{%%}*b*\n"],
        ];
    }

    #[DataProvider('spellings')]
    public function testKeepsTheEmptyAttributeAsAComment(string $djot, string $carve): void
    {
        self::assertSame($carve, (new DjotToCarve())->convert($djot));
    }
}
