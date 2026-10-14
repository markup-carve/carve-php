<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\Parser\BlockParser;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use PHPUnit\Framework\TestCase;

class QuotedTermReleasesFlushListTest extends TestCase
{
    public function testFlushListIsOutsideQuotedTerm(): void
    {
        $render = static fn (string $source): string => (new HtmlRenderer())->render((new BlockParser())->parse($source));
        self::assertSame("<blockquote>\n  <dl>\n    <dt>c</dt>\n  </dl>\n</blockquote>\n<ul>\n  <li>t</li>\n</ul>\n", $render("> :: c\n- t\n"));
        self::assertStringContainsString("  <ul>\n    <li>t</li>\n  </ul>", $render("> :: c\n> - t\n"));
    }
}
