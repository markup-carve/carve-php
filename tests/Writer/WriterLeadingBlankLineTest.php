<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve-php#5990: empty lines before the first block read as nothing, so the write of the rest needs no read-back;
 * where they decide how the first line reads (frontmatter, a byte-order mark), the write keeps its read-back.
 */
final class WriterLeadingBlankLineTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function sources(): array
    {
        return [
            'table' => ["\n| a | `c` | [l](u) |\n", "| a | `c` | [l](u) |\n"],
            'list' => ["\n\n- a *b*\n", "- a *b*\n"],
            'frontmatter opener below the line' => ["\n\n---\na: b\n---\n", "\n---\na: b\n---\n"],
            'typed frontmatter opener' => ["\n---yaml\na: b\n---\n", "---\na: b\n---\n"],
            'byte-order mark below the line' => ["\n\u{FEFF}x *y*\n", "\n\u{FEFF}x *y*\n"],
        ];
    }

    #[DataProvider('sources')]
    public function testLeadingEmptyLines(string $source, string $written): void
    {
        $this->assertSame($written, Carve::format($source));
    }
}
