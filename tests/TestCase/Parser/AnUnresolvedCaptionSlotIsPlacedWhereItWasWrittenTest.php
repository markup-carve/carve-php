<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * An unresolved reference image keeps no caption, so the slot's lines go back
 * to the paragraph as literal text. This engine built that text and the soft
 * break before it but published neither with a `pos`, and the paragraph then
 * ended at the image (carve-php#2250).
 *
 * THIS IS NOT THE PART 12 §4 EXEMPTION. §4 permits omitting `pos` on a
 * REASSEMBLED node. Nothing here is reassembled: the given-back text is a
 * contiguous slice of one source line, verbatim, and the soft break is the line
 * ending that precedes it. carve-js and carve-rs place both at the offsets
 * below, which is the demonstration that an honest span exists rather than
 * merely not having been written down.
 *
 * THE OFFSETS ARE THE ASSERTION, not the presence of a `pos`. A test that
 * checked only for a key would pass on a span pointing at the wrong bytes -
 * markup-carve/carve#755 catalogues exactly that failure, two engines slicing
 * to the same plausible text from different places.
 *
 * The soft break reaches PAST the newline wherever a container prefix follows
 * it: in an item the break runs from the end of the marker line to where the
 * body text resumes, because the two columns of indentation belong to neither
 * the image above nor the text below.
 */
class AnUnresolvedCaptionSlotIsPlacedWhereItWasWrittenTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function publish(string $source): array
    {
        return (new AstCodec())->encode((new BlockParser(false, false, false, true))->parse($source));
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string> $types
     *
     * @return array<int, array<string, mixed>>
     */
    private function collect(array $node, array $types): array
    {
        $found = [];
        if (in_array($node['type'] ?? '', $types, true)) {
            $found[] = $node;
        }
        foreach (['children', 'items', 'rows'] as $key) {
            /** @var array<mixed> $branch */
            $branch = $node[$key] ?? [];
            foreach ($branch as $child) {
                if (is_array($child) && isset($child['type'])) {
                    $found = array_merge($found, $this->collect($child, $types));
                }
            }
        }

        return $found;
    }

    /**
     * @param array<string, mixed> $node
     *
     * @return array<int>
     */
    private function extent(array $node): array
    {
        $pos = $node['pos'] ?? null;
        $this->assertIsArray($pos, ($node['type'] ?? '?') . ' has no position');

        return [$pos['startOffset'], $pos['endOffset']];
    }

    /**
     * The four documents `npm run ast:check` reports, each with the extents
     * carve-js and carve-rs agree on, in document order per node type.
     *
     * @return array<string, array{string, array<int, array<int>>, array<int, array<int>>}>
     */
    public static function slotProvider(): array
    {
        return [
            // corpus 209-an-unresolved-reference-image-takes-no-caption
            'at the top level' => [
                "![a][nope]\n^ cap\n",
                [[10, 11]],
                [[11, 16]],
            ],
            // corpus 352-a-bracketed-construct-s-identifiers-stay-on-one-line-3
            'below an image whose label ran over two lines' => [
                "![a][r\nx]\n^ cap\n",
                [[9, 10]],
                [[10, 15]],
            ],
            // corpus 434-an-unresolved-image-gives-its-whole-caption-slot-back-at-any-depth
            'over two slot lines' => [
                "![a][r]\n^ cap one\ncontinued\n",
                [[7, 8], [17, 18]],
                [[8, 17], [18, 27]],
            ],
            // corpus 434-...-at-any-depth-3, where the unplaced text used to
            // drag the enclosing list and list_item in with the paragraph.
            'inside a list item' => [
                "- ![a][r]\n  ^ cap\n",
                [[9, 12]],
                [[12, 17]],
            ],
        ];
    }

    /**
     * @param string $source
     * @param array<int, array<int>> $breaks
     * @param array<int, array<int>> $texts
     */
    #[DataProvider('slotProvider')]
    public function testTheGivenBackLinesSitWhereTheyWereWritten(
        string $source,
        array $breaks,
        array $texts,
    ): void {
        $published = $this->publish($source);

        $this->assertSame(
            $breaks,
            array_map($this->extent(...), $this->collect($published, ['soft_break'])),
        );
        $this->assertSame(
            $texts,
            array_map($this->extent(...), $this->collect($published, ['text'])),
        );
    }

    /**
     * A span that points at plausible text elsewhere passes an offset check
     * that never reads the source, so each run is sliced back out of it.
     *
     * @param string $source
     * @param array<int, array<int>> $breaks
     * @param array<int, array<int>> $texts
     */
    #[DataProvider('slotProvider')]
    public function testEachGivenBackRunSlicesToItsOwnText(
        string $source,
        array $breaks,
        array $texts,
    ): void {
        unset($breaks);
        $runs = $this->collect($this->publish($source), ['text']);

        $this->assertCount(count($texts), $runs);
        foreach ($runs as $offset => $run) {
            [$start, $end] = $this->extent($run);
            $this->assertSame($run['value'], substr($source, $start, $end - $start), "text run {$offset}");
        }
    }

    /**
     * markup-carve/carve#913's containment invariant. The paragraph ending at
     * the image is the visible half of this bug, and every container above it
     * ended there too.
     *
     * @param string $source
     * @param array<int, array<int>> $breaks
     * @param array<int, array<int>> $texts
     */
    #[DataProvider('slotProvider')]
    public function testEveryContainerReachesThroughTheGivenBackLines(
        string $source,
        array $breaks,
        array $texts,
    ): void {
        unset($breaks);
        $published = $this->publish($source);
        $last = $texts[count($texts) - 1][1];

        $containers = $this->collect($published, ['paragraph', 'list', 'list_item']);
        $this->assertNotSame([], $containers);
        foreach ($containers as $container) {
            $this->assertSame(
                $last,
                $this->extent($container)[1],
                $container['type'] . ' stops short of the lines it owns',
            );
        }
    }

    /**
     * The resolved case is untouched: a caption whose image resolves is a
     * figure, with no given-back text to place.
     */
    public function testAResolvedReferenceStillBuildsAFigure(): void
    {
        $published = $this->publish("![a][r]\n^ cap\n\n[r]: /i\n");

        $this->assertCount(1, $this->collect($published, ['figure']));
        $this->assertSame([], $this->collect($published, ['soft_break']));
    }
}
