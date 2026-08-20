<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\TestCase;

/**
 * PART 9 §25: past MAX_NESTING_DEPTH an opener degrades to literal text, and a
 * flattened opener is ORDINARY PARAGRAPH TEXT - so consecutive over-cap
 * openers and the text after them form ONE paragraph, ending at the first
 * blank line like any other, with no trailing newline before `</p>`.
 *
 * The degrade path handed the whole remainder to a single paragraph, which
 * kept the document's trailing newline inside it and swallowed a blank line
 * that ends a paragraph everywhere else (carve-php#702).
 */
class OverCapOpenerParagraphTest extends TestCase
{
    protected CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    /**
     * @param int $extra Openers beyond the cap.
     * @param string $tail
     */
    protected function nestedAdmonitions(int $extra, string $tail): string
    {
        return str_repeat("::: note\n", BlockParser::MAX_NESTING_DEPTH + $extra) . $tail;
    }

    public function testOverCapOpenersFormOneParagraphWithoutATrailingNewline(): void
    {
        $html = $this->converter->convert($this->nestedAdmonitions(3, 'x'));

        $this->assertStringContainsString("<p>::: note\n::: note\n::: note\nx</p>", $html);
        $this->assertStringNotContainsString("x\n</p>", $html);
    }

    public function testABlankLineEndsTheDegradedParagraph(): void
    {
        $html = $this->converter->convert($this->nestedAdmonitions(3, "x\n\ny"));

        $this->assertStringContainsString('x</p>', $html);
        $this->assertStringContainsString('<p>y</p>', $html);
        $this->assertStringNotContainsString("x\n\ny", $html);
    }

    public function testAnOverCapOpenerAloneIsStillLiteralText(): void
    {
        $html = $this->converter->convert($this->nestedAdmonitions(1, ''));

        $this->assertStringContainsString('<p>::: note</p>', $html);
    }

    public function testContentUnderTheCapIsUnaffected(): void
    {
        $html = $this->converter->convert("::: note\nx\n:::");

        $this->assertStringContainsString('<aside class="admonition note" aria-label="Note">', $html);
        $this->assertStringContainsString('<p>x</p>', $html);
    }
}
