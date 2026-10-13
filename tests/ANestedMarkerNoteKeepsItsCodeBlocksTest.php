<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

class ANestedMarkerNoteKeepsItsCodeBlocksTest extends TestCase
{
    public function testCodeBelongsToTheNestedDefinition(): void
    {
        foreach ([11, 12] as $row) {
            $base = __DIR__ . '/spec/tests/corpus/556-a-closed-block-leaves-no-frame-for-the-line-below-' . $row;
            $source = file_get_contents($base . '.crv');
            $converter = new CarveConverter();
            $html = $converter->convert($source);
            self::assertSame(trim(file_get_contents($base . '.html')), trim($html));
            self::assertStringStartsWith("<ul>\n  <li></li>\n</ul>", $html);
            self::assertGreaterThan(strpos($html, '<li id="fn2">'), strpos($html, '<pre>'));
        }
    }
}
