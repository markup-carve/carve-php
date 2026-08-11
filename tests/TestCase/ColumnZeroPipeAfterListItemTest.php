<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A column-0 line after a list item whose only content is an EMPTY quote does
 * not continue the item, and the answer cannot depend on which character the
 * line happens to start with.
 *
 * The character-independence half was the original defect: a `|` detached the
 * line while `*`, `-` and `x` attached it, because isBlockElementStart()
 * accepted ANY line starting with a pipe as a table, while the
 * block-position predicate next to it validated the row first
 * (carve-php#683). A bare `|` is not a table row, so it behaves like the other
 * three.
 *
 * WHICH way all four go was open as markup-carve/carve#561 when this was
 * written, and this engine attached them. PART 1 S4 has since answered it -
 * NO OPEN PARAGRAPH, NO LAZY LINE - so they all detach now, and the assertions
 * flip while the property they check does not.
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
    public function testColumnZeroLineDetachesFromTheItemWhateverTheCharacter(string $char): void
    {
        $html = $this->converter->convert(". >\n" . $char);
        $this->assertStringNotContainsString($char . "\n  </li>", $html);
        $this->assertStringContainsString('<p>' . $char . '</p>', $html);
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
