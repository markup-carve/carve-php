<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\TestCase;

/**
 * PART 12 §4 requires `pos` on every node but the document root, and forbids
 * both inventing values and omitting them silently.
 *
 * The machinery landed first and nothing turned it on: `trackPositions`
 * defaults to false and no caller passed true, so `--json` published a tree
 * with no positions at all while the code to produce them sat unused.
 */
class PositionConformanceTest extends TestCase
{
    private function parseWithPositions(string $source): array
    {
        $parser = new BlockParser(false, false, false, true);

        return (new AstCodec())->encode($parser->parse($source));
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string> $missing
     * @param string $path
     */
    private function collectMissing(array $node, array &$missing, string $path = '$'): void
    {
        foreach ($node as $key => $value) {
            if ($key === 'pos' || !is_array($value)) {
                continue;
            }
            if (isset($value['type'])) {
                if (!isset($value['pos'])) {
                    $missing[] = $path . '.' . $key . ' (' . $value['type'] . ')';
                }
                $this->collectMissing($value, $missing, $path . '.' . $key);

                continue;
            }
            foreach ($value as $i => $item) {
                if (!is_array($item)) {
                    continue;
                }
                if (isset($item['type']) && !isset($item['pos'])) {
                    $missing[] = $path . '.' . $key . "[$i] (" . $item['type'] . ')';
                }
                $this->collectMissing($item, $missing, $path . '.' . $key . "[$i]");
            }
        }
    }

    public function testEveryNodeButTheRootCarriesAPosition(): void
    {
        $source = "# H\n\na *b* `c` and [l](/u)\n\n- one\n- two\n\n| a | b |\n|---|---|\n| c | d |\n";
        $missing = [];
        $this->collectMissing($this->parseWithPositions($source), $missing);

        $this->assertSame([], $missing);
    }

    public function testTheRootItselfCarriesNone(): void
    {
        // Exempt by definition: it spans the whole source.
        $this->assertArrayNotHasKey('pos', $this->parseWithPositions("a\n"));
    }

    public function testAPositionCoversTheTextItBelongsTo(): void
    {
        // The unit is CODEPOINTS (PART 12 §4). Slicing the source the same way
        // is what distinguishes codepoints from bytes or UTF-16 - they agree on
        // ASCII, so nothing catches a wrong unit without an astral character.
        $source = "\u{1F600} plain *bold* tail\n";
        $encoded = $this->parseWithPositions($source);
        $codepoints = preg_split('//u', $source, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $first = $encoded['children'][0]['children'][0];

        $slice = implode('', array_slice(
            $codepoints,
            $first['pos']['startOffset'],
            $first['pos']['endOffset'] - $first['pos']['startOffset'],
        ));

        $this->assertSame($first['value'], $slice);
    }

    public function testAHeadingAlwaysPublishesItsLevel(): void
    {
        // Level 1 is this engine's property default, so the encoder dropped it -
        // making `# H` and `## H` differ in FIELD SET rather than in value.
        $this->assertArrayHasKey('level', $this->parseWithPositions("# H\n")['children'][0]);
        $this->assertArrayHasKey('level', $this->parseWithPositions("## H\n")['children'][0]);
    }
}
