<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

class AdmonitionHeadingIdCollisionTest extends TestCase
{
    public function testMergedC1CorpusRow(): void
    {
        $base = __DIR__ . '/spec/tests/corpus/663-a-titled-admonition-skips-an-authored-heading-id';
        $source = file_get_contents($base . '.crv');
        $expected = file_get_contents($base . '.html');
        self::assertNotFalse($source);
        self::assertNotFalse($expected);
        self::assertSame(trim($expected), trim((new CarveConverter())->convert($source)));
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
