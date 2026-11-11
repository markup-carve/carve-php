<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A verse line whose tabbed lead the ownership walk respelled as spaces keeps the
 * authored bytes behind its lead and text (PART 12 §4, CARVE-P12-004, carve-php#5151).
 */
final class RespelledVerseLeadPositionTest extends TestCase
{
    /**
     * @return array<string, array{string, list<array{string, array<int>|null}>}>
     */
    public static function sources(): array
    {
        return [
            '1042-a-quoted-nested-item-keeps-three-verse-spaces' => [
                "> - a\n>   - a\n>   \t::: |\n>     \t z\n>   \t:::\n",
                [
                    ['paragraph', [30, 34]],
                    ['non_breaking_space', [30, 31]],
                    ['non_breaking_space', [31, 32]],
                    ['non_breaking_space', [32, 33]],
                    ['text', [33, 34]],
                ],
            ],
            '1043-a-second-tab-keeps-two-verse-spaces' => [
                "> - a\n>   - a\n>   \t::: |\n> \t\tz\n>   \t:::\n",
                [
                    ['paragraph', [27, 30]],
                    ['non_breaking_space', [27, 28]],
                    ['non_breaking_space', [28, 29]],
                    ['text', [29, 30]],
                ],
            ],
            '1044-a-nested-item-without-a-quote-keeps-three-verse-spaces' => [
                "- a\n  - a\n  \t::: |\n    \t z\n  \t:::\n",
                [
                    ['paragraph', [22, 26]],
                    ['non_breaking_space', [22, 23]],
                    ['non_breaking_space', [23, 24]],
                    ['non_breaking_space', [24, 25]],
                    ['text', [25, 26]],
                ],
            ],
            '1071-a-tabbed-verse-line-keeps-the-lead-its-frames-leave' => [
                "- a\n  ::: |\n  \tx\n  :::\n",
                [
                    ['paragraph', [14, 16]],
                    ['non_breaking_space', null],
                    ['non_breaking_space', null],
                    ['non_breaking_space', null],
                    ['non_breaking_space', null],
                    ['text', [15, 16]],
                ],
            ],
            '1071-a-tabbed-verse-line-keeps-the-lead-its-frames-leave-2' => [
                "- a\n  ::: |\n      x\n  :::\n",
                [
                    ['paragraph', [14, 19]],
                    ['non_breaking_space', [14, 15]],
                    ['non_breaking_space', [15, 16]],
                    ['non_breaking_space', [16, 17]],
                    ['non_breaking_space', [17, 18]],
                    ['text', [18, 19]],
                ],
            ],
            '1071-a-tabbed-verse-line-keeps-the-lead-its-frames-leave-3' => [
                ":: t\n: d\n  ::: |\n  \tx\n  :::\n",
                [
                    ['paragraph', [19, 21]],
                    ['non_breaking_space', null],
                    ['non_breaking_space', null],
                    ['non_breaking_space', null],
                    ['non_breaking_space', null],
                    ['text', [20, 21]],
                ],
            ],
            '1071-a-tabbed-verse-line-keeps-the-lead-its-frames-leave-4' => [
                "> - a\n>   ::: |\n>   \tx\n>   :::\n",
                [
                    ['paragraph', [20, 22]],
                    ['non_breaking_space', null],
                    ['non_breaking_space', null],
                    ['non_breaking_space', null],
                    ['non_breaking_space', null],
                    ['text', [21, 22]],
                ],
            ],
            '1073-a-verse-lead-under-an-indented-opener-is-measured-per-frame' => [
                "- a\n   ::: |\n  \tx\n   :::\n",
                [
                    ['paragraph', [13, 17]],
                    ['non_breaking_space', [13, 14]],
                    ['non_breaking_space', [14, 15]],
                    ['non_breaking_space', [15, 16]],
                    ['text', [16, 17]],
                ],
            ],
            '1073-a-verse-lead-under-an-indented-opener-is-measured-per-frame-2' => [
                "- a\n   ::: |\n    \tx\n   :::\n",
                [
                    ['paragraph', [15, 19]],
                    ['non_breaking_space', [15, 16]],
                    ['non_breaking_space', [16, 17]],
                    ['non_breaking_space', [17, 18]],
                    ['text', [18, 19]],
                ],
            ],
            '1073-a-verse-lead-under-an-indented-opener-is-measured-per-frame-3' => [
                "- a\n  \t::: |\n    \tx\n  \t:::\n",
                [
                    ['paragraph', [18, 19]],
                    ['text', [18, 19]],
                ],
            ],
            '1073-a-verse-lead-under-an-indented-opener-is-measured-per-frame-4' => [
                "> - a\n>    ::: |\n>   \tx\n>    :::\n",
                [
                    ['paragraph', [19, 23]],
                    ['non_breaking_space', [19, 20]],
                    ['non_breaking_space', [20, 21]],
                    ['non_breaking_space', [21, 22]],
                    ['text', [22, 23]],
                ],
            ],
        ];
    }

    /**
     * @param string $source
     * @param list<array{string, array<int>|null}> $expected
     */
    #[DataProvider('sources')]
    public function testTheVerseParagraphKeepsItsAuthoredSpans(string $source, array $expected): void
    {
        $lineBlock = self::lineBlock((new AstCodec())->encode((new BlockParser(false, false, false, true))->parse($source)));
        self::assertNotNull($lineBlock);
        $paragraph = $lineBlock['children'][0];
        $actual = [[$paragraph['type'], self::offsets($paragraph)]];
        foreach ($paragraph['children'] as $child) {
            $actual[] = [$child['type'], self::offsets($child)];
        }

        self::assertSame($expected, $actual);
    }

    /**
     * @param array<string, mixed> $node
     *
     * @return array<int>|null
     */
    private static function offsets(array $node): ?array
    {
        return isset($node['pos']) ? [$node['pos']['startOffset'], $node['pos']['endOffset']] : null;
    }

    /**
     * @param array<string, mixed> $node
     *
     * @return array<string, mixed>|null
     */
    private static function lineBlock(array $node): ?array
    {
        if (($node['type'] ?? null) === 'line_block') {
            return $node;
        }
        foreach ($node as $key => $value) {
            if ($key === 'pos' || !is_array($value)) {
                continue;
            }
            foreach (array_is_list($value) ? $value : [$value] as $child) {
                if (is_array($child) && ($found = self::lineBlock($child)) !== null) {
                    return $found;
                }
            }
        }

        return null;
    }
}
