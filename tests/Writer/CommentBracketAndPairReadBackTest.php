<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve-php#5733, #5739, #5740, C2-W-088: each input is the form carve-js writes, so each is a fixed point that reads
 * back as its own tree.
 */
final class CommentBracketAndPairReadBackTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function fixedPoints(): array
    {
        return [
            'image shape closed inside a line comment' => ["!\\[a %% c](/u)\n"],
            'image shape with a footnote closed inside a line comment' => ["!\\[^\\[n %% c] x](/u)\n"],
            'image shape with strong after a span' => ["*a* !\\[*b* %% c](/u)\n"],
            'image shape before a reference after a span' => ["*a* !\\[*b* %% ][r]\n"],
            'link shape closed inside a line comment stays bare' => ["[a %% c](/u)\n"],
            'brace hash that opens no critic comment' => ["x{#i}\n"],
            'leading star before a strong inside a bracket' => ["*s [ ^[n]*y*]\n"],
            'leading star before a strong in a bracket after a space' => ["*s [ *y*]\n"],
            'leading star before a strong behind a closed bracket' => ["*s [ ]*y*\n"],
            'leading star before a strong after a footnote reference' => ["*s [^[n] *y*] w\n"],
            'leading slash before an emphasis after a footnote reference' => ["*a* /s x [^[n] /y/ z]\n"],
            'star before a strong after a space' => ["a *b *s*\n"],
        ];
    }

    #[DataProvider('fixedPoints')]
    public function testWritesBackUnchanged(string $input): void
    {
        $sidecar = Carve::formatWithDiagnostics($input);
        $this->assertSame($input, $sidecar->source);
        $this->assertSame([], $sidecar->diagnostics);
        $this->assertSame($input, Carve::write(Carve::parse($input)));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function escapes(): array
    {
        return [
            'escaped bang before an image shape' => ["\\![a %% c](/u)\n", "!\\[a %% c](/u)\n"],
            'escaped star before a strong inside a bracket' => ["\\*s [ ^[n]*y*]\n", "*s [ ^[n]*y*]\n"],
        ];
    }

    #[DataProvider('escapes')]
    public function testWritesTheCanonicalForm(string $input, string $expected): void
    {
        $this->assertSame($expected, Carve::format($input));
        $this->assertSame($expected, Carve::format($expected));
    }
}
