<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Parser\BlockParser;
use MarkupCarve\Carve\Renderer\AnsiRenderer;
use PHPUnit\Framework\TestCase;

class CrossImplementationDivergenceTest extends TestCase
{
    public function testCollapsedReferenceFallsBackToHeadingCaseInsensitively(): void
    {
        $this->assertSame(
            "<p>See <a href=\"#Name\">name</a></p>\n"
            . "<section id=\"Name\">\n"
            . "  <h1>Name</h1>\n"
            . "</section>\n",
            (new CarveConverter())->convert("See [name][]\n\n# Name"),
        );

        $this->assertStringContainsString(
            '<a href="#name">NAME</a>',
            (new CarveConverter())->convert("See [NAME][]\n\n# name"),
        );
    }

    public function testCollapsedHeadingReferenceRendersAsResolvedLinkInNonHtmlFormats(): void
    {
        $source = "See [name][]\n\n# Name";

        $this->assertSame("See [name](#Name)\n\n# Name {#Name}\n", CarveConverter::markdown()->convert($source));
        $this->assertSame("See name\n\nName\n", CarveConverter::plainText()->convert($source));

        $ansi = CarveConverter::ansi()->convert($source);
        $this->assertStringContainsString('name', $this->stripSgr($ansi));
        $this->assertStringNotContainsString(' (#Name)', $this->stripSgr($ansi));
        $this->assertStringNotContainsString('[name][]', $ansi);
    }

    public function testAnsiHeadingUnderlineUsesVisibleDisplayWidth(): void
    {
        $document = (new BlockParser())->parse('# H *em*');
        $ansi = (new AnsiRenderer(useColors: true))->render($document);
        $plain = $this->stripSgr($ansi);
        $lines = explode("\n", trim($plain));

        $this->assertSame('H em', $lines[0]);
        $this->assertSame('════', $lines[1]);
    }

    public function testHeaderRowspanPreservesColumnsInNonHtmlTables(): void
    {
        $source = "|=A|\n|^|x|";

        $this->assertSame("| A |\n| --- | --- |\n|  | x |\n", CarveConverter::markdown()->convert($source));
        $this->assertSame("A\n | x\n", CarveConverter::plainText()->convert($source));

        $ansi = $this->stripSgr(CarveConverter::ansi()->convert($source));
        $this->assertStringContainsString('│ A │', $ansi);
        $this->assertStringNotContainsString('│ A │   │', $ansi);
        $this->assertStringContainsString('│   │ x │', $ansi);
    }

    private function stripSgr(string $text): string
    {
        return preg_replace('/\033\[[0-9;]*m/', '', $text) ?? $text;
    }
}
