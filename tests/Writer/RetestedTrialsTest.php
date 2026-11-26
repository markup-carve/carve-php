<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * C2-W-125: where every pass of the rounds loses a node, the passes run again and retest the escapes they placed
 * together, fewest first (carve-js `retestTrials`). Outputs are carve-js `next2`.
 */
final class RetestedTrialsTest extends TestCase
{
    /**
     * carve-php#6078: the kind escape of the second `[` keeps the collapsed reference from forming, and every pass
     * stopped there; the retest keeps only the escapes of the first `[` and the backtick.
     *
     * @return array<string, array{string, string, bool}>
     */
    public static function provideRetested(): array
    {
        $first = "\\[^[\\`[`[[^]]][]\n";
        $both = "\\[^[\\`[`[[^]]][]\n\n\\[]\\[[]\n";

        return [
            'one paragraph' => [$first, $first, false],
            'one paragraph, normalize' => [$first, $first, true],
            'two paragraphs' => ["\\[^[\\`[`[[^]]][]\n\n[\\][[]\n", $both, false],
            'two paragraphs, normalize' => ["\\[^[\\`[`[[^]]][]\n\n[\\][[]\n", $both, true],
        ];
    }

    /**
     * @param string $source
     * @param string $expected
     * @param bool $normalize
     *
     * @return void
     */
    #[DataProvider('provideRetested')]
    public function testRetestedPassGivesTheTree(string $source, string $expected, bool $normalize): void
    {
        $options = $normalize ? ['normalize' => true] : [];

        $result = Carve::formatWithDiagnostics($source, $options);

        $this->assertSame([], $result->diagnostics);
        $this->assertSame($expected, $result->source);
        $this->assertSame($expected, Carve::format($expected, $options));
    }
}
