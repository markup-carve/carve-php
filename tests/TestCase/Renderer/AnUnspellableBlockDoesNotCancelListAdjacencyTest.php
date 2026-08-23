<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\TestCase;

/**
 * PART 11 §10j: an unspellable block does not cancel the adjacency it cannot
 * spell (markup-carve/carve#1621, this engine's half in carve-php#1633).
 *
 * A whitespace-only paragraph does two things at once. It is not empty, so a
 * writer tracking list adjacency treats it as a block that separates two
 * sibling lists and never writes PART 9 §11 N1a's hard boundary; and it has no
 * Carve spelling, so nothing of it reaches the page. The two lists come back
 * with ONE blank line between them and MERGE. What is lost is a document
 * boundary, not a blank line.
 *
 * HAND-BUILT, AND IT HAS TO BE. No Carve source spells a whitespace-only
 * paragraph - a lone ASCII-space line is a BLANK line - so the parse-driven
 * corpus cannot reach this tree whatever it is written to assert. It enters
 * through the AST ingest, which is also where an editor hands it back.
 */
class AnUnspellableBlockDoesNotCancelListAdjacencyTest extends TestCase
{
    /**
     * @param array<string, mixed>|null $between
     *
     * @return string
     */
    protected function written(?array $between): string
    {
        $list = static fn (string $text): array => [
            'type' => 'list',
            'ordered' => false,
            'tight' => true,
            'bulletChar' => '-',
            'items' => [
                [
                    'type' => 'list_item',
                    'children' => [['type' => 'paragraph', 'children' => [['type' => 'text', 'value' => $text]]]],
                ],
            ],
        ];
        $payload = [
            'type' => 'document',
            'srcByteLength' => 0,
            'children' => array_merge([$list('a')], $between === null ? [] : [$between], [$list('b')]),
        ];

        $document = (new AstCodec())->decodeJson(json_encode($payload) ?: '{}');

        return (new CarveRenderer())->render($document);
    }

    /**
     * @param array<string, mixed>|null $between
     *
     * @return int
     */
    protected function lists(?array $between): int
    {
        return substr_count((new CarveConverter())->convert($this->written($between)), '<ul>');
    }

    public function testTheBoundarySurvivesAWhitespaceOnlyParagraph(): void
    {
        // The shape the ruling turns on.
        $this->assertSame(2, $this->lists(['type' => 'paragraph', 'children' => [['type' => 'text', 'value' => ' ']]]));
    }

    public function testTheWriterAgreesWithItselfAboutTheEmptyParagraph(): void
    {
        // The self-contradiction that settled the clause: the writer already
        // wrote the boundary across an EMPTY paragraph, and the two trees
        // differ in nothing it can put on the page.
        $this->assertSame(2, $this->lists(['type' => 'paragraph', 'children' => []]));
        $this->assertSame(
            $this->written(['type' => 'paragraph', 'children' => []]),
            $this->written(['type' => 'paragraph', 'children' => [['type' => 'text', 'value' => ' ']]]),
        );
    }

    public function testABlockThatDoesReachThePageStillSeparatesThem(): void
    {
        // The control that separates §10j from "always write a boundary between
        // two lists". Both of these spell something, so they part the lists as
        // they always did and no boundary is needed.
        $this->assertSame(2, $this->lists(['type' => 'thematic_break']));
        $this->assertStringContainsString('---', $this->written(['type' => 'thematic_break']));

        // A NO-BREAK space is CONTENT (PART 11 §7). This row is what holds
        // the line there: counting U+00A0 as layout makes this paragraph
        // vanish from the page and the assertion below fails.
        $nbsp = ['type' => 'paragraph', 'children' => [['type' => 'text', 'value' => "\u{00A0}"]]];
        $this->assertSame(2, $this->lists($nbsp));
        $this->assertStringContainsString("\u{00A0}", $this->written($nbsp));
    }

    public function testTheBlockItselfIsStillLostAndTheLossIsBoundedToIt(): void
    {
        // §10j does not claim the paragraph survives - it claims the BOUNDARY
        // does. So the claim is exactly that: the page is the one with nothing
        // between the two lists, and the boundary is on it.
        $this->assertSame(
            $this->written(null),
            $this->written(['type' => 'paragraph', 'children' => [['type' => 'text', 'value' => ' ']]]),
        );
    }

    public function testTheControlWithNothingBetweenThem(): void
    {
        // The mechanism is present and it works. Without this, a fix that
        // simply stopped writing boundaries would look like a pass everywhere
        // else in this class.
        $this->assertSame(2, $this->lists(null));
    }
}
