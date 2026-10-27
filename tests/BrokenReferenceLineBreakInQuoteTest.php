<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BrokenReferenceLineBreakInQuoteTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function references(): iterable
    {
        yield 'link' => ['[t][r'];
        yield 'image' => ['![t][r'];
    }

    #[DataProvider('references')]
    public function testStructuralQuoteIndentDoesNotEnterLiteralReference(string $opener): void
    {
        self::assertSame(
            preg_replace('/\n\z/', '', "<blockquote>\n  <blockquote><p>{$opener}\nx]</p></blockquote>\n</blockquote>\n"),
            CarveConverter::create()->convert("> > {$opener}\nx]\n\n[r x]: /u\n"),
        );
    }
}
