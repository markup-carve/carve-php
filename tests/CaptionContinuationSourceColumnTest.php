<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CaptionContinuationSourceColumnTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function captions(): iterable
    {
        yield 'flush continuation' => ["- - x\n  > q\n  ^ c\nt\n", "c\nt"];
        yield 'one source column inside list' => ["- - x\n  > q\n  ^ c\n t\n", "c\nt"];
        yield 'two source columns inside list' => ["- - x\n  > q\n  ^ c\n  t\n", "c\nt"];
        yield 'authored space outside list' => ["> q\n^ c\n t\n", "c\n t"];
    }

    #[DataProvider('captions')]
    public function testOnlyAuthoredSpaceEntersCaption(string $source, string $caption): void
    {
        self::assertStringContainsString(
            '<figcaption>' . $caption . '</figcaption>',
            CarveConverter::create()->convert($source),
        );
    }
}
