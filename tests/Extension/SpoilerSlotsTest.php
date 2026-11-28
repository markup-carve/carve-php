<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Extension;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Extension\Spoiler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * markup-carve/carve#6983, C2-X-064: the catalog row of `carve.spoiler` declares no slots.
 */
final class SpoilerSlotsTest extends TestCase
{
    public function testInlineSpoilerCarriesNoSlots(): void
    {
        $tree = self::tree("A :spoiler[secret] word.\n");
        $blocks = $tree['children'];
        self::assertIsArray($blocks);
        self::assertIsArray($blocks[0]);

        self::assertSame(
            [['name' => 'carve.spoiler', 'required' => false, 'slots' => [], 'version' => '1']],
            $tree['extensions'],
        );
        self::assertSame(
            [
                ['type' => 'text', 'value' => 'A '],
                [
                    'children' => [],
                    'fallback' => [['type' => 'text', 'value' => 'secret']],
                    'name' => 'carve.spoiler',
                    'source' => ['body' => 'secret', 'form' => 'inline', 'name' => 'spoiler'],
                    'type' => 'inline_extension',
                    'version' => '1',
                ],
                ['type' => 'text', 'value' => ' word.'],
            ],
            $blocks[0]['children'] ?? null,
        );
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function documents(): array
    {
        return [
            'plain' => ["A :spoiler[secret] word.\n", '<p>A <span class="spoiler">secret</span> word.</p>', ''],
            'markup' => [
                "Plot :spoiler[*the* [butler](b)]{.x}.\n",
                '<p>Plot <span class="spoiler x"><strong>the</strong> <a href="b">butler</a></span>.</p>',
                '',
            ],
            'static' => [
                "A :spoiler[x `y`] word.\n",
                '<p>A <span class="spoiler spoiler-revealed">x <code>y</code></span> word.</p>',
                'static',
            ],
        ];
    }

    #[DataProvider('documents')]
    public function testRendersAndRoundTripsWithoutASlot(string $source, string $html, string $mode): void
    {
        $options = ['extensions' => [Spoiler::bundle()]] + ($mode === '' ? [] : ['mode' => $mode]);
        $json = Carve::toJson($source, ['extensions' => [Spoiler::bundle()]]);
        self::assertStringNotContainsString('"slots":{', $json);

        self::assertSame($html, trim(Carve::render($source, $options)));
        $read = Carve::decodeTree($json, ['extensions' => [Spoiler::bundle()]]);
        self::assertSame($html, trim(Carve::render($read, $options)));
        self::assertSame($source, Carve::write($read));
    }

    /**
     * @param string $source
     *
     * @return array<string, mixed>
     */
    private static function tree(string $source): array
    {
        $tree = json_decode(Carve::toJson($source, ['extensions' => [Spoiler::bundle()]]), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($tree);
        self::withoutPositions($tree);

        return $tree;
    }

    /**
     * @param array<mixed> $node
     */
    private static function withoutPositions(array &$node): void
    {
        unset($node['pos']);
        foreach ($node as &$value) {
            if (is_array($value)) {
                self::withoutPositions($value);
            }
        }
    }
}
