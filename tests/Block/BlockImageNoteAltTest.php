<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Block;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve#6452, C2-B-197: the block image line pairs brackets as the inline scan does around `[^` and `^[`
 * (C2-I-087, C2-I-089).
 */
final class BlockImageNoteAltTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function lines(): array
    {
        return [
            'label-less [^ is text' => ["![a [^](u)\n", '<img src="u" alt="a [^">'],
            'note takes no label' => ["![a ^[f][a](b])](u)\n", '<img src="u" alt="a ^[f][a](b])">'],
            'note inside a note is a bracket' => ["![aa^[^[x]]](u)\n", '<img src="u" alt="aa^[^[x]]">'],
            'label-less [^ inside a bracket' => ["![a[[^]](u)\n", '<img src="u" alt="a[[^]">'],
            'label-less [^ behind a note' => ["![^[f][^](u)\n", '<img src="u" alt="^[f][^">'],
            'empty label closes the alt early' => ["![a[^]](u)\n", '<p>![a[^]](u)</p>'],
            'superscript span' => ["![{^a^}](u)\n", '<img src="u" alt="{^a^}">'],
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
        $lines = ['![^[[^]](u)', '![^[a[^]](u)', '![^[^[[^]]][k]', '![a ^[b [^ ]](u)', '![aa[^][k]', '![a ^[f [^n] g] h](u)', '![[^ ]](u)'];

        return array_combine($lines, array_map(static fn (string $line): array => [$line], $lines));
    }

    /**
     * The role is `image` exactly where the paragraph's inline content is one image.
     */
    #[DataProvider('roleLines')]
    public function testRoleMatchesInlineParse(string $line): void
    {
        $paragraph = (Carve::parse($line . "\n\n[k]: /u\n\n[^n]: x\n")->children ?? [])[0] ?? null;
        $this->assertNotNull($paragraph);
        $children = $paragraph->children ?? [];
        $only = count($children) === 1 && $children[0]->type === 'image';

        $this->assertSame($only, ($paragraph->f['role'] ?? null) === 'image');
    }
}
