<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Block;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Test\Support\Corpus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * C2-B-232, C2-B-233: typed metadata blocks after line 1.
 */
final class MetadataBlockTest extends TestCase
{
    /**
     * @var array<string>
     */
    private const PINNED = [
        'CC-1585', 'CC-1613', 'CC-1653', 'CC-1654', 'CC-1655', 'CC-1656', 'CC-1657', 'CC-1658', 'CC-1659', 'CC-1660',
        'CC-1661', 'CC-1666', 'CC-1667', 'CC-1668', 'CC-1669', 'CC-1670', 'CC-1671', 'CC-1672',
    ];

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
            'lazy line of an item paragraph' => ["- a\n---yaml\nk\n---\n", ['list']],
            'lazy line of a nested item paragraph' => ["- a\n  - b\n---yaml\nk\n---\n", ['list']],
            'list ending in a fence closes' => ["- ```\n  x\n  ```\n---yaml\nk\n---\n", ['list', 'paragraph']],
            'after a list and a blank line' => ["- a\n\n---yaml\nk\n---\n", ['list', 'frontmatter']],
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

    /**
     * C2-W-154 (ruling on carve#6389): the first line is indented by one space instead of a loss report.
     */
    public function testAnEmDashBeforeAFormatNameIsIndented(): void
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

        self::assertSame("x\n\n ---yaml\n", $result->source);
        self::assertSame([], $result->diagnostics);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function indentedLeads(): array
    {
        return [
            'below a paragraph' => ["a\n\n ---yaml\nk: v\n---\n"],
            'first block' => [" ---yaml\nk: v\n---\n"],
            'below a heading' => ["= H\n\n ---toml\nx\n"],
            'below attribute lines' => ["a\n\n{.x}\n ---yaml\n"],
            'two in a row' => ["x\n\n ---yaml\n\n ---json\n"],
            'lazy line in a list' => ["- a\n  ---yaml\n"],
            'below a list ending in a quote that ends in a fence' => ["- > ```\n  > x\n  > ```\n---yaml\nk: v\n---\n"],
            'below a footnote ending in a div' => ["a[^n]\n\n[^n]: x\n\n  ::: d\n  y\n  :::\n---yaml\nk: v\n---\n"],
            'below a footnote ending in a heading' => ["a[^n]\n\n[^n]: x\n\n  = H\n---json\n{}\n---\n"],
            'below a description ending in a table' => [":: t\n: | a |\n---toml\nk = 1\n---\n"],
            'below a description ending in a nested list with a fence' => [":: t\n: - ```\n    y\n    ```\n---yaml\nk\n---\n"],
        ];
    }

    #[DataProvider('indentedLeads')]
    public function testIndentedLeadFormatsToItself(string $source): void
    {
        self::assertSame($source, Carve::format($source));
    }

    /**
     * The format and parse cases of the rulings on carve#6389 and carve#6394, read from the pinned spec.
     */
    public function testPinnedCases(): void
    {
        $spec = json_decode(
            (string)file_get_contents(__DIR__ . '/../../spec/conformance/clause-cases.json'),
            true,
            4096,
            JSON_THROW_ON_ERROR,
        );
        $seen = 0;
        foreach ($spec['cases'] as $case) {
            if (!in_array($case['id'], self::PINNED, true)) {
                continue;
            }
            $seen++;
            $formatted = Carve::format($case['input']);
            if (isset($case['expected']['carve'])) {
                self::assertSame($case['expected']['carve'], $formatted, $case['id']);
            }
            self::assertSame(self::tree($case['input']), self::tree($formatted), $case['id'] . ' read-back');
        }
        self::assertSame(count(self::PINNED), $seen);
    }

    /**
     * @return array<string, array{0: array<mixed>}>
     */
    public static function lazyListEnds(): array
    {
        $paragraph = ['type' => 'paragraph', 'children' => [['type' => 'text', 'value' => 'q']]];

        return [
            'paragraph' => [$paragraph],
            'quote' => [['type' => 'block_quote', 'children' => [$paragraph]]],
        ];
    }

    /**
     * C2-W-154: below a list that ends in a block taking lazy lines, the paragraph has no spelling.
     *
     * @param array<mixed> $end
     */
    #[DataProvider('lazyListEnds')]
    public function testNoSpellingBelowALazyListEnd(array $end): void
    {
        $tree = [
            'type' => 'document',
            'astVersion' => '2.0',
            'children' => [
                [
                    'type' => 'list',
                    'marker' => ['kind' => 'bullet', 'char' => '-'],
                    'spacing' => 'tight',
                    'children' => [['type' => 'list_item', 'children' => [$end]]],
                ],
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

    /**
     * @return array<string, array{0: array<mixed>}>
     */
    public static function lazyContainerEnds(): array
    {
        $paragraph = ['type' => 'paragraph', 'children' => [['type' => 'text', 'value' => 'q']]];
        $quote = ['type' => 'block_quote', 'children' => [$paragraph]];
        $footnote = static fn (array $end): array => ['type' => 'footnote', 'key' => 'n', 'namespace' => 'footnote', 'children' => [$end]];
        $description = static fn (array $end): array => [
            'type' => 'definition_list',
            'children' => [
                ['type' => 'definition_term', 'children' => [['type' => 'text', 'value' => 't']]],
                ['type' => 'definition_description', 'children' => [$end]],
            ],
        ];

        return [
            'footnote ending in a paragraph' => [$footnote($paragraph)],
            'footnote ending in a quote' => [$footnote($quote)],
            'description ending in a paragraph' => [$description($paragraph)],
            'description ending in a quote' => [$description($quote)],
        ];
    }

    /**
     * C2-W-154 (ruling on carve#6397): a footnote or description follows the list rules, so below one that ends in a
     * block taking lazy lines the paragraph has no spelling.
     *
     * @param array<mixed> $container
     */
    #[DataProvider('lazyContainerEnds')]
    public function testNoSpellingBelowALazyContainerEnd(array $container): void
    {
        $children = [
            [

                'type' => 'paragraph',
                'children' => [
                    ['type' => 'text', 'value' => 'a'],
                    ['type' => 'footnote_ref', 'key' => 'n', 'namespace' => 'footnote'],
                ],
            ],
            $container,
            [
                'type' => 'paragraph',
                'children' => [
                    ['type' => 'smart_punctuation', 'kind' => 'em_dash', 'glyph' => "\u{2014}", 'source' => '---'],
                    ['type' => 'text', 'value' => 'yaml'],
                ],
            ],
        ];
        $result = Carve::writeWithDiagnostics(['type' => 'document', 'astVersion' => '2.0', 'children' => $children]);

        self::assertSame(
            [['structure-unspellable', '/children/2/children/0']],
            array_map(static fn ($d): array => [$d->code, $d->path], $result->diagnostics),
        );
    }

    /**
     * @param string $source
     *
     * @return mixed
     */
    private static function tree(string $source): mixed
    {
        $tree = json_decode(Carve::toJson($source), true, 512, JSON_THROW_ON_ERROR);
        unset($tree['srcByteLength']);

        return Corpus::withoutPositions($tree);
    }
}
