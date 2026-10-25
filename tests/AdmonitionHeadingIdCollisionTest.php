<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

class AdmonitionHeadingIdCollisionTest extends TestCase
{
    public function testMergedC1CorpusRow(): void
    {
        $source = "{#adm-1}\n# H\n\n::: warning \"Careful\"\nbody\n:::\n";
        $expected = "<section id=\"adm-1\">\n  <h1>H</h1>\n  <aside class=\"admonition warning\" aria-labelledby=\"adm-1-2\">\n    <p class=\"admonition-title\" id=\"adm-1-2\">Careful</p>\n    <p>body</p>\n  </aside>\n</section>";
        self::assertSame($expected, trim((new CarveConverter())->convert($source)));
    }

    public function testAutomaticHeadingAfterAdmonition(): void
    {
        $source = "::: warning \"Careful\"\nbody\n:::\n\n# adm-1\n";
        $html = (new CarveConverter())->convert($source);
        self::assertStringContainsString('<section id="adm-1">', $html);
        self::assertStringContainsString('aria-labelledby="adm-1-2"', $html);
        self::assertStringContainsString('<p class="admonition-title" id="adm-1-2">', $html);
    }
}
