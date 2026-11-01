<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CaptionLazyContinuationInContainersTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function sources(): array
    {
        return [
            'footnote' => ["[^0]: ```\n  ```\n  ^ p\nz\n\n[^0]\n"],
            'description' => [":: t\n: ```\n  ```\n  ^ p\nz\n"],
            'description in item' => ["- :: t\n  : ```\n    ```\n    ^ p\nz\n"],
        ];
    }

    #[DataProvider('sources')]
    public function testALazyLineContinuesTheCaption(string $source): void
    {
        $html = (new CarveConverter())->convert($source);

        self::assertSame(1, substr_count($html, '<figcaption>'));
        self::assertStringContainsString("<figcaption>p\nz</figcaption>", $html);
        self::assertStringNotContainsString('<p>z</p>', $html);
    }
}
