<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Json\TreeEncoder;
use MarkupCarve\Carve\Json\TreeEquality;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * C2-W-125 (carve#6592): once a content closed early and its closer took the backslash, the round asks nothing behind
 * it; the outer contents that closed one level early read as written again in the next round. Outputs match carve-js.
 */
final class ClosedEarlyCutTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function cases(): iterable
    {
        yield 'superscript chain behind a reported brace kind' => [
            '{^^\{^{\{^\{\^^}{^{^\{^\^}^}^}^^}^}',
            '{^\^\{\^{\{\^\{\^^}{^{^\{\^\^}^}^}\^^}\^}',
        ];
    }

    #[DataProvider('cases')]
    public function testNothingBehindAnEarlyCloserAsks(string $input, string $expected): void
    {
        $out = Carve::format($input . "\n");
        self::assertSame($expected . "\n", $out);
        self::assertSame(
            TreeEquality::normalize(TreeEncoder::decodedForWriter(Carve::parse($input . "\n"))),
            TreeEquality::normalize(TreeEncoder::decodedForWriter(Carve::parse($out))),
        );
    }
}
