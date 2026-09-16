<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Carve has no pointy link destination, so CommonMark's `<a b>` is written as
 * a bare destination, percent-encoding what a bare one cannot hold (#2073).
 */
class APointyMarkdownDestinationIsWrittenBareTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function shapes(): array
    {
        return [
            'a space' => ['[m](< >)', '[m](%20)'],
            'a path with a space' => ['[k](</u v>)', '[k](/u%20v)'],
            'a title after it' => ['[k](</u v> "t")', '[k](/u%20v "t")'],
            'an image' => ['![i](<a b.png>)', '![i](a%20b.png)'],
            'parentheses' => ['[k](<a(b)>)', '[k](a%28b%29)'],
            'an unbalanced parenthesis' => ['[x](<a)b>) c', '[x](a%29b) c'],
            'an escaped angle bracket' => ['[k](<a\\>b>)', '[k](a%3Eb)'],
            'a backslash' => ['[k](<a\\\\b>)', '[k](a%5Cb)'],
            'a tag-like destination' => ['[k](<u>)', '[k](u)'],
            'a reference definition' => ["[t][r]\n\n[r]: </u v>", "[t][r]\n\n[r]: /u%20v"],
            'a definition label holding an escaped bracket' => ["[t][a\\]]\n\n[a\\]]: </u v>", "[t][a\\]]\n\n[a\\]]: /u%20v"],
            'a code span' => ['`[k](<u v>)`', '`[k](<u v>)`'],
            'angle brackets not in a destination' => ['a <b>x</b> y', 'a *x* y'],
        ];
    }

    #[DataProvider('shapes')]
    public function testTheDestinationIsWrittenBare(string $markdown, string $carve): void
    {
        $this->assertSame($carve, rtrim((new MarkdownToCarve())->convert($markdown), "\n"));
    }
}
