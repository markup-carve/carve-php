<?php

declare(strict_types=1);

namespace Carve\Test\TestCase;

use Carve\CarveConverter;
use Carve\Converter\HtmlToCarve;
use Carve\Renderer\AnsiRenderer;
use Carve\Renderer\MarkdownRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Non-HTML render targets are safe-by-default too: Markdown output cannot carry
 * XSS into a downstream Markdown -> HTML render, and ANSI/plain output cannot
 * inject terminal escape sequences.
 */
class NonHtmlRendererSecurityTest extends TestCase
{
    protected function md(string $djot): string
    {
        $c = new CarveConverter();

        return trim((new MarkdownRenderer())->render($c->parse($djot)));
    }

    protected function ansi(string $djot): string
    {
        $c = new CarveConverter();

        return (new AnsiRenderer())->render($c->parse($djot));
    }

    public function testMarkdownBlanksDangerousLinkScheme(): void
    {
        $this->assertSame('[x]()', $this->md('[x](javascript:alert(1))'));
        $this->assertStringContainsString('[ok](https://e.com)', $this->md('[ok](https://e.com)'));
    }

    public function testMarkdownEscapesRawHtml(): void
    {
        $out = $this->md("```=html\n<script>alert(1)</script>\n```");
        $this->assertStringNotContainsString('<script>', $out);
        $this->assertStringContainsString('&lt;script&gt;', $out);
    }

    public function testMarkdownEscapesEmbeddedHtmlInTextAndFallbackTags(): void
    {
        $this->assertStringNotContainsString('<img', $this->md('plain <img onerror=x> text'));
        // Superscript HTML fallback: children are HTML-escaped.
        $sup = $this->md('{^<img src=x onerror=alert(1)>^}');
        $this->assertStringContainsString('<sup>', $sup);
        $this->assertStringNotContainsString('<img', $sup);
    }

    public function testAnsiStripsTerminalEscapeBytes(): void
    {
        $out = $this->ansi("hi \x1b[31mX\x1b[0m \x07 there");
        $this->assertStringNotContainsString("\x1b[31m", $out);
        $this->assertStringNotContainsString("\x07", $out);
        $this->assertStringContainsString('there', $out);
    }

    public function testHtmlImportDropsEventHandlers(): void
    {
        $carve = (new HtmlToCarve())->convert('<a href="https://e.com" onerror="alert(1)" onfocus="x">y</a>');
        $this->assertStringNotContainsString('onerror', $carve);
        $this->assertStringNotContainsString('onfocus', $carve);
    }
}
