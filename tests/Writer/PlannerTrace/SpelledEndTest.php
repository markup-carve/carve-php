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
 * The comparison steps over a construct by the written length of the text it stands in, which counts the backslashes
 * of C2-W-097 (carve-js `spelledEnd`). The outputs are those of carve-js `writeTreeWithStats(..., 'repair')`.
 */
final class SpelledEndTest extends TestCase
{
    /**
     * A text that reads back as a code span holds a backslash the writer doubles: the comparison resumes behind the
     * span, so `=}` stays text and takes no backslash.
     *
     * @return void
     */
    public function testDoubledBackslashInsideASteppedOverSpan(): void
    {
        $this->assertSame('\\`\\\\\\`=}', self::repaired('`\\`=}'));
    }

    private static function repaired(string $value): string
    {
        $document = Node::mirror([
            'type' => 'document',
            'children' => [
                ['type' => 'paragraph', 'children' => [['type' => 'text', 'value' => $value]]],
            ],
        ]);
        $w = new WriterState(new TreeSpell());
        $w->groupWriter = new TreeGroupWriter();
        Passes::writeGroup($w, $document, $document->children(), true);

        return implode("\n", $w->lines);
    }
}
