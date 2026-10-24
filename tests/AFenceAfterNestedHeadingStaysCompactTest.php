<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AFenceAfterNestedHeadingStaysCompactTest extends TestCase
{
    #[DataProvider('cases')]
    public function testTheFenceAndNestedListUseTheCompactLayout(string $source, string $expected): void
    {
        $formatted = CarveConverter::toCarve($source);
        $converter = new CarveConverter();

        self::assertSame($expected, $formatted);
        self::assertSame($converter->convert($source), $converter->convert($formatted));
        self::assertSame($formatted, CarveConverter::toCarve($formatted));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function cases(): array
    {
        return [
            'heading' => ["- a\n    - # h\n  ~~~\nafter\n", "- a\n  - # h\n  ```\n  ```\n\nafter\n"],
            'last nested item is a heading' => [
                "- a\n    - first\n    - # h\n  ~~~\nafter\n",
                "- a\n  - first\n  - # h\n  ```\n  ```\n\nafter\n",
            ],
            'backtick source fence' => ["- a\n    - # h\n  ```\nafter\n", "- a\n  - # h\n  ```\n  ```\n\nafter\n"],
        ];
    }
}
