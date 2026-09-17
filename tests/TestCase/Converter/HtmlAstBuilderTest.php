<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Converter\HtmlAstBuilder;
use PHPUnit\Framework\TestCase;

class HtmlAstBuilderTest extends TestCase
{
    public function testItBuildsTheBasicSharedFixtureWithoutWritingCarve(): void
    {
        $fixture = dirname(__DIR__, 2) . '/spec/tests/html-import/basic';
        $html = file_get_contents($fixture . '/input.html');
        $expected = file_get_contents($fixture . '/expected.ast.json');
        $this->assertNotFalse($html);
        $this->assertNotFalse($expected);

        $actual = (new HtmlAstBuilder())->build($html);
        unset($actual['srcByteLength']);

        $this->assertSame(json_decode($expected, true, flags: JSON_THROW_ON_ERROR), $actual);
    }
}
