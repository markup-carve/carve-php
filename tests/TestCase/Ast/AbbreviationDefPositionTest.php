<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\TestCase;

/**
 * An `abbreviation_def` node is SYNTHESIZED at serialization from a
 * document-level map, so it had no position to carry - the parser discarded
 * where each `*[ABBR]: …` line sat when the definition entered the map
 * (carve-php#579).
 *
 * The span is recorded beside the definitions and handed to the codec directly.
 * It cannot travel through the encoded array: `ReferenceShape` keeps it off the
 * wire, which is what the schema golden asserts.
 */
class AbbreviationDefPositionTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    protected function ast(string $source): array
    {
        return (new AstCodec())->encode((new BlockParser(false, false, false, true))->parse($source));
    }

    protected function sliceOf(string $source, array $pos): string
    {
        $codepoints = preg_split('//u', $source, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return implode('', array_slice($codepoints, $pos['startOffset'], $pos['endOffset'] - $pos['startOffset']));
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function firstDef(array $ast): ?array
    {
        foreach ($ast['children'] as $child) {
            if (($child['type'] ?? '') === 'abbreviation_def') {
                return $child;
            }
        }

        return null;
    }

    public function testADefinitionSpansItsOwnLine(): void
    {
        $source = "*[HTML]: Hyper Text\n\nThe HTML spec.\n";
        $def = $this->firstDef($this->ast($source));

        $this->assertNotNull($def, 'no abbreviation_def was published');
        $this->assertArrayHasKey('pos', $def, 'a definition must carry a position');
        $this->assertSame('*[HTML]: Hyper Text', $this->sliceOf($source, $def['pos']));
    }

    /**
     * The expansion ends at newline; an indented line is a separate paragraph.
     */
    public function testAnIndentedFollowingLineIsOutsideTheDefinitionSpan(): void
    {
        $source = "*[HTML]: Hyper Text\n    Markup Language\n\nThe HTML spec.\n";
        $def = $this->firstDef($this->ast($source));

        $text = $this->sliceOf($source, $def['pos']);
        $this->assertStringContainsString('Hyper Text', $text);
        $this->assertStringNotContainsString('Markup Language', $text);
    }

    /**
     * The second definition must be placed at its OWN line, not the first's.
     */
    public function testEachDefinitionIsPlacedAtItsOwnLine(): void
    {
        $source = "*[HTML]: Hyper Text\n*[CSS]: Style Sheets\n\nThe HTML and CSS specs.\n";
        $ast = $this->ast($source);

        $spans = [];
        foreach ($ast['children'] as $child) {
            if (($child['type'] ?? '') === 'abbreviation_def') {
                $spans[$child['abbr']] = $this->sliceOf($source, $child['pos']);
            }
        }

        $this->assertSame('*[HTML]: Hyper Text', $spans['HTML'] ?? null);
        $this->assertSame('*[CSS]: Style Sheets', $spans['CSS'] ?? null);
    }

    /**
     * With tracking off there is no span, and the node is published regardless.
     */
    public function testTheDefinitionIsStillPublishedWithoutTracking(): void
    {
        $ast = (new AstCodec())->encode((new BlockParser())->parse("*[HTML]: Hyper Text\n\nThe HTML spec.\n"));
        $def = $this->firstDef($ast);

        $this->assertNotNull($def);
        $this->assertArrayNotHasKey('pos', $def);
    }
}
