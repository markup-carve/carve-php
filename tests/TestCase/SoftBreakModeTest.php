<?php

declare(strict_types=1);

namespace Carve\Test\TestCase;

use Carve\CarveConverter;
use Carve\Renderer\MarkdownRenderer;
use Carve\Renderer\PlainTextRenderer;
use Carve\Renderer\SoftBreakMode;
use PHPUnit\Framework\TestCase;

class SoftBreakModeTest extends TestCase
{
    public function testConstructorWithSpaceMode(): void
    {
        $converter = new CarveConverter(softBreakMode: SoftBreakMode::Space);

        $this->assertSame("<p>Line one Line two</p>\n", $converter->convert("Line one\nLine two"));
    }

    public function testConstructorWithBreakMode(): void
    {
        $converter = new CarveConverter(softBreakMode: SoftBreakMode::Break);

        $this->assertSame("<p>Line one<br>\nLine two</p>\n", $converter->convert("Line one\nLine two"));
    }

    public function testMarkdownRendererSoftBreakMode(): void
    {
        $renderer = (new MarkdownRenderer())->setSoftBreakMode(SoftBreakMode::Space);
        $converter = CarveConverter::create(renderer: $renderer);

        $this->assertSame("Line one Line two\n", $converter->convert("Line one\nLine two"));
    }

    public function testPlainTextRendererSoftBreakMode(): void
    {
        $renderer = (new PlainTextRenderer())->setSoftBreakMode(SoftBreakMode::Newline);
        $converter = CarveConverter::create(renderer: $renderer);

        $this->assertSame("Line one\nLine two\n", $converter->convert("Line one\nLine two"));
    }
}
