<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\TestCase;

/**
 * `fmt` must never write a heading whose text carries a line break: a heading
 * ENDS AT THE NEWLINE (PART 2), so emitting one would close the heading and
 * re-parse the remainder as a following block, moving text out of the title.
 *
 * No parse builds such a heading. An ingested AST can - PART 12 lets any inline
 * sit in a heading, break nodes included - so the writer collapses the break.
 * Matches carve-js and carve-rs.
 */
class FmtHeadingSingleLineTest extends TestCase
{
    /**
     * @param array<array<string, mixed>> $children
     */
    private function fmt(array $children): string
    {
        $document = (new AstCodec())->decode([
            'type' => 'document',
            'srcByteLength' => 0,
            'children' => [
                ['type' => 'heading', 'level' => 1, 'children' => $children],
            ],
        ]);

        return (new CarveRenderer())->render($document);
    }

    public function testASoftBreakInAnIngestedHeadingCollapsesToASpace(): void
    {
        $out = $this->fmt([
            ['type' => 'text', 'value' => 'a'],
            ['type' => 'soft_break'],
            ['type' => 'text', 'value' => 'b'],
        ]);

        $this->assertSame("# a b\n", $out);
        // The point of the collapse: re-parsing keeps every word in the title.
        $this->assertSame(
            "<section id=\"a-b\">\n  <h1>a b</h1>\n</section>\n",
            (new CarveConverter())->convert($out),
        );
    }

    public function testAHardBreakInAnIngestedHeadingCollapsesToo(): void
    {
        $out = $this->fmt([
            ['type' => 'text', 'value' => 'a'],
            ['type' => 'hard_break'],
            ['type' => 'text', 'value' => 'b'],
        ]);

        $this->assertSame("# a b\n", $out);
        $this->assertSame(
            "<section id=\"a-b\">\n  <h1>a b</h1>\n</section>\n",
            (new CarveConverter())->convert($out),
        );
    }

    public function testALiteralBackslashBeforeTheBreakSurvivesTheCollapse(): void
    {
        // Only an ODD run of backslashes is a hard break's marker. Dropping one
        // unconditionally wrote an escape that swallowed the space, and the
        // author's backslash disappeared on re-parse.
        $out = $this->fmt([
            ['type' => 'text', 'value' => 'a\\'],
            ['type' => 'soft_break'],
            ['type' => 'text', 'value' => 'b'],
        ]);

        $this->assertSame("# a\\\\ b\n", $out);
        $this->assertSame(
            "<section id=\"a-b\">\n  <h1>a\\ b</h1>\n</section>\n",
            (new CarveConverter())->convert($out),
        );
    }
}
