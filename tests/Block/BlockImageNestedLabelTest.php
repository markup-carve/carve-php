<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Block;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve#6460, carve#6470, C2-B-197: a nested label the inline scan rejects leaves its pair text, and a nested
 * `![` opens a bracket that is no footnote reference (C2-I-072, C2-I-078, C2-I-079, C2-I-087).
 */
final class BlockImageNestedLabelTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function lines(): array
    {
        return [
            'at-sign label' => ["![[a][@[]]](u)\n", '<img src="u" alt="[a][@[]]">'],
            'collapsed label of a blank pair' => ["![[ ][][[x]](u)\n", '<img src="u" alt="[ ][][[x]">'],
            'blank full label' => ["![[a][ ][[x]](u)\n", '<img src="u" alt="[a][ ][[x]">'],
            'caret label' => ["![[a][^x]](u)\n", '<img src="u" alt="[a][^x]">'],
            'unclosed label' => ["![[a][](u)\n", '<p>![[a][](u)</p>'],
            'keyed full label' => ["![[a][b]](u)\n", '<img src="u" alt="[a][b]">'],
            'keyed collapsed label' => ["![[a][]](u)\n", '<img src="u" alt="[a][]">'],
            'nested image with an empty caret label' => ["![[![^]]](u)\n", '<img src="u" alt="[![^]]">'],
            'nested image with a blank caret label' => ["![![^ ]](u)\n", '<img src="u" alt="![^ ]">'],
            'escaped image opener' => ["![a\\![^]](u)\n", '<p>![a![^]](u)</p>'],
        ];
    }

    #[DataProvider('lines')]
    public function testLine(string $source, string $html): void
    {
        $this->assertSame($html, Carve::toHtml($source));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function roleLines(): array
    {
        $lines = [
            '> ![[a][@x]](u)',
            '- ![[ ][]][k]',
            '![[a][ ]](u){.c}',
            '![[a][@[b]]][]',
            '1. ![[[[![^]][`[`][^x]][k]](u "t")',
            '![![^]^[note]](u "t")',
            '![x y![^]][^x]](u "t")',
        ];

        return array_combine($lines, array_map(static fn (string $line): array => [$line], $lines));
    }

    /**
     * The role is `image` exactly where the paragraph's inline content is one image.
     */
    #[DataProvider('roleLines')]
    public function testRoleMatchesInlineParse(string $line): void
    {
        $paragraph = Carve::parse($line . "\n\n[k]: /u\n\n[^x]: x\n")->children[0] ?? null;
        while ($paragraph !== null && $paragraph->type !== 'paragraph') {
            $paragraph = $paragraph->children[0] ?? null;
        }
        $this->assertNotNull($paragraph);
        $children = $paragraph->children ?? [];
        $only = count($children) === 1 && $children[0]->type === 'image';

        $this->assertSame($only, ($paragraph->f['role'] ?? null) === 'image');
    }
}
