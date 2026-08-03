<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Whether a column-0 line continues a list item whose last block is a container
 * is an open cross-engine question (markup-carve/carve#561) - but the answer
 * cannot depend on which character the line happens to start with. It did: a
 * `|` detached the line while `*`, `-` and `x` attached it, because
 * isBlockElementStart() accepted ANY line starting with a pipe as a table,
 * while the paragraph-interruption predicate next to it validated the row
 * first (carve-php#683). A bare `|` is not a table row, so it now behaves
 * exactly like the other three. A real row still opens a table.
 */
class ColumnZeroPipeAfterListItemTest extends TestCase
{
    protected CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nonRowCharacterProvider(): array
    {
        return [
            'bullet star' => ['*'],
            'bullet dash' => ['-'],
            'plain text' => ['x'],
            'bare pipe' => ['|'],
        ];
    }

    /**
     * @param string $char
     */
    #[DataProvider('nonRowCharacterProvider')]
    public function testColumnZeroLineAttachesToTheItemWhateverTheCharacter(string $char): void
    {
        $html = $this->converter->convert(". >\n" . $char);
        $this->assertStringContainsString($char . "\n  </li>", $html);
        $this->assertStringNotContainsString('<p>' . $char . '</p>', $html);
    }

    public function testARealTableRowStillOpensATable(): void
    {
        // `| a |` IS a row, so it opens a table and ends the item - which is
        // the behavior the unvalidated check was reaching for.
        $html = $this->converter->convert(". >\n| a |");
        $this->assertStringContainsString('<table>', $html);
        $this->assertStringContainsString('<td>a</td>', $html);
    }

    public function testAContinuationBackedRowStillBreaksOutOfTheItem(): void
    {
        // `| `a |` opens a code span, so it is not a complete row on its own -
        // but the `+` continuation closes the span and the table parser accepts
        // it. The block-boundary test has to accept the same shape, or the
        // table folds into the item as text instead of breaking out.
        $html = $this->converter->convert(". >\n| `a |\n+ b` |");
        $this->assertStringContainsString('<table>', $html);
        $this->assertStringContainsString('</ol>', strstr($html, '<table>', true) ?: '');
    }

    public function testAContinuationBackedRowAtTheContentColumnStaysInTheItem(): void
    {
        $html = $this->converter->convert("- item\n\n  | `a |\n  + b` |");
        $this->assertStringContainsString('<table>', $html);
        // Tight: the table opens a block, so the blank before it does not
        // loosen the item.
        $this->assertStringNotContainsString('<p>item</p>', $html);
    }

    public function testABarePipeAfterABlankLoosensTheItem(): void
    {
        // Follows from validating the row: a bare `|` is prose, so the blank
        // line before it separates two of the item's blocks. carve-js agrees.
        $html = $this->converter->convert("- item\n\n  |");
        $this->assertStringContainsString('<p>item</p>', $html);
        $this->assertStringContainsString('<p>|</p>', $html);
    }

    public function testANonRowPipeLineIsStillPlainTextAtTopLevel(): void
    {
        $this->assertSame("<p>|</p>\n", $this->converter->convert('|'));
        $this->assertSame("<p>|x</p>\n", $this->converter->convert('|x'));
    }
}
