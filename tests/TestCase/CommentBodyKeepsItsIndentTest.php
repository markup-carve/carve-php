<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\Comment;
use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\TestCase;

/**
 * A comment body is verbatim text, indentation included.
 *
 * The joined body ran through `trim()`, which ate the FIRST line's leading
 * whitespace - only the first, because every later line kept the indent that
 * the join placed after a newline. A comment whose body line is indented two
 * columns parsed to a content of `x` here and to a content of two spaces plus
 * `x` in carve-js and carve-rs: a cross-engine AST difference, and a
 * `carve fmt` round trip that silently moved the author's line one column
 * left (carve#653).
 *
 * The body still loses leading and trailing BLANK lines, which all three do.
 */
class CommentBodyKeepsItsIndentTest extends TestCase
{
    protected function comment(string $source): Comment
    {
        $document = (new BlockParser())->parse($source);
        foreach ($document->getChildren() as $child) {
            if ($child instanceof Comment) {
                return $child;
            }
        }

        $this->fail('no comment node in ' . var_export($source, true));
    }

    public function testTheFirstBodyLineKeepsItsIndent(): void
    {
        $this->assertSame('  x', $this->comment("%%%\n  x\n%%%\n")->getContent());
    }

    public function testALaterBodyLineKeepsItsIndentToo(): void
    {
        $this->assertSame("x\n  y", $this->comment("%%%\nx\n  y\n%%%\n")->getContent());
    }

    public function testBlankLinesAroundTheBodyAreStillDropped(): void
    {
        $this->assertSame('x', $this->comment("%%%\n\nx\n\n%%%\n")->getContent());
    }

    public function testTheWriterRoundTripsAnIndentedBody(): void
    {
        $source = "%%%\n  x\n%%%\n";

        $this->assertSame($source, CarveConverter::toCarve($source));
    }
}
