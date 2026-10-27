<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\TestCase;

final class MarkdownDocumentEdgeFixedPointTest extends TestCase
{
    public function testDecorativeEdgeLines(): void
    {
        $rows = [
            'cm-097' => ["\n====\n", "====\n", 'a58a920120379ad4ec52b22db9716886b7738c0863edf651f0da7d35ac82fa3e'],
            'cm-117' => ["\n    \n    foo\n    \n\n", "```\nfoo\n```\n", '0636e179ebcce0bf3a033ec241e7b7edf89a1bbf6d8539fa2391e93e706a5ff4'],
            'cm-227' => ["  \n\naaa\n  \n\n# aaa\n\n  \n", "aaa\n\n# aaa\n", 'b5c6e2052e98476f5527f277250187d5367969d4eddd703d261e9033b672a2ee'],
            'cm-541' => ["[Foo\n  bar]: /url\n\n[Baz][Foo bar]\n", "[Baz](/url)\n", 'f32346f981077b8ef98e8d45111cd3af9340dc3ab57e1407169ba2a2338bb2d1'],
        ];

        foreach ($rows as $id => [$source, $expected, $htmlHash]) {
            $actual = (new MarkdownToCarve())->convert($source);
            self::assertSame($expected, $actual, $id);
            self::assertSame($actual, CarveConverter::toCarve($actual), $id);
            self::assertSame($htmlHash, hash('sha256', (new CarveConverter())->convert($actual)), $id);
        }
    }
}
