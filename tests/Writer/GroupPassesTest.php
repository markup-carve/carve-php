<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * C2-W-088: the fallback passes run group by group and the rounds are not run again after the verbatim restore
 * (carve-php#6082). Expected outputs are carve-js `main`.
 */
final class GroupPassesTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function cases(): array
    {
        return [
            // One group whose rounds lose a node: the authored-block fallback, no second round run.
            'collapsed label, one group' => [
                "\\[^[\\`[`[[^]\\[]][]\\[\n",
                "\\[^[\\`[`[[^]\\[]][]\\[\n",
            ],
            // The first two groups read back as the local write gives them; only the third takes the fallback.
            'groups settled by the local write' => [
                "a[\\][]a][\\`][][\\`\\`\n\n][^][\\`[][]^][]\\`\n\n\\[^[\\`[`[[^]]][][]\n",
                "a[\\][]a][\\`][][``\n\n][^][\\`[][]^][]`\n\n\\[^[\\`[`[[^]]][][]\n",
            ],
            // The first and third groups keep the form of their kind rounds.
            'groups settled by the kind rounds' => [
                "\\[^[\\`[`[[^]`]][][]\n\n\\[^[\\`[`[[^]]][][]\n\n\\[^[\\`[`[[][^]\\[]][]^`[\n",
                "\\[^[\\`\\[`[[^]`\\]]\\[]\\[]\n\n\\[^[\\`[`[[^]]][][]\n\n\\[^\\[\\`\\[`[[][^]\\[]][]^`\\[\n",
            ],
        ];
    }

    #[DataProvider('cases')]
    public function testFormat(string $source, string $expected): void
    {
        foreach ([[], ['normalize' => true]] as $options) {
            $written = Carve::formatWithDiagnostics($source, $options);
            self::assertSame($expected, $written->source);
            self::assertSame([], $written->diagnostics);
            self::assertSame($expected, Carve::format($expected, $options));
        }
    }
}
