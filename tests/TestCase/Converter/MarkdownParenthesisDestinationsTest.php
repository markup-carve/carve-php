<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\TestCase;

class MarkdownParenthesisDestinationsTest extends TestCase
{
    public function testParenthesesRemainUrlData(): void
    {
        $cases = [
            ["[link][r]\n\n[r]: <a)b>", '<p><a href="a)b">link</a></p>'],
            ["[link][r]\n\n[r]: <a(b>", '<p><a href="a(b">link</a></p>'],
            ["[link][r]\n\n[r]: a\\)b", '<p><a href="a)b">link</a></p>'],
            ['[link](\\(foo\\))', '<p><a href="(foo)">link</a></p>'],
            ['[link](foo\\(and\\(bar\\))', '<p><a href="foo(and(bar)">link</a></p>'],
            ['[link](<a)b>)', '<p><a href="a)b">link</a></p>'],
            ['[link](a(b)c)', '<p><a href="a(b)c">link</a></p>'],
            ['[link](a%28b%29)', '<p><a href="a%28b%29">link</a></p>'],
        ];
        foreach ($cases as [$source, $expected]) {
            $this->assertSame($expected, rtrim((new CarveConverter())->convert((new MarkdownToCarve())->convert($source)), "\n"));
        }
    }
}
