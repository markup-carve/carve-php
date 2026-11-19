<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Lint;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class Next2QuotedBlockAndFrontmatterLintTest extends TestCase
{
    /**
     * @return array<string, array{string, array<array{string, int, int}>}>
     */
    public static function cases(): array
    {
        return [
            'bare frontmatter opener' => ['---', [['unclosed-frontmatter', 0, 3]]],
            'bare frontmatter opener with trailing newline' => ["---\n", [['unclosed-frontmatter', 0, 3]]],
            'frontmatter with a value line' => [
                "---\nplain", [['frontmatter-shaped-thematic-break', 0, 9], ['unclosed-frontmatter', 0, 9]],
            ],
            'closed frontmatter with no value' => ["---\n---", [['frontmatter-shaped-thematic-break', 0, 7]]],
            'quoted heading-shaped line' => ["> base\n> # H", [['block-opener-without-blank-line', 9, 10]]],
            'quoted underline' => ["> base\n> ---", [['setext-heading-underline', 9, 12]]],
            'nested quoted underline' => ["> > base\n> > ===", [['setext-heading-underline', 13, 16]]],
            'quoted heading after blank' => ["> base\n>\n> # H", []],
            'quoted underline after blank' => ["> base\n>\n> ---", [['thematic-break-spelling', 11, 14]]],
        ];
    }

    /**
     * @param string $source
     * @param array<array{string, int, int}> $expected
     */
    #[DataProvider('cases')]
    public function testCoreLint(string $source, array $expected): void
    {
        self::assertSame($expected, array_map(
            static fn ($diagnostic): array => [$diagnostic->code, $diagnostic->startOffset, $diagnostic->endOffset],
            Carve::lint($source),
        ));
    }
}
