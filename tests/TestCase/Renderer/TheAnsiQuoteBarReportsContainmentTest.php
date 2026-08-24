<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Renderer\AnsiRenderer;
use PHPUnit\Framework\TestCase;

/**
 * markup-carve/carve#1689: the ANSI blockquote bar reports CONTAINMENT, not node
 * kind. Everything a quote contains carries it, so the ANSI reader is never told
 * a block was unquoted where the HTML says it was.
 *
 * WHY THESE FIXTURES CAN FAIL. Before the ruling the bar was applied by the
 * paragraph render method alone, so every assertion below that expects a bar on
 * a heading, a code block, a list or a promoted image failed on the previous
 * implementation, and the two-spellings-agree assertion failed because the flush
 * spelling had no bar at all while the indented one did.
 *
 * The blank-line assertion is the NEAR MISS: prefixing a quote's whole rendered
 * body indiscriminately would draw a gutter through the space between its blocks
 * and past its end. It is the one shape a naive reading of this fix would also
 * change, and it must not.
 */
class TheAnsiQuoteBarReportsContainmentTest extends TestCase
{
    protected CarveConverter $converter;

    protected AnsiRenderer $renderer;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
        $this->renderer = new AnsiRenderer();
    }

    protected function plain(string $source): string
    {
        $doc = $this->converter->parse($source);

        return (string)preg_replace('/\033\[[0-9;]*m/', '', $this->renderer->render($doc));
    }

    public function testBothSpellingsOfALoneQuotedImageGetTheSameBar(): void
    {
        // Identical HTML, different trees: `block_quote > image` against
        // `block_quote > paragraph > image`. Spec corpus category
        // 411-a-lone-indented-image-is-a-paragraph-and-its-html-cannot-say-so.
        $flush = $this->plain('> ![Apollo](a.jpg)');
        $indented = $this->plain('>   ![Apollo](a.jpg)');

        // Asserting BOTH spellings is the point of the ruling: a test on the
        // flush case alone cannot show that the two now agree.
        $this->assertSame($indented, $flush);
        $this->assertSame('│ [img: Apollo]', trim($flush));
    }

    public function testAQuotedHeadingGetsTheBarOnItsUnderlineToo(): void
    {
        $lines = array_values(array_filter(explode("\n", $this->plain('> # Heading'))));

        $this->assertCount(2, $lines);
        foreach ($lines as $line) {
            $this->assertStringStartsWith('│ ', $line);
        }
    }

    public function testAQuotedCodeBlockGetsTheBarOnEveryPayloadLine(): void
    {
        $lines = array_values(array_filter(explode("\n", $this->plain("> ```\n> alpha\n> beta\n> ```"))));

        $this->assertCount(2, $lines);
        foreach ($lines as $line) {
            $this->assertStringStartsWith('│ ', $line);
        }
        $this->assertStringContainsString('alpha', implode("\n", $lines));
    }

    public function testTheBarSitsOutsideAQuotedListMarker(): void
    {
        // The old design prefixed the item's PARAGRAPH, so the bullet - added by
        // the list renderer afterwards - landed to the LEFT of the bar and the
        // output read `• │ item`. Containment puts the quote outermost.
        $output = $this->plain('> - item');

        $this->assertStringStartsWith('│ ', $output);
        $this->assertLessThan(strpos($output, '•'), strpos($output, '│'));
    }

    public function testNestedQuotesComposeOneBarPerLevel(): void
    {
        $this->assertStringStartsWith('│ │ ', $this->plain('> > nested'));
    }

    public function testTheBlankLineBetweenTwoQuotedBlocksStaysBare(): void
    {
        // Near miss: the shape a naive "prefix the whole body" fix would also
        // change. A bar here would draw a gutter through the gap and past the end.
        $lines = explode("\n", $this->plain("> one\n>\n> two"));
        $barred = array_filter($lines, static fn (string $l): bool => str_starts_with($l, '│ '));

        $this->assertCount(2, $barred);
        foreach ($lines as $line) {
            if (!str_starts_with($line, '│ ')) {
                $this->assertSame('', $line);
            }
        }
    }

    public function testAnUnquotedHeadingAndCodeBlockHaveNoBarAtAll(): void
    {
        // Control: the bar tracks containment, so outside a quote there is none.
        $this->assertStringNotContainsString('│', $this->plain('# Heading'));
        $this->assertStringNotContainsString('│', $this->plain("```\ncode\n```"));
    }
}
