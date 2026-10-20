<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class NestedTaskFenceFlushLeftTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string}>
     */
    public static function nestedTaskProvider(): array
    {
        $cases = [];
        $followers = [
            'paragraph' => ['tail', "<p>tail</p>\n"],
            'quote' => ['> tail', "<blockquote><p>tail</p></blockquote>\n"],
            'list' => ['* tail', "<ul>\n  <li>tail</li>\n</ul>\n"],
        ];
        foreach ([2, 3] as $depth) {
            foreach (['[ ]', '[x]'] as $box) {
                foreach ([0, 2, 4] as $extra) {
                    foreach ($followers as $name => [$follower, $after]) {
                        $indent = $depth * 2 + $extra;
                        $source = str_repeat('- ', $depth - 1) . "- $box ```\n"
                            . str_repeat(' ', $indent) . "z\n$follower\n";
                        $cases["depth $depth $box column $indent $name"] = [$source, str_repeat(' ', $extra) . "z\n", $after];
                    }
                }
            }
        }

        return $cases;
    }

    #[DataProvider('nestedTaskProvider')]
    public function testFlushLeftLineEndsNestedTaskFence(string $source, string $payload, string $after): void
    {
        $html = (new CarveConverter())->convert($source);
        self::assertStringContainsString("<pre><code>$payload</code></pre>", $html);
        self::assertStringEndsWith("</ul>\n$after", $html);
    }
}
