<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FuzzFinalSpellingTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function cases(): array
    {
        return [
            'slash across unmatched bracket' => ['\\/[/', '\\/[/'],
            'star run behind brace' => ['{***>*', '{***>*'],
            'dollar before math' => ['\\$$`x`', '\\$$`x`'],
            'unmatched backtick runs' => ['[`][]``', '[`][]``'],
            'braced strong at paragraph lead' => ['{{* x *}', '{{* x *}'],
            'malformed bytes in a cell continuation' => ["|\xDF|\n+\x83|", "| � |\n+ � |"],
        ];
    }

    #[DataProvider('cases')]
    public function testWriterKeepsParsedContent(string $input, string $expected): void
    {
        $result = Carve::formatWithDiagnostics($input);
        self::assertSame([], $result->diagnostics);
        self::assertSame($expected . "\n", $result->source);
        self::assertSame(Carve::toHtml($input), Carve::toHtml($result->source));
        self::assertSame($result->source, Carve::format($result->source));
    }
}
