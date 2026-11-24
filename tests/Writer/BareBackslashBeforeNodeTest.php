<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve-php#5859: a text that ends in a backslash before a node keeps its doubled backslash in the bare write
 * (C2-W-097), so the rounds read no superscript that is not there and escape no unclosed `{^` before it.
 */
final class BareBackslashBeforeNodeTest extends TestCase
{
    /**
     * @return array<string, array{int}>
     */
    public static function counts(): array
    {
        return [
            'one' => [1],
            'two' => [2],
            'many' => [150],
        ];
    }

    #[DataProvider('counts')]
    public function testKeepsTheSource(int $count): void
    {
        $source = trim(str_repeat('{^a \\\\{^ b^} ', $count)) . "\n";

        $result = Carve::formatWithDiagnostics($source);

        $this->assertSame([], $result->diagnostics);
        $this->assertSame($source, $result->source);
    }
}
