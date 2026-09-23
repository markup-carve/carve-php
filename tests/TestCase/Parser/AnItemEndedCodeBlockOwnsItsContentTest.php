<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A fence the item holding it never closes is closed by the item itself, and
 * the item does that by appending a closer the author never wrote. That line
 * maps to no source line, the end of the block resolved through it, and the
 * stamp fell back to the opener - so the block spanned its own fence line only,
 * with its content on the line below it, outside its own span (carve-php#2251).
 *
 * The node's own `content` falling outside the node's own `pos` is the cleanest
 * statement of the bug, and it is asserted as its own rule below: no offset
 * table is needed to see that a block cannot both own `b` and end before it.
 *
 * PART 12 §4 puts a span's end "immediately after the last source codepoint the
 * construct owns". The construct owns `b`. carve-js and carve-rs both end there,
 * and the `list` and `list_item` above follow them in.
 */
class AnItemEndedCodeBlockOwnsItsContentTest extends TestCase
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
     * The three documents `npm run ast:check` reports, with the extents
     * carve-js and carve-rs agree on.
     *
     * @return array<string, array{string, array<int>, array<int>}>
     */
    public static function documentProvider(): array
    {
        return [
            // corpus 276-a-fence-opened-on-a-list-marker-line-body-below-the-content-column-7
            'below a paragraph' => [
                "- a\n  ```\n  b\n y\n  ```\n",
                [6, 13],
                [0, 13],
            ],
            // corpus 476-an-item-s-fence-is-read-once-whatever-block-it-follows
            'below a figure' => [
                "- ![a](i)\n  ^ cap\n  ```\n  b\n y\n  ```\n",
                [20, 27],
                [0, 27],
            ],
            // corpus 476-...-whatever-block-it-follows-2
            'below a quote' => [
                "- > q\n  ```\n  b\n y\n  ```\n",
                [8, 15],
                [0, 15],
            ],
        ];
    }

    /**
     * The invariant the bug violates, stated without any offset at all.
     *
     * @param string $source
     * @param array<int> $block
     * @param array<int> $item
     */
    #[DataProvider('documentProvider')]
    public function testTheBlocksContentLiesInsideItsOwnSpan(
        string $source,
        array $block,
        array $item,
    ): void {
        unset($block, $item);
        $blocks = $this->collect($this->publish($source), ['code_block']);

        $this->assertCount(1, $blocks);
        [$start, $end] = $this->extent($blocks[0]);
        $slice = substr($source, $start, $end - $start);

        $this->assertStringContainsString(
            (string)$blocks[0]['content'],
            $slice,
            'the code block reports content its own span does not cover',
        );
    }

    /**
     * @param string $source
     * @param array<int> $block
     * @param array<int> $item
     */
    #[DataProvider('documentProvider')]
    public function testTheBlockEndsAfterTheLastCodepointItOwns(
        string $source,
        array $block,
        array $item,
    ): void {
        unset($item);
        $blocks = $this->collect($this->publish($source), ['code_block']);

        $this->assertCount(1, $blocks);
        $this->assertSame($block, $this->extent($blocks[0]));
    }

    /**
     * The list and the item stop where the block they hold stops.
     *
     * @param string $source
     * @param array<int> $block
     * @param array<int> $item
     */
    #[DataProvider('documentProvider')]
    public function testTheListAndItsItemFollowTheBlockIn(
        string $source,
        array $block,
        array $item,
    ): void {
        unset($block);
        $published = $this->publish($source);

        foreach ($this->collect($published, ['list', 'list_item']) as $container) {
            $this->assertSame(
                $item,
                $this->extent($container),
                $container['type'] . ' does not reach the block it holds',
            );
        }
    }

    /**
     * A fence the author DID close is unaffected: its closer is a real source
     * line, so the end was never resolved through a synthesized one.
     */
    public function testAClosedFenceInAnItemIsUnchanged(): void
    {
        $source = "- a\n  ```\n  b\n  ```\n";
        $blocks = $this->collect($this->publish($source), ['code_block']);

        $this->assertCount(1, $blocks);
        $this->assertSame([6, 19], $this->extent($blocks[0]));
    }
}
