<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

class ContainerBoundariesTest extends TestCase
{
    public function testSharedContainerBoundaries(): void
    {
        $path = dirname(__DIR__, 2) . '/fixtures/container-boundaries.json';
        $fixtures = json_decode((string)file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $converter = new CarveConverter();
        foreach ($fixtures as $fixture) {
            $expected = $fixture['html'];
            $this->assertSame($expected, trim($converter->convert($fixture['source'])), $fixture['name']);
        }
    }

    public function testAnAttachmentDoesNotSplitASurroundingDiv(): void
    {
        $source = "> ::: box\n> ```\n+\ntail\n> :::\n";
        $expected = "<blockquote>\n  <div class=\"box\">\n    <pre><code>\ntail\n\n</code></pre>\n  </div>\n</blockquote>";
        $this->assertSame($expected, trim((new CarveConverter())->convert($source)));
    }

    public function testAFenceAfterAnAttachmentOpensANewBlock(): void
    {
        $source = "> ```\n> code\n+\npara\n> ```\n> after\n";
        $expected = "<blockquote>\n  <pre><code>code\n</code></pre>\n  <p>para</p>\n  <pre><code>after\n</code></pre>\n</blockquote>";
        $this->assertSame($expected, trim((new CarveConverter())->convert($source)));
    }
}
