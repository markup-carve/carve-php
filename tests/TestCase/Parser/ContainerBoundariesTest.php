<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

class ContainerBoundariesTest extends TestCase
{
    /**
     * Rows this engine answers differently, with the answer it gives.
     *
     * A `+` attachment inside an item does not carry the item's open fence, so
     * the blank lines below the marker are not collected as payload; the oracle
     * and carve-js collect them. Tracked in carve-php#2754.
     *
     * @var array<string, string>
     */
    protected const DIVERGENCES = [
        'container-293' => "<ul>\n  <li>\n    <pre><code></code></pre>\n  </li>\n</ul>\n<p>tail</p>",
        'container-629' => "<ol>\n  <li>\n    <pre><code></code></pre>\n  </li>\n</ol>\n<p>tail</p>",
    ];

    public function testSharedContainerBoundaries(): void
    {
        $path = dirname(__DIR__, 2) . '/fixtures/container-boundaries.json';
        $fixtures = json_decode((string)file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $converter = new CarveConverter();
        foreach ($fixtures as $fixture) {
            $expected = self::DIVERGENCES[$fixture['name']] ?? $fixture['html'];
            $this->assertSame($expected, trim($converter->convert($fixture['source'])), $fixture['name']);
        }
    }

    /**
     * A divergence that no longer describes this engine hides a row that agrees,
     * so the entry expires with the difference.
     */
    public function testEveryDivergenceStillDiffers(): void
    {
        $path = dirname(__DIR__, 2) . '/fixtures/container-boundaries.json';
        $fixtures = json_decode((string)file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $shared = [];
        foreach ($fixtures as $fixture) {
            $shared[$fixture['name']] = $fixture['html'];
        }
        foreach (self::DIVERGENCES as $name => $html) {
            $this->assertArrayHasKey($name, $shared, "Divergence names no row: {$name}");
            $this->assertNotSame($shared[$name], $html, "{$name} agrees with the shared row - delete its DIVERGENCES entry");
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
