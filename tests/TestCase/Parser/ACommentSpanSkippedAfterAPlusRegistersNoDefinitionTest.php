<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A `%%%` span a `+` skips is still a comment: a definition inside it registers nothing (corpus 0190).
 */
final class ACommentSpanSkippedAfterAPlusRegistersNoDefinitionTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function provider(): array
    {
        $hosts = [
            'item' => ["- a\n+\n", ''],
            'lazy item' => ["- a\nfirst\n+\n", ''],
            'item marker line' => ["- +\n", ''],
            'ordered item' => ["1. a\n+\n", ''],
            'quote marker line' => ["> +\n", '> '],
            'description' => [":: t\n: b\n+\n", ''],
            'nested item' => ["- a\n  - b\n  +\n", '  '],
        ];
        $cases = [];
        foreach ($hosts as $name => [$head, $prefix]) {
            foreach (['link' => '[r]: /u', 'abbreviation' => '*[HTML]: Hyper'] as $kind => $definition) {
                foreach (['%%%', '%%%%'] as $fence) {
                    $cases["$name, $kind, $fence"] = [$head . "$prefix$fence\n$prefix$definition\n$prefix$fence\n\nSee [r][] HTML.\n"];
                }
            }
        }

        return $cases;
    }

    #[DataProvider('provider')]
    public function testTheDefinitionStaysInTheComment(string $source): void
    {
        $html = (new CarveConverter())->convert($source);

        $this->assertStringContainsString('<p>See [r][] HTML.</p>', $html, $source);
    }

    public function testTheSpanHidesItsBodyAndTheReferenceStaysLiteral(): void
    {
        $html = (new CarveConverter())->convert("- a\n+\n%%%\n[r]: /u\n%%%\n\nSee [r][].\n");

        $this->assertSame("<ul>\n  <li>a</li>\n</ul>\n<p>See [r][].</p>", $html);
    }
}
