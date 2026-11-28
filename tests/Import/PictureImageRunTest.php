<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Import;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve#6976: a `<picture>` stands as its `<img>` in the image-run test of Exception C2-M-013 (CC-2523 to CC-2526).
 */
final class PictureImageRunTest extends TestCase
{
    /**
     * @return array<string, array{string, array<mixed>, string}>
     */
    public static function runs(): array
    {
        return [
            'CC-2523 a <p> holding one <picture>' => [
                '<p><picture><source srcset="a.webp"><img src="a.png" alt="A"></picture></p>',
                [self::image('a.png', 'A')],
                "![A](a.png)\n",
            ],
            'CC-2524 a bare <picture> with a <source>' => [
                '<picture><source srcset="b.webp"><img src="b.png" alt="B"></picture>',
                [self::image('b.png', 'B')],
                "![B](b.png)\n",
            ],
            'CC-2525 two bare <picture> elements' => [
                "<picture><img src=\"a.png\" alt=\"A\"></picture>\n<picture><img src=\"b.png\" alt=\"B\"></picture>",
                [self::image('a.png', 'A'), self::image('b.png', 'B')],
                "![A](a.png)\n\n![B](b.png)\n",
            ],
            'CC-2526 a bare <picture> with text in its run' => [
                '<picture><img src="a.png" alt="A"></picture> b',
                [
                    [
                        'type' => 'paragraph',
                        'children' => [
                            ['type' => 'image', 'src' => 'a.png', 'alt' => 'A'],
                            ['type' => 'text', 'value' => ' b'],
                        ],
                    ],
                ],
                "![A](a.png) b\n",
            ],
        ];
    }

    /**
     * @param string $html
     * @param array<mixed> $children
     * @param string $source
     *
     * @return void
     */
    #[DataProvider('runs')]
    public function testRun(string $html, array $children, string $source): void
    {
        $result = Carve::importHtml($html);
        self::assertSame(self::strip($children), self::strip($result->document['children'] ?? null));
        self::assertSame($source, $result->source);
        self::assertNotContains('field-unspellable', $result->codes());
    }

    /**
     * @return array<string, mixed>
     */
    private static function image(string $src, string $alt): array
    {
        return ['type' => 'paragraph', 'role' => 'image', 'children' => [['type' => 'image', 'src' => $src, 'alt' => $alt]]];
    }

    private static function strip(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        unset($value['pos']);
        $value = array_map(self::strip(...), $value);
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }
}
