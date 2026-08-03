<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * An empty `{}` is not a block-attribute block, so a line containing one is not
 * a block-attribute line - in any position.
 *
 * `parseSingleLineBlockAttributePayload` joined the blocks' payloads with a
 * space, so `{}` contributed an empty string and vanished in the join: `{}{x}`
 * became the payload `x` and the line was consumed. Standalone that dropped the
 * whole document, because there was no block to attach the attributes to
 * (carve-php#638).
 *
 * The rule was already stated in `tryParseBlockAttributes`:
 *
 *   "A bare `{}` line is NOT a block-attribute block (block_attributes needs
 *    >= 1 attribute, no block-level blessed-empty); it stays a literal
 *    paragraph, matching carve-js / carve-rs."
 *
 * It was only enforced for a line that is exactly `{}`.
 */
class EmptyAttributeBlockMergeTest extends TestCase
{
    protected CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    public function testAnEmptyLeadingBlockMakesTheLineLiteral(): void
    {
        $this->assertSame(
            "<p>{}{x}\ntext</p>\n",
            $this->converter->convert("{}{x}\ntext\n"),
        );
    }

    public function testStandaloneItDoesNotDropTheDocument(): void
    {
        // The sharp end: with no block to attach to, the consumed line rendered
        // nothing at all and the document came out empty.
        $this->assertSame("<p>{}{x}</p>\n", $this->converter->convert("{}{x}\n"));
    }

    public function testAnEmptyTrailingBlockAlsoMakesTheLineLiteral(): void
    {
        $this->assertSame(
            "<p>{x}{}\ntext</p>\n",
            $this->converter->convert("{x}{}\ntext\n"),
        );
    }

    public function testAnEmptyBlockInTheMiddleAlsoMakesTheLineLiteral(): void
    {
        $this->assertSame(
            "<p>{x}{}{y}\ntext</p>\n",
            $this->converter->convert("{x}{}{y}\ntext\n"),
        );
    }

    public function testAdjacentNonEmptyBlocksStillMerge(): void
    {
        // The fix must not widen: corpus 112 pins that adjacent blocks merge.
        $this->assertSame(
            "<p a=\"\" x=\"\">text</p>\n",
            $this->converter->convert("{a}{x}\ntext\n"),
        );
    }

    public function testABareEmptyBlockIsStillLiteral(): void
    {
        // Corpus 123, unchanged.
        $this->assertSame("<p>{}</p>\n", $this->converter->convert("{}\n"));
    }

    public function testASingleValidBlockStillAttaches(): void
    {
        $this->assertSame("<p x=\"\">text</p>\n", $this->converter->convert("{x}\ntext\n"));
    }
}
