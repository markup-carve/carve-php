<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * CARVE-P9-016 and CARVE-P9-017: a malformed colon line is paragraph text and leaves no
 * absorbing state, so the bare run below it opens a div (carve#4863 ruling).
 */
final class AQuotedMalformedColonLazyLineKeepsAbsorptionTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function documents(): iterable
    {
        yield 'unmarked malformed colon line belongs to the quote paragraph' => [
            "> e\n:::e\n> :::\nl\n",
            "<blockquote>\n  <p>e\n:::e</p>\n  <div>\n\n  </div>\n</blockquote>\n<p>l</p>",
        ];

        yield 'bare run at the outer quote does not belong to the inner paragraph' => [
            "> > e\n> :::e\n> :::\nl\n",
            "<blockquote>\n  <blockquote><p>e\n:::e</p></blockquote>\n  <div>\n\n  </div>\n</blockquote>\n<p>l</p>",
        ];

        yield 'partially prefixed malformed line arms the inner paragraph' => [
            "> > e\n> :::e\n> > :::\nl\n",
            "<blockquote>\n  <blockquote>\n    <p>e\n:::e</p>\n    <div>\n\n    </div>\n  </blockquote>\n</blockquote>\n<p>l</p>",
        ];
    }

    #[DataProvider('documents')]
    public function testQuoteParagraphAbsorption(string $source, string $expected): void
    {
        self::assertSame($expected, trim((new CarveConverter())->convert($source)));
    }
}
