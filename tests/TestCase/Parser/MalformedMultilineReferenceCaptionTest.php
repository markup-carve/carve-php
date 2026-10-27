<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MalformedMultilineReferenceCaptionTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function malformedReferences(): array
    {
        return [
            'image' => ["![][\n]\n^ a\n^ b\n"],
            'link' => ["[x][\n]\n^ a\n^ b\n"],
        ];
    }

    #[DataProvider('malformedReferences')]
    public function testCaretLinesContinueLiteralReferenceParagraph(string $source): void
    {
        self::assertSame(
            '<p>' . rtrim($source) . '</p>',
            trim((new CarveConverter())->convert($source)),
        );
    }
}
