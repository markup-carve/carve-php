<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Cli;

use MarkupCarve\Carve\Cli\Fixer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ClosingHeadingHashFixTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function headings(): array
    {
        return [
            'single hash' => ['# a #', '# a'],
            'longer run' => ['## Title ###', '## Title'],
            'tab separator' => ["# Title\t##", '# Title'],
            'following whitespace' => ["# Title ##  \n", "# Title\n"],
        ];
    }

    #[DataProvider('headings')]
    public function testClosingHashFixRemovesItsSeparator(string $source, string $expected): void
    {
        self::assertSame($expected, Fixer::fix($source, []));
    }
}
