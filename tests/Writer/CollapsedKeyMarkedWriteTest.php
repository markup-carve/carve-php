<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve-php#5798: the marked write of the rounds spells the key of a collapsed reference as the bare write does, so many
 * such paragraphs stay inside the alignment edit cap.
 */
final class CollapsedKeyMarkedWriteTest extends TestCase
{
    /**
     * @return array<string, array{int}>
     */
    public static function counts(): array
    {
        return ['one' => [1], 'past the cap' => [129], 'four hundred' => [400]];
    }

    #[DataProvider('counts')]
    public function testWritesTheSource(int $count): void
    {
        $paragraph = "^[ \\]} [^[b]\\]][]\n";
        $source = rtrim(str_repeat($paragraph . "\n", $count)) . "\n";
        $this->assertSame($source, Carve::write(Carve::parse($source)));
    }
}
