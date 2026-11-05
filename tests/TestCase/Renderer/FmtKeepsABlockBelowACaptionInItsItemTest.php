<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A captioned fence, quote or table opens with a line that ends the paragraph above it, so the writer keeps
 * it and the block below its caption at the item's content column instead of behind a `+` (carve#5177).
 * Cases and outputs are carve-js's (carve-js#4424).
 */
final class FmtKeepsABlockBelowACaptionInItsItemTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function cases(): iterable
    {
        yield 'a captioned fence and a quote below it' => [
            "- text\n  ```\n  code\n  ```\n\n  ^ cap\n  > r\n",
            "- text\n  ```\n  code\n  ```\n  ^ cap\n  > r\n",
        ];

        yield 'a quote figure' => ["- text\n  > q\n  ^ cap\n  > r\n", "- text\n  > q\n  ^ cap\n  > r\n"];

        yield 'a math figure' => ["1. text\n   $$\n   x\n   $$\n   ^ cap\n   > r\n", "1. text\n   $$\n   x\n   $$\n   ^ cap\n   > r\n"];

        yield 'a nested fence figure' => [
            "- a\n\n  - text\n    ```\n    code\n    ```\n    ^ cap\n    > r\n",
            "- a\n\n  - text\n    ```\n    code\n    ```\n    ^ cap\n    > r\n",
        ];

        yield 'the + spelling stays when the caption ends the item (corpus 622)' => [
            "- x\n  ```\n  x\n  ```\n  ^ cap\n- c\n",
            "- x\n+\n```\nx\n```\n^ cap\n- c\n",
        ];

        yield 'a caption that runs on' => [
            "- text\n  ```\n  code\n  ```\n  ^ cap\n  more\n",
            "- text\n  ```\n  code\n  ```\n  ^ cap\n  more\n",
        ];

        yield 'a figure below a figure' => [
            "- a\n\n  - text\n    ```\n    x\n    ```\n    ^ cap\n    ```\n    y\n    ```\n    ^ cap2\n",
            "- a\n\n  - text\n    ```\n    x\n    ```\n    ^ cap\n    ```\n    y\n    ```\n    ^ cap2\n",
        ];

        yield 'a captioned table does not merge with the table below it' => [
            "- outer\n\n  - one\n    | a | b |\n    ^ cap\n    | t |\n",
            "- outer\n\n  - one\n    | a | b |\n    ^ cap\n    | t |\n",
        ];
    }

    #[DataProvider('cases')]
    public function testTheBlockStaysInTheItem(string $source, string $expected): void
    {
        $written = (new CarveConverter())->toCarve($source);
        self::assertSame($expected, $written);
        self::assertSame((new CarveConverter())->convert($source), (new CarveConverter())->convert($written));
        self::assertSame($written, (new CarveConverter())->toCarve($written));
    }
}
