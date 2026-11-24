<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Json\TreeEncoder;
use MarkupCarve\Carve\Json\TreeEquality;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HyphenRunAfterPlusTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function cases(): array
    {
        return [
            'plus and two hyphens' => ['\\+-\\-', '\\+-\\-'],
            'plus and a trailing word' => ['a \\+-\\- b', 'a \\+-\\- b'],
            'plus and a longer run' => ['x \\+-\\--\\- y', 'x \\+-\\--\\- y'],
            'quoted run' => ['> \\+-\\-', '> \\+-\\-'],
            'item run' => ['- \\+-\\-', '- \\+-\\-'],
        ];
    }

    #[DataProvider('cases')]
    public function testTheSecondHyphenIsEscapedBeforeThePlusSign(string $source, string $expected): void
    {
        $tree = TreeEncoder::decodedForWriter(Carve::parse($source));
        foreach ([[], ['normalize' => true]] as $options) {
            $written = Carve::formatWithDiagnostics($source, $options);
            self::assertSame($expected . "\n", $written->source);
            self::assertSame([], $written->diagnostics);
            self::assertTrue(TreeEquality::equal($tree, TreeEncoder::decodedForWriter(Carve::parse($written->source))));
        }
    }
}
