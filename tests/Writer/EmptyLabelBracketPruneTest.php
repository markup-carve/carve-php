<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Json\TreeEncoder;
use MarkupCarve\Carve\Json\TreeEquality;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve-php#5899, C2-W-125: the backslash a trial left before the `[` of a text `[^]` is taken out where the form
 * still reads back and the bare parse reports no `[` kind in the block, as carve-js and the spec model write it.
 */
final class EmptyLabelBracketPruneTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function cases(): array
    {
        return [
            '5899 text note bracket before a reference' => ['^\\[[^a][^]', '^\\[[^a][^]'],
            'escaped empty label behind a reference' => ['\\[^(u)[^b]\\[^]', '\\[^(u)[^b][^]'],
            'brace before an empty label' => ['[\\^(u)[^a]{[^]', '\\[^(u)[^a]{[^]'],
            'brace and space before an empty label' => ['\\[^x[^a]{ [^]', '\\[^x[^a]{ [^]'],
            'closing brace before an empty label' => ['\\^:a[[^a]}\\[^]', '^:a[[^a]}[^]'],
            'extension sigil before a caret bracket' => [':a\\[^*a[^]', '\\:a\\[^*a[^]'],
        ];
    }

    #[DataProvider('cases')]
    public function testEmptyLabelBracketStaysBare(string $source, string $expected): void
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
