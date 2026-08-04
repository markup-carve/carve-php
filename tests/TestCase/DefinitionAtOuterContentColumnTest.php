<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A definition is collected or left as text, never both.
 *
 * `- - a` opens two items on one line, so two content columns are live: 2 for
 * the outer item and 4 for the inner one. A definition at either belongs to
 * that item and renders nothing; between them it reaches neither and folds as
 * item text (PART 9 §24 C3).
 *
 * The outer column used to do BOTH: the prepass registered the definition while
 * the inner item still rendered the line, so `[^f]` parsed as a reference
 * inside the item, took `id="fnref1"`, pushed the real reference to
 * `fnref1-2`, and the endnote grew a backlink to a place the author never wrote
 * (carve-php#783).
 */
class DefinitionAtOuterContentColumnTest extends TestCase
{
    protected CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function columnProvider(): array
    {
        return [
            'outer content column' => ['  '],
            'inner content column' => ['    '],
        ];
    }

    #[DataProvider('columnProvider')]
    public function testADefinitionAtAContentColumnRegistersAndRendersNothing(string $indent): void
    {
        $html = $this->converter->convert("- - a\n{$indent}[^f]: x\n\nsee[^f]\n");

        $this->assertStringContainsString('doc-endnotes', $html);
        $this->assertStringNotContainsString('[^f]: x', $html);
    }

    #[DataProvider('columnProvider')]
    public function testTheNoteIsClaimedByExactlyOneReference(string $indent): void
    {
        $html = $this->converter->convert("- - a\n{$indent}[^f]: x\n\nsee[^f]\n");

        $this->assertSame(1, substr_count($html, 'role="doc-noteref"'));
        $this->assertSame(1, substr_count($html, 'role="doc-backlink"'));
        $this->assertSame(1, substr_count($html, 'id="fnref1"'));
    }

    public function testBetweenTwoColumnsTheLineStaysTextAndDefinesNothing(): void
    {
        $html = $this->converter->convert("- - a\n   [^f]: x\n\nsee[^f]\n");

        $this->assertStringContainsString('[^f]: x', $html);
        $this->assertStringNotContainsString('doc-endnotes', $html);
    }

    public function testTheSameHoldsForALinkDefinition(): void
    {
        $html = $this->converter->convert("- - a\n  [r]: /u\n\nsee [t][r]\n");

        $this->assertStringContainsString('href="/u"', $html);
        $this->assertStringNotContainsString('[r]: /u', $html);
    }
}
