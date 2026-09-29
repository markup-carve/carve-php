<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A bracket run whose `(` tail fails to parse writes `[` and `](` as literal text,
 * and both are placed.
 *
 * Each is a contiguous slice of the source, so PART 12 §4's exemption for a node the
 * producer REASSEMBLED does not reach them: `docs/ast-json.md` narrows it to nodes that
 * cannot be placed, not nodes that have not been. An unplaced run also swallows the
 * placed ones beside it, because the text-run coalescer returns no span for a run
 * holding one - which left the paragraph starting at the label's first character
 * instead of at the `[` that opens it (carve-php#2713). A paragraph begins at the
 * markup that opens the construct (markup-carve/carve#913).
 *
 * Every span below was measured against carve-js `c5df77f6`, which places both, over
 * this set plus the six documents of markup-carve/carve corpus category 523.
 */
class ADecliningInlineLinkBracketRunIsPlacedTest extends TestCase
{
    /**
     * @return array<string, array{string, array<int, array{string, string, int, int}>}>
     */
    public static function shapes(): array
    {
        return [
            // The paragraph's own start is the second half of the bug: it opens at
            // the `[`, offset 0, not at the label's `a`.
            'a declining tail' => [
                "[a](/u t\n",
                [['paragraph', '', 0, 8], ['text', '[a](/u t', 0, 8]],
            ],
            'an empty label' => [
                "[](/u t\n",
                [['paragraph', '', 0, 7], ['text', '[](/u t', 0, 7]],
            ],
            'a quote in the tail, which is not needed to reach it' => [
                "[a](/u \"t\n",
                [['paragraph', '', 0, 9], ['text', '[a](/u ', 0, 7], ['smart_punctuation', '"', 7, 8]],
            ],
            'nested brackets in the label' => [
                "[a [b] c](/u t\n",
                [['paragraph', '', 0, 14], ['text', '[a [b] c](/u t', 0, 14]],
            ],
            'two declining runs' => [
                "[a](/u t [b](/v w\n",
                [['paragraph', '', 0, 17], ['text', '[a](/u t [b](/v w', 0, 17]],
            ],
            'inside a block quote' => [
                "> [a](/u t\n",
                [['block_quote', '', 0, 10], ['paragraph', '', 2, 10], ['text', '[a](/u t', 2, 10]],
            ],
            // Emphasis inside the label breaks the run, so the literals are placed
            // on their own rather than merged.
            'emphasis in the label' => [
                "[/a/](/u t\n",
                [
                    ['paragraph', '', 0, 10],
                    ['text', '[', 0, 1],
                    ['emphasis', '', 1, 4],
                    ['text', 'a', 2, 3],
                    ['text', '](/u t', 4, 10],
                ],
            ],
            // Controls: these already placed every node.
            'a bracket run with no tail' => [
                "[a] \"t\n",
                [['paragraph', '', 0, 6], ['text', '[a] ', 0, 4]],
            ],
            'a tail with no bracket run' => [
                "(/u \"t\n",
                [['paragraph', '', 0, 6], ['text', '(/u ', 0, 4]],
            ],
            // A space in the destination refuses the link too, and the whole run is
            // one placed text node either way.
            'a closed tail whose destination holds a space' => [
                "[a](/u t)x\n",
                [['paragraph', '', 0, 10], ['text', '[a](/u t)x', 0, 10]],
            ],
            'a link that parses' => [
                "[a](/u)x\n",
                [['paragraph', '', 0, 8], ['link', '', 0, 7], ['text', 'a', 1, 2]],
            ],
        ];
    }

    /**
     * @param string $source
     * @param array<int, array{string, string, int, int}> $expected
     *
     * @return void
     */
    #[DataProvider('shapes')]
    public function testEveryNodeCarriesItsSpan(string $source, array $expected): void
    {
        $nodes = $this->flatten($this->encode($source));

        foreach ($expected as $index => [$type, $value, $start, $end]) {
            $this->assertArrayHasKey($index, $nodes, 'missing node ' . $index . ' of ' . $type);
            $node = $nodes[$index];
            $this->assertSame($type, $node['type']);
            if ($value !== '') {
                $this->assertSame($value, $node['value'] ?? null);
            }
            $this->assertArrayHasKey('pos', $node, $type . ' carries no position');
            $this->assertSame($start, $node['pos']['startOffset'], $type . ' startOffset');
            $this->assertSame($end, $node['pos']['endOffset'], $type . ' endOffset');
            $this->assertSame($start + 1, $node['pos']['startColumn'], $type . ' startColumn');
        }
    }

    /**
     * Nothing below the document root may go unplaced on any of these shapes.
     *
     * @param string $source
     * @param array<int, array{string, string, int, int}> $expected
     *
     * @return void
     */
    #[DataProvider('shapes')]
    public function testNothingBelowTheRootGoesUnplaced(string $source, array $expected): void
    {
        $unplaced = [];
        foreach ($this->flatten($this->encode($source)) as $node) {
            if (!isset($node['pos'])) {
                $unplaced[] = $node['type'];
            }
        }

        $this->assertSame([], $unplaced);
    }

    /**
     * @param string $source
     *
     * @return array<string, mixed>
     */
    protected function encode(string $source): array
    {
        $converter = new CarveConverter(parser: new BlockParser(false, false, false, true));

        return (new AstCodec())->encode($converter->parse($source));
    }

    /**
     * Every node below the root, in document order.
     *
     * @param array<string, mixed> $tree
     *
     * @return array<int, array<string, mixed>>
     */
    protected function flatten(array $tree): array
    {
        $flat = [];
        $walk = function (mixed $node) use (&$walk, &$flat): void {
            if (is_array($node) && isset($node['type'])) {
                $flat[] = $node;
            }
            if (!is_array($node)) {
                return;
            }
            foreach ($node as $key => $value) {
                if ($key === 'pos' || $key === 'type' || $key === 'value') {
                    continue;
                }
                $walk($value);
            }
        };
        foreach ($tree['children'] ?? [] as $child) {
            $walk($child);
        }

        return $flat;
    }
}
