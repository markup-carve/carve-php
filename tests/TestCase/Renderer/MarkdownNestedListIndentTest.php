<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Renderer\MarkdownRenderer;
use PHPUnit\Framework\TestCase;

/**
 * A nested list item is indented once, to its parent's content column.
 *
 * The writer used to indent twice: `renderList()` padded every line by the
 * list's own depth, and the parent item padded the same lines again by the
 * width of its marker. Two levels came out at four spaces, three levels at
 * ten, and ten spaces under a marker whose content column is six is an
 * indented verbatim block - the third level stopped being a list at all for
 * every reader that is not Carve itself.
 *
 * Item tightness is a separate question and deliberately untouched here: the
 * blank line these expectations carry between an item's own text and its
 * nested list is what the writer emitted before this change too.
 */
class MarkdownNestedListIndentTest extends TestCase
{
    protected CarveConverter $converter;

    protected MarkdownRenderer $renderer;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
        $this->renderer = new MarkdownRenderer();
    }

    public function testThreeLevelsIndentToTheParentContentColumn(): void
    {
        $document = $this->converter->parse("- a\n  - b\n    - c\n- d\n");

        $this->assertSame(
            "- a\n\n  - b\n\n    - c\n- d\n",
            $this->renderer->render($document),
        );
    }

    public function testAnOrderedParentIndentsToItsOwnMarkerWidth(): void
    {
        $document = $this->converter->parse("1. a\n   - b\n2. c\n");

        $this->assertSame(
            "1. a\n\n   - b\n2. c\n",
            $this->renderer->render($document),
        );
    }

    /**
     * The third level has to stay within four spaces of its parent's content
     * column. A CommonMark reader opens an indented verbatim block four columns
     * past that, so the ten spaces this used to emit stopped being a list.
     */
    public function testTheThirdLevelStaysBelowTheVerbatimThreshold(): void
    {
        $document = $this->converter->parse("- a\n  - b\n    - c\n");
        $markdown = $this->renderer->render($document);

        preg_match('/^( *)- c$/m', $markdown, $matches);

        $this->assertNotEmpty($matches, 'the third level is no longer a list item: ' . var_export($markdown, true));
        $this->assertSame(4, strlen($matches[1]));
    }

    /**
     * PART 11 section 7: a line whose only content is space or tab is emitted
     * empty. The continuation pad used to be applied to the blank line between
     * an item's text and its nested list as well.
     */
    public function testNoLineIsWhitespaceOnly(): void
    {
        $document = $this->converter->parse("- a\n\n  - b\n\n    - c\n- d\n");
        $markdown = $this->renderer->render($document);

        foreach (explode("\n", $markdown) as $number => $line) {
            $this->assertFalse(
                $line !== '' && trim($line, " \t") === '',
                'line ' . ($number + 1) . ' is whitespace-only: ' . var_export($line, true),
            );
        }
    }
}
