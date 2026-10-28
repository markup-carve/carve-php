<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReturnedCaptionInlineParsingTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function inlines(): iterable
    {
        yield 'strong' => ["![a][r]\n^ c\n*b*\n", '<strong>b</strong>'];
        yield 'emphasis' => ["![a][r]\n^ c\n/e/\n", '<em>e</em>'];
        yield 'link' => ["![a][r]\n^ c\n[x](/u)\n", '<a href="/u">x</a>'];
        yield 'quote host' => ["> ![a][r]\n> ^ c\n> *b*\n", '<strong>b</strong>'];
    }

    #[DataProvider('inlines')]
    public function testReturnedCaptionLinesUseOrdinaryInlineParsing(string $source, string $expected): void
    {
        $html = (new CarveConverter())->convert($source);

        self::assertStringContainsString($expected, $html);
        self::assertStringContainsString('![a][r]', $html);
    }
}
