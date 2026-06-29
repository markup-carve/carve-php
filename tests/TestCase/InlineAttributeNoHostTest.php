<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * A `{...}` attribute block only attaches when it directly abuts an explicit
 * inline element host (a bracketed span, code span, link, ...). A bare word is
 * not an inline span host: the braces are ordinary literal text and their
 * content is parsed inline (grammar PART 9 §14, inline_span requires `[...]`).
 * Canonical verified against carve-js.
 *
 * Before this fix carve-php silently dropped a host-less block (consuming the
 * braces and leaving a stray space), diverging from the reference and losing
 * content. See markup-carve/carve-php#97 (list-item space form).
 */
class InlineAttributeNoHostTest extends TestCase
{
    protected CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    public function testHostlessBlockAfterWhitespaceStaysLiteral(): void
    {
        $result = $this->converter->convert("para {.c} more\n");

        $this->assertSame("<p>para {.c} more</p>\n", $result);
    }

    public function testHostlessBlockAtStartStaysLiteral(): void
    {
        $result = $this->converter->convert("{.c} text\n");

        $this->assertSame("<p>{.c} text</p>\n", $result);
    }

    public function testListItemSpaceFormBlockIsLiteralContent(): void
    {
        // markup-carve/carve-php#97 part 3: a space before the brace makes the
        // block ordinary item content, not a list-item attribute.
        $result = $this->converter->convert("- {.c} text\n");

        $this->assertSame("<ul>\n  <li>{.c} text</li>\n</ul>\n", $result);
    }

    public function testListItemHostlessBlockAfterWordWithSpace(): void
    {
        $result = $this->converter->convert("- a {.c} text\n");

        $this->assertSame("<ul>\n  <li>a {.c} text</li>\n</ul>\n", $result);
    }

    // ---- reference behavior: bare words stay literal; explicit hosts attach ----

    public function testAbuttingWordAttributeBlockStaysLiteral(): void
    {
        $result = $this->converter->convert("word{.c} x\n");

        $this->assertSame("<p>word{.c} x</p>\n", $result);
    }

    public function testBareWordAttributeValueStaysLiteralWithSmartQuotes(): void
    {
        $result = $this->converter->convert("p{data-x=\"y z\"}\n");

        $this->assertSame("<p>p{data-x=“y z”}</p>\n", $result);
    }

    public function testAbuttingBracketSpanStillAttaches(): void
    {
        $result = $this->converter->convert("[s]{.c} x\n");

        $this->assertSame("<p><span class=\"c\">s</span> x</p>\n", $result);
    }
}
