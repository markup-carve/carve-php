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
 * carve-js decides the glued form of a pair in every write of inlines and shares the writer's forms with its probes.
 * The outputs are those of carve-js `writeTreeWithStats(..., 'repair')`.
 */
final class ProbeGlueTest extends TestCase
{
    /**
     * A probe writes a strong behind its word stand-in, which braces it for the rest of the round: its closer then
     * reads `*}`, the comparison resumes behind the span, and the `{` before the next span stays bare.
     *
     * @return void
     */
    public function testProbeBracesAStrongForTheRound(): void
    {
        $strong = ['type' => 'strong', 'children' => [['type' => 'text', 'value' => '[']]];
        $inner = [
            'type' => 'span',
            'attrs' => ['keyValues' => [['k', 'w x']]],
            'children' => [$strong, ['type' => 'text', 'value' => ' ']],
        ];
        $paragraph = [
            'type' => 'paragraph',
            'children' => [
                ['type' => 'span', 'attrs' => ['keyValues' => [['k', 'v']]], 'children' => [$inner, ['type' => 'text', 'value' => '{']]],
                ['type' => 'span', 'attrs' => ['keyValues' => [['k', 'v']]], 'children' => [['type' => 'text', 'value' => '[']]],
            ],
        ];

        $this->assertSame('[[*\\[* ]{k="w x"}{]{k=v}[\\[]{k=v}', self::repaired($paragraph));
    }

    /**
     * A probe across the closer of the first strong writes the second one inside a strong, which braces it anyway for
     * the writer: when a `*` of its content closes it early, it keeps the bare form and the `*` takes the backslash.
     *
     * @return void
     */
    public function testProbeMarksAStrongBracedAnyway(): void
    {
        $paragraph = [
            'type' => 'paragraph',
            'children' => [
                ['type' => 'strong', 'children' => [['type' => 'text', 'value' => '` ']]],
                ['type' => 'strong', 'children' => [['type' => 'text', 'value' => '`b*+']]],
            ],
        ];

        $this->assertSame('{*\\` *}*\\`b\\*+*', self::repaired($paragraph));
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
