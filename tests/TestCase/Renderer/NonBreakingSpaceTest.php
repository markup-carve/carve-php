<?php

declare(strict_types=1);

namespace Carve\Test\TestCase\Renderer;

use Carve\CarveConverter;
use Carve\Renderer\AnsiRenderer;
use Carve\Renderer\MarkdownRenderer;
use Carve\Renderer\PlainTextRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Non-breaking-space placeholder (U+E000) handling across renderers.
 *
 * The line-block indent and the escaped space (`\ `) share one private-use
 * sentinel. It renders as `&nbsp;` in HTML, a real non-breaking space (U+00A0)
 * in Markdown (so it survives a round-trip re-render and is not mistaken for an
 * indented code block), and an ordinary space in plain-text and ANSI output. A
 * literal U+00A0 in the author's own text is never altered.
 */
class NonBreakingSpaceTest extends TestCase
{
    /**
     * @var string
     */
    private const NBSP = "\u{00A0}";

    /**
     * @var string
     */
    private const PLACEHOLDER = "\u{E000}";

    private CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    public function testLineBlockIndentIsNbspInHtml(): void
    {
        $html = $this->converter->convert("::: |\nflush\n  indented\n:::");

        $this->assertStringContainsString("flush<br>\n&nbsp;&nbsp;indented", $html);
        $this->assertStringNotContainsString(self::PLACEHOLDER, $html);
    }

    public function testLineBlockIndentIsRealNbspInMarkdown(): void
    {
        $document = $this->converter->parse("::: |\nflush\n  indented\n:::");
        $markdown = (new MarkdownRenderer())->render($document);

        $this->assertStringContainsString(self::NBSP . self::NBSP . 'indented', $markdown);
        $this->assertStringNotContainsString(self::PLACEHOLDER, $markdown);
    }

    public function testLineBlockIndentIsOrdinarySpaceInPlainText(): void
    {
        $document = $this->converter->parse("::: |\nflush\n  indented\n:::");
        $text = (new PlainTextRenderer())->render($document);

        $this->assertStringContainsString("flush\n  indented", $text);
        $this->assertStringNotContainsString(self::PLACEHOLDER, $text);
        $this->assertStringNotContainsString(self::NBSP, $text);
    }

    public function testEscapedSpaceDoesNotLeakPlaceholderIntoNonHtml(): void
    {
        $document = $this->converter->parse('a\\ b');

        $this->assertStringContainsString('a' . self::NBSP . 'b', (new MarkdownRenderer())->render($document));
        $this->assertStringContainsString('a b', (new PlainTextRenderer())->render($document));
        $this->assertStringNotContainsString(self::PLACEHOLDER, (new AnsiRenderer())->render($document));
    }

    public function testLiteralNonBreakingSpaceIsPreservedInNonHtml(): void
    {
        $document = $this->converter->parse('ice' . self::NBSP . 'cream');

        $this->assertStringContainsString('ice' . self::NBSP . 'cream', (new PlainTextRenderer())->render($document));
        $this->assertStringContainsString('ice' . self::NBSP . 'cream', (new MarkdownRenderer())->render($document));
    }
}
