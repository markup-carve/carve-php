<?php

declare(strict_types=1);

namespace Carve\Test\TestCase\Renderer;

use Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * Severity-2 renderer content loss: non-HTML renderers must not drop content.
 */
class Sev2RendererLossTest extends TestCase
{
    public function testEscapedTextIsNotDroppedInNonHtmlRenderers(): void
    {
        $md = CarveConverter::markdown()->convert('a \*lit\* b');
        $this->assertStringContainsString('lit', $md);
        $this->assertStringContainsString('*', $md);

        $plain = CarveConverter::plainText()->convert('a \*lit\* b');
        $this->assertStringContainsString('*lit*', $plain);

        $ansi = CarveConverter::ansi()->convert('a \*lit\* b');
        $this->assertStringContainsString('*lit*', $ansi);
    }

    public function testAbbreviationTitlePreservedInMarkdown(): void
    {
        $md = CarveConverter::markdown()->convert("The HTML spec.\n\n*[HTML]: HyperText Markup Language");
        $this->assertStringContainsString('HyperText Markup Language', $md);
    }

    public function testFigureCaptionNotGluedInMarkdown(): void
    {
        $md = CarveConverter::markdown()->convert("![a](i.png)\n^ Cap text");
        // caption sits on its own line, not glued to the image
        $this->assertStringNotContainsString('i.png)Cap', $md);
        $this->assertStringContainsString('Cap text', $md);
    }

    public function testAdmonitionTitlePreservedInNonHtmlRenderers(): void
    {
        $src = ":::note \"Heads up\"\nbody\n:::";
        $this->assertStringContainsString('Heads up', CarveConverter::markdown()->convert($src));
        $this->assertStringContainsString('Heads up', CarveConverter::plainText()->convert($src));
        $this->assertStringContainsString('Heads up', CarveConverter::ansi()->convert($src));
    }
}
