<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve-php#6119: a C2-W-125 retest that repeats in one write, for blocks of the same content, gives each block what
 * its own retest gives: the retest of the first paragraph finds a subset, that of the second finds none.
 */
final class RepeatedRetestTest extends TestCase
{
    /**
     * @var string
     */
    private const FOUND = "\\[^[\\`[`[[^]]][]\n";

    /**
     * @var string
     */
    private const LOST = "\\[^[\\`[`[[^]\\[]][]\\[\n";

    /**
     * @return array<string, array{string, bool}>
     */
    public static function provideRepeated(): array
    {
        $cases = [];
        foreach (
            [
                'found' => str_repeat(self::FOUND . "\n", 6),
                'lost' => str_repeat(self::LOST . "\n", 6),
                'mixed' => str_repeat(self::FOUND . "\n" . self::LOST . "\n", 3),
            ] as $name => $source
        ) {
            $cases[$name] = [$source, false];
            $cases[$name . ', normalize'] = [$source, true];
        }

        return $cases;
    }

    #[DataProvider('provideRepeated')]
    public function testEachBlockKeepsItsRetest(string $source, bool $normalize): void
    {
        $options = $normalize ? ['normalize' => true] : [];

        $result = Carve::formatWithDiagnostics($source, $options);

        $this->assertSame([], $result->diagnostics);
        $this->assertSame(substr($source, 0, -1), $result->source);
    }
}
