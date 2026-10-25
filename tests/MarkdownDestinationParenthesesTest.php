<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Renderer\MarkdownRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MarkdownDestinationParenthesesTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function cases(): array
    {
        return [
            'open' => ['[x](a\\(b)', '[x](a\\(b)'],
            'close' => ['[x](a\\)b)', '[x](a\\)b)'],
            'pair' => ['[x](a(b))', '[x](a\\(b\\))'],
            'nested' => ['[x](a(b(c)))', '[x](a\\(b\\(c\\)\\))'],
            'image' => ['![x](a(b))', '![x](a\\(b\\))'],
        ];
    }

    #[DataProvider('cases')]
    public function testDestination(string $source, string $expected): void
    {
        $document = (new CarveConverter())->parse($source);
        self::assertSame($expected . "\n", (new MarkdownRenderer())->render($document));
    }
}
