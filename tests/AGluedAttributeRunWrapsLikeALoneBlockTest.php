<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * markup-carve/carve#4078 superseding ruling: each block of a glued run keeps a lone block's wrapping rule.
 */
final class AGluedAttributeRunWrapsLikeALoneBlockTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function runs(): array
    {
        return [
            'one line' => ["{.a}{.b}\np\n", '<p class="a b">p</p>'],
            'later block wraps' => ["{.a}{.b\n}\np\n", '<p class="a b">p</p>'],
            'first block wraps' => ["{.a\n}{.b}\np\n", '<p class="a b">p</p>'],
            'both blocks wrap' => ["{.a\n}{.b\n}\np\n", '<p class="a b">p</p>'],
            'three blocks' => ["{.a}{.b\n.c\n}{.d}\np\n", '<p class="a b c d">p</p>'],
            'nothing below renders nothing' => ["{k=\"\"}{c\n}\n", ''],
            'text after the closer is no run' => ["{.a}{.b\n}x\np\n", "<p>{.a}{.b\n}x\np</p>"],
            'a space between blocks is no run' => ["{.a} {.b\n}\np\n", "<p>{.a} {.b\n}\np</p>"],
            'a blank inside is no run' => ["{.a}{.b\n\n}\n", "<p>{.a}{.b</p>\n<p>}</p>"],
            'an empty block is no run' => ["{.a}{\n}\np\n", "<p>{.a}{\n}\np</p>"],
            'in a quote' => ["> {.a}{.b\n> }\n> p\n", '<blockquote><p class="a b">p</p></blockquote>'],
        ];
    }

    #[DataProvider('runs')]
    public function testARunWrapsBlockByBlock(string $source, string $html): void
    {
        $this->assertSame($html, (new CarveConverter())->convert($source));
    }
}
