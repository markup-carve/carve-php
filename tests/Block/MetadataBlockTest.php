<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Block;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * C2-B-232, C2-B-233: typed metadata blocks after line 1.
 */
final class MetadataBlockTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: array<string>}>
     */
    public static function shapes(): array
    {
        return [
            'after a blank line' => ["a\n\n---toml\nk = 1\n---\n\nb\n", ['paragraph', 'frontmatter', 'paragraph']],
            'tab before the name is no opener' => ["a\n\n---\tx\nk\n---\n", ['paragraph', 'paragraph']],
            'nested list closes' => ["- a\n  - b\n\n---json\n{}\n---\n", ['list', 'frontmatter']],
            'two attribute lines above' => ["a\n\n{#m}\n{.c}\n---yaml\nk: v\n---\n", ['paragraph', 'frontmatter']],
            'inside an ordered item stays text' => ["1. a\n\n   ---yaml\n   k\n   ---\n", ['list']],
            'after a fence' => ["```\nx\n```\n\n---yaml\nk\n---\n", ['code_block', 'frontmatter']],
        ];
    }

    /**
     * @param string $source
     * @param array<string> $types
     */
    #[DataProvider('shapes')]
    public function testTopLevelTypes(string $source, array $types): void
    {
        $tree = json_decode(Carve::toJson($source), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame($types, array_column($tree['children'], 'type'));
    }

    public function testFormatKeepsTheKeyAndNamesTheFormat(): void
    {
        $source = "a\n\n{#m}\n---yaml\nk: v\n---\n";

        self::assertSame($source, Carve::format($source));
    }

    public function testAnEmDashBeforeAFormatNameIsALoss(): void
    {
        $tree = [

            'type' => 'document',
            'astVersion' => '2.0',
            'children' => [
                ['type' => 'paragraph', 'children' => [['type' => 'text', 'value' => 'x']]],
                [

                    'type' => 'paragraph',
                    'children' => [
                        ['type' => 'smart_punctuation', 'kind' => 'em_dash', 'glyph' => "\u{2014}", 'source' => '---'],
                        ['type' => 'text', 'value' => 'yaml'],
                    ],
                ],
            ],
        ];
        $result = Carve::writeWithDiagnostics($tree);

        self::assertSame(
            [['structure-unspellable', '/children/1/children/0']],
            array_map(static fn ($d): array => [$d->code, $d->path], $result->diagnostics),
        );
    }
}
