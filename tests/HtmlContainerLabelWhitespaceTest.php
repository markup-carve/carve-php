<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\TestCase;

final class HtmlContainerLabelWhitespaceTest extends TestCase
{
    public function testFoldedWhitespaceInContainerLabelsKeepsTheDiv(): void
    {
        $importer = new HtmlToCarve();
        foreach (
            [
                "<div><p class=\"div-label\">a  b\nc</p><p>body</p></div>" => "::: [a b c]\nbody\n:::\n",
                "<div><p class=\"div-label\">a\tb</p><p>body</p></div>" => "::: [a b]\nbody\n:::\n",
                "<div><p class=\"div-label\">a\r\nb</p><p>body</p></div>" => "::: [a b]\nbody\n:::\n",
            ] as $html => $expected
        ) {
            self::assertSame($expected, $importer->convert($html));
        }
    }
}
