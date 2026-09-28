<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\TestCase;

class MarkdownUrlEncodingTest extends TestCase
{
    public function testDestinationsUseUriEncoding(): void
    {
        $cases = [
            ['[x](/f&ouml;&ouml; "f&ouml;&ouml;")', '<p><a href="/f%C3%B6%C3%B6" title="föö">x</a></p>'],
            ["[x]\n\n[x]: /f&ouml;&ouml;", '<p><a href="/f%C3%B6%C3%B6">x</a></p>'],
            ["[ΑΓΩ]: /φου\n\n[αγω]", '<p><a href="/%CF%86%CE%BF%CF%85">αγω</a></p>'],
            ['[x](foo\\bar)', '<p><a href="foo%5Cbar">x</a></p>'],
            ['[x](foo%20b&auml;)', '<p><a href="foo%20b%C3%A4">x</a></p>'],
            ['[x]("title")', '<p><a href="%22title%22">x</a></p>'],
            ['[x](a%zz)', '<p><a href="a%zz">x</a></p>'],
            ['[x](a\\*b)', '<p><a href="a*b">x</a></p>'],
            ['a ![x](f&ouml;&ouml;)', '<p>a <img src="f%C3%B6%C3%B6" alt="x"></p>'],
        ];
        foreach ($cases as [$source, $expected]) {
            $this->assertSame($expected, rtrim((new CarveConverter())->convert((new MarkdownToCarve())->convert($source)), "\n"));
        }
    }
}
