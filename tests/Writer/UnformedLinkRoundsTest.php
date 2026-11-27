<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * C2-W-088, C2-W-125: a construct the parse reads inside a link that did not form is no reported kind, the lone link
 * brackets of the link fallback are no logged trials, and a bracketed node that ends early cuts its list (carve-php#6116).
 * Expected outputs are carve-js `main`.
 */
final class UnformedLinkRoundsTest extends TestCase
{
    /**
     * @return array<string, array{string, string, bool}>
     */
    public static function cases(): array
    {
        return [
            'footnote reference inside a collapsed label' => [
                "\\[^[\\` [`[[^]]\\]][]\\[\n",
                "\\[^[\\` [`[[^]]\\]][][\n",
                true,
            ],
            // Under normalize this shape still differs from carve-js, as before this change.
            'note that ends early before text brackets' => [
                "\\[^[\\`[`[[^]a\\[]][]][\\[\n",
                "\\[^[\\`[`[[^]a\\[]][]]\\[[\n",
                false,
            ],
            'code span inside a collapsed label' => [
                "\\[^[\\`[`]`[[^]\\[]][]\\[\n",
                "\\[^[\\`[`]`[[^]\\[]][]\\[\n",
                true,
            ],
        ];
    }

    #[DataProvider('cases')]
    public function testFormat(string $source, string $expected, bool $normalized): void
    {
        foreach ($normalized ? [[], ['normalize' => true]] : [[]] as $options) {
            $written = Carve::formatWithDiagnostics($source, $options);
            self::assertSame($expected, $written->source);
            self::assertSame([], $written->diagnostics);
            self::assertSame($expected, Carve::format($expected, $options));
        }
    }
}
