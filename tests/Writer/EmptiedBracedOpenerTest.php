<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Json\TreeEncoder;
use MarkupCarve\Carve\Json\TreeEquality;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve-php#5857: a superscript whose text ends in `{^` reads back as text when written bare (`{^{^^}`); the rounds
 * escape every such opener of the leaf in one round, also where the parse holds no superscript at all.
 */
final class EmptiedBracedOpenerTest extends TestCase
{
    /**
     * @return array<string, array{int}>
     */
    public static function counts(): array
    {
        return [
            'one' => [1],
            'three' => [3],
            'below the depth cap' => [199],
            'at the depth cap' => [200],
            'past the depth cap' => [400],
        ];
    }

    #[DataProvider('counts')]
    public function testWritesEveryUnitWithoutARefusal(int $count): void
    {
        $source = trim(str_repeat('{^\\{^^} ', $count)) . "\n";

        $result = Carve::formatWithDiagnostics($source);

        $this->assertSame([], $result->diagnostics);
        $this->assertSame($source, $result->source);
        $this->assertTrue(TreeEquality::equal(
            TreeEncoder::decodedForWriter(Carve::parse($source)),
            TreeEncoder::decodedForWriter(Carve::parse($result->source)),
        ));
    }
}
