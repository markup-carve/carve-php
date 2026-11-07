<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AQuotedDescriptionLineBlockDropsItsHostIndentTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function verseLines(): iterable
    {
        yield 'content column' => [":: t\n: +\n> ::: |\n  > word\n", '<p>word</p>'];

        yield 'past content column' => [":: t\n: +\n> ::: |\n    > word\n", '<p>word</p>'];

        yield 'space after quote marker remains verse' => [":: t\n: +\n> ::: |\n  >   word\n", '<p>&nbsp;&nbsp;word</p>'];

        yield 'nested quote' => [":: t\n: +\n> > ::: |\n  > > word\n", '<p>word</p>'];
    }

    #[DataProvider('verseLines')]
    public function testStructuralQuoteIndentIsNotVerseText(string $source, string $expectedVerse): void
    {
        self::assertStringContainsString($expectedVerse, CarveConverter::create()->convert($source));
    }
}
