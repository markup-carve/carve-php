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
            ['[x](/f&ouml;&ouml; "f&ouml;&ouml;")', '<p><a href="/föö" title="föö">x</a></p>'],
            ["[x]\n\n[x]: /f&ouml;&ouml;", '<p><a href="/föö">x</a></p>'],
            ["[ΑΓΩ]: /φου\n\n[αγω]", '<p><a href="/φου">αγω</a></p>'],
            ['[x](foo\\bar)', '<p><a href="foo\bar">x</a></p>'],
            ['[x](foo%20b&auml;)', '<p><a href="foo%20bä">x</a></p>'],
            ['[x]("title")', '<p><a href="&quot;title&quot;">x</a></p>'],
            ['[x](a%zz)', '<p><a href="a%zz">x</a></p>'],
            ['[x](a\\*b)', '<p><a href="a*b">x</a></p>'],
            ['a ![x](f&ouml;&ouml;)', '<p>a <img src="föö" alt="x"></p>'],
        ];
        foreach ($cases as [$source, $expected]) {
            $this->assertSame($expected, rtrim((new CarveConverter())->convert((new MarkdownToCarve())->convert($source)), "\n"));
        }
    }
}
