<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AnOpenFenceKeepsTheBlankRunBeforeItsBodyEndsTest extends TestCase
{
    #[DataProvider('cases')]
    public function testTheRunStaysInTheFence(string $source, string $code): void
    {
        self::assertStringContainsString('<pre><code>' . $code . '</code></pre>', (new CarveConverter())->convert($source));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function cases(): array
    {
        return [
            'description' => [":: t\n: ```\n  code\n\nafter\n", "code\n\n"],
            'description, two blanks' => [":: t\n: ```\n  code\n\n\nafter\n", "code\n\n\n"],
            'description at the end of the document' => [":: t\n: ```\n  code\n\n", "code\n\n"],
            'empty fence in a description' => [":: t\n: ```\n\nafter\n", "\n"],
            'note' => ["[^1]: ```\n  code\n\nafter\n\nx[^1]\n", "code\n\n"],
            'description in an item' => ["- :: t\n  : ```\n    code\n\nafter\n", "code\n\n"],
            'note in an item' => ["x[^1]\n\n- [^1]: ```\n    code\n\nafter\n", "code\n\n"],
            'description in a task item' => ["- [ ] :: t\n      : ```\n        code\n\nafter\n", "code\n\n"],
            'description in a note' => ["[^1]: :: t\n  : ```\n    code\n\nx[^1]\n", "code\n\n"],
            'description in a description' => [":: t\n: :: u\n  : ```\n\nafter\n", "\n"],
            'a note block a plus attached ends at the blank' => ["[^a]: +\n```\nx\n\nsee[^a]\n", "x\n"],
            'a quote block a plus attached adds no blank' => ["> t\n+\n:: t\n: ```\n\n", ''],
            'sublist item keeps its run' => ["- - ```\n    code\n\nafter\n", "code\n\n"],
        ];
    }

    public function testAClosedFenceInADescriptionLeavesTheRunOut(): void
    {
        self::assertStringContainsString(
            "<pre><code>code\n</code></pre>",
            (new CarveConverter())->convert("- :: t\n  : ```\n    code\n    ```\n\nafter\n"),
        );
    }
}
