<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Json\TreeEncoder;
use MarkupCarve\Carve\Json\TreeEquality;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve-php#5910, C2-W-003, C2-W-088: carets and brackets around a note take the escapes the rounds give, as carve-js
 * writes them, and a second `fmt` gives the same bytes.
 */
final class CaretBracketFmtStableTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function cases(): array
    {
        return [
            '5910 carets and brackets before a footnote reference' => ['[^]^\\^[^n]', '[\\^]\\^\\^[^n]'],
            'caret pair after a note reference' => ['\\^[^n][^]', '\\^[^n][\\^]'],
            'caret runs around a note reference' => ['^\\^[^n]^^([^]', '\\^\\^[^n]\\^^([\\^]'],
            'parenthesis before a footnote reference' => ['^)\\^[^n][^n](\\^', '\\^)\\^[^n][^n](\\^'],
            'escaped bracket before a later caret pair' => ['[^n]^[\\[^^[^n]!^[', '[^n]^\\[\\[^^[^n]!^\\['],
            'unclosed parenthesis behind a note' => ['^^[\\[](^^', '^^[\\[](^^'],
            'closed tail behind a note' => ['^[\\[](z)', '^[\\[]\\(z)'],
        ];
    }

    #[DataProvider('cases')]
    public function testFormatIsTheRoundFormAndStable(string $source, string $expected): void
    {
        $written = Carve::formatWithDiagnostics($source . "\n");
        $this->assertSame($expected . "\n", $written->source);
        $this->assertSame([], $written->diagnostics);
        $this->assertSame($written->source, Carve::formatWithDiagnostics($written->source)->source);
        $this->assertTrue(TreeEquality::equal(
            TreeEncoder::decodedForWriter(Carve::parse($source . "\n")),
            TreeEncoder::decodedForWriter(Carve::parse($written->source)),
        ));
    }
}
