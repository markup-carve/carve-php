<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Json\TreeEncoder;
use MarkupCarve\Carve\Json\TreeEquality;
use MarkupCarve\Carve\Writer\Writer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve-php#5645: the escaped brackets of the local write no longer count toward the depth limit of C2-I-118, so a
 * delimiter past the limit of the source can pair in the written form although the bare form reads back.
 */
final class NestedLinkDepthReadBackTest extends TestCase
{
    /**
     * @return array<string, array{int}>
     */
    public static function depths(): array
    {
        return ['n = 49' => [49], 'n = 50' => [50], 'n = 51' => [51], 'n = 60' => [60], 'n = 146' => [146]];
    }

    #[DataProvider('depths')]
    public function testNestedLinksReadBackAsTheirTree(int $n): void
    {
        $source = str_repeat('^*[*[[*', $n) . '^*[*x](u)' . str_repeat('^]]](u)', $n) . "\n";
        $result = Writer::formatWithDiagnostics($source);

        $this->assertSame([], $result->diagnostics);
        $this->assertTrue(TreeEquality::equal(
            TreeEncoder::decodedForWriter(Carve::parse($source)),
            TreeEncoder::decodedForWriter(Carve::parse($result->source)),
        ));
    }
}
