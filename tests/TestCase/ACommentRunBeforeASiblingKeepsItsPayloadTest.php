<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A comment span carried to the last item leaves its opener's column, like any
 * other span in an item. Keeping the column made every `fmt` pass indent the
 * body further, and a payload line was read as a comment or a definition.
 */
class ACommentRunBeforeASiblingKeepsItsPayloadTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function runs(): array
    {
        return [
            'opener at column 0' => ["- a\n%%%\nc\n%%%\n\n- b\n", "- a\n\n  %%%\n  c\n  %%%\n\n- b\n"],
            'opener at the item column' => ["- a\n\n  %%%\n  c\n  %%%\n\n- b\n", "- a\n\n  %%%\n  c\n  %%%\n\n- b\n"],
            'payload past the opener' => ["- a\n\n  %%%\n    c\n  %%%\n\n- b\n", "- a\n\n  %%%\n    c\n  %%%\n\n- b\n"],
            'low opener keeps the payload offset' => ["- a\n%%%\n c\n%%%\n\n- b\n", "- a\n\n  %%%\n   c\n  %%%\n\n- b\n"],
            'second item' => [
                "- a\n\n  %%%\n  c\n  %%%\n\n- b\n\n  %%%\n  e\n  %%%\n\n- c\n",
                "- a\n\n  %%%\n  c\n  %%%\n\n- b\n\n  %%%\n  e\n  %%%\n\n- c\n",
            ],
            'payload that reads as a comment' => ["- a\n\n  %%%\n   %% c\n  %%%\n\n- b\n", "- a\n\n  %%%\n   %% c\n  %%%\n\n- b\n"],
            'nested list' => ["- a\n  - s\n\n    %%%\n    c\n    %%%\n\n  - t\n", "- a\n  - s\n\n    %%%\n    c\n    %%%\n\n  - t\n"],
        ];
    }

    #[DataProvider('runs')]
    public function testFmtSettlesInOnePass(string $input, string $expected): void
    {
        $once = CarveConverter::toCarve($input);

        $this->assertSame($expected, $once);
        $this->assertSame($once, CarveConverter::toCarve($once));
    }

    public function testAPayloadLineIsNotAnAbbreviationDefinition(): void
    {
        $html = (new CarveConverter())->convert("- a\n\n  %%%\n  *[HTML]: Hyper\n  %%%\n\n- HTML\n");

        $this->assertStringNotContainsString('<abbr', $html);
    }
}
