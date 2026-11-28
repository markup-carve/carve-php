<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer\PlannerTrace;

use MarkupCarve\Carve\Writer\Check\Node;
use MarkupCarve\Carve\Writer\Check\Passes;
use MarkupCarve\Carve\Writer\Check\TreeGroupWriter;
use MarkupCarve\Carve\Writer\Check\TreeSpell;
use MarkupCarve\Carve\Writer\Check\WriterState;
use PHPUnit\Framework\TestCase;

/**
 * The escapes carve-js places while it writes (`placeEscapes`) stand in the writer state before the first round. The
 * outputs are those of carve-js `writeTreeWithStats(..., 'repair')`.
 */
final class PlacedEscapesTest extends TestCase
{
    /**
     * A backslash that ends a text before a braced strong is doubled once, and a `{` that ends the content of a strong
     * takes its backslash, so the first round reads back and the trailing `!` stays bare.
     *
     * @return void
     */
    public function testLastBackslashAndBraceAtThePairedEnd(): void
    {
        $paragraph = [
            'type' => 'paragraph',
            'children' => [
                ['type' => 'text', 'value' => '\\'],
                ['type' => 'strong', 'children' => [['type' => 'text', 'value' => '*{']]],
                ['type' => 'strong', 'children' => [['type' => 'emphasis', 'children' => [['type' => 'text', 'value' => '/']]]]],
                ['type' => 'text', 'value' => '!'],
            ],
        ];

        $this->assertSame('\\\\{**\\{*}*{///}*!', self::repaired($paragraph));
    }

    /**
     * A text that ends with `{` before a pair is written again once the pair is braced; that write leaves the escapes
     * to the rounds too, so the leading `/` stays bare until a round asks for it.
     *
     * @return void
     */
    public function testTextRewrittenBeforeABracedPair(): void
    {
        $paragraph = [
            'type' => 'paragraph',
            'children' => [
                ['type' => 'text', 'value' => '/{'],
                ['type' => 'emphasis', 'children' => [['type' => 'text', 'value' => '/.']]],
            ],
        ];

        $this->assertSame('\\/{{/\\/./}', self::repaired($paragraph));
    }

    /**
     * @param array<string, mixed> $paragraph
     */
    private static function repaired(array $paragraph): string
    {
        $document = Node::mirror(['type' => 'document', 'children' => [$paragraph]]);
        $w = new WriterState(new TreeSpell());
        $w->groupWriter = new TreeGroupWriter();
        Passes::writeGroup($w, $document, $document->children(), true);

        return implode("\n", $w->lines);
    }
}
