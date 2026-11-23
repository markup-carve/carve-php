<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * C2-B-154, C2-W-035: a heading continuation stands past the heading marker, also where the marker line of a
 * container is wider than its body column (carve#6502).
 */
final class HeadingContinuationColumnTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function sources(): array
    {
        return [
            'footnote' => ["Text.[^1]\n\n[^1]: = Head\n        cont\n"],
            'footnote, five-character label, level 3' => ["Text.[^abcde]\n\n[^abcde]: === Head\n              one\n              two\n"],
            'footnote, non-ASCII label' => ["Text.[^日本]\n\n[^日本]: == Head\n          cont\n"],
            'footnote, hard break' => ["Text.[^1]\n\n[^1]: = Head\\\n        cont\n"],
            'footnote, heading as a later block' => ["Text.[^1]\n\n[^1]: Para.\n\n  = Head\n    cont\n"],
            'list inside a footnote' => ["Text.[^1]\n\n[^1]: - = Head\n          cont\n"],
            'list item' => ["- = Head\n    cont\n"],
            'ordered list item' => ["10. == Head\n       cont\n"],
            'description' => [":: T\n: = Head\n    cont\n"],
            'quote' => ["> = Head\n>   cont\n"],
        ];
    }

    /**
     * @param string $source
     *
     * @return void
     */
    #[DataProvider('sources')]
    public function testTheContinuationKeepsItsColumn(string $source): void
    {
        $this->assertSame($source, Carve::format($source));
    }
}
