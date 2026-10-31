<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Lint\DocumentLinter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The first-block form pulls only non-list blocks (CARVE-P9-067), so a list
 * item at the quote's column attaches to `> +` (carve#4409).
 */
final class AQuoteFirstBlockPullsNoListTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function provider(): array
    {
        return [
            'marker line' => ["- > +\n  - b\n"],
            'nested marker line' => ["- a\n  - > +\n    - b\n"],
            'stacked marker line' => ["- - > +\n    - b\n"],
            'ordered follower' => ["- a\n  - > +\n    1. b\n"],
            'own line in an item' => ["- a\n  > +\n  - b\n"],
        ];
    }

    #[DataProvider('provider')]
    public function testTheRefusedPlusReadsLikeALetter(string $source): void
    {
        $attached = (string)preg_replace('/> \+\n *(?=-|1\.)/', '> ', $source);
        $expected = (new CarveConverter())->convert($attached);

        $this->assertSame(preg_replace('/\n\z/', '', $expected), (new CarveConverter())->convert($source));
    }

    #[DataProvider('provider')]
    public function testLintReportsTheRefusedPlus(string $source): void
    {
        $found = array_map(
            static fn ($warning): string => $warning->rule . '@' . $warning->line . ':' . $warning->column,
            (new DocumentLinter())->lint($source),
        );

        $this->assertSame([], $found);
    }

    public function testTheListLineFoldsIntoTheQuotedPlus(): void
    {
        $this->assertSame(
            "<ul>\n  <li>\n    <blockquote>\n      <ul>\n        <li>b</li>\n      </ul>\n    </blockquote>\n  </li>\n</ul>",
            trim((new CarveConverter())->convert("- > +\n  - b\n")),
        );
    }
}
