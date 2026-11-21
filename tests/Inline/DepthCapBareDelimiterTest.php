<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Inline;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DepthCapBareDelimiterTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string}>
     */
    public static function marks(): array
    {
        return [
            'strong' => ['*', 'strong', '*a*'],
            'emphasis' => ['/', 'em', '/a/'],
        ];
    }

    #[DataProvider('marks')]
    public function testBareOpenerAtCapStaysTextAfterUnclosedBrackets(string $mark, string $tag, string $literal): void
    {
        $paired = '<' . $tag . '>[</' . $tag . '>[';
        $source = str_repeat($mark . '[', 100) . $literal;

        $this->assertSame('<p>' . str_repeat($paired, 50) . $literal . '</p>', Carve::render($source));
        $this->assertSame(
            '<p>' . str_repeat($paired, 49) . $mark . '[' . '<' . $tag . '>a</' . $tag . '></p>',
            Carve::render(str_repeat($mark . '[', 99) . $literal),
        );
    }
}
