<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AFenceOnANestedDescriptionMarkerLineClosesTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function fences(): array
    {
        return [
            'closer then paragraph' => [
                ":: t\n: :: t\n  : ```\n    code\n    ```\n    b\n",
                "<pre><code>code\n</code></pre>\n        <p>b</p>\n      </dd>",
            ],
            'closer at the end' => [
                ":: t\n: :: t\n  : ```\n    code\n    ```\n",
                "<pre><code>code\n</code></pre>\n      </dd>",
            ],
            'deeper payload line keeps its indentation' => [
                ":: t\n: :: t\n  : ```\n    code\n      deep\n    ```\n",
                "<pre><code>code\n  deep\n</code></pre>\n      </dd>",
            ],
            'tilde fence in a list item' => [
                "- :: t\n  : :: t\n    : ~~~\n      code\n      ~~~\n      b\n",
                "<pre><code>code\n</code></pre>\n            <p>b</p>",
            ],
        ];
    }

    #[DataProvider('fences')]
    public function testTheFenceClosesInTheInnerDescription(string $source, string $expected): void
    {
        $html = (new CarveConverter())->convert($source);

        // carve-php#4670: the outer body folded the closer into the paragraph it thought `code` opened.
        self::assertStringContainsString($expected, $html);
        self::assertStringNotContainsString("```\n", $html);
        self::assertStringNotContainsString("~~~\n", $html);
    }

    public function testFmtWritesTheCommentAfterTheFenceAsItsOwnBlock(): void
    {
        $source = ":: t\n: :: t\n  : ```\n    code\n    ```\n    %% note\n\n    b\n";
        $formatted = CarveConverter::toCarve($source);

        self::assertSame(":: t\n: :: t\n  : ```\n    code\n    ```\n\n    %% note\n\n    b\n", $formatted);
        self::assertSame($formatted, CarveConverter::toCarve($formatted));
        self::assertSame((new CarveConverter())->convert($source), (new CarveConverter())->convert($formatted));
    }
}
