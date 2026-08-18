<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A line block's body is verse, so a link reference definition written there
 * registers nothing (PART 9 §23; carve#557, carve#574).
 *
 * Registering it made the line RENDER and RESOLVE at the same time - the one
 * place in the language where a construct did both. A registered definition
 * renders nothing everywhere else.
 */
class LineBlockLinkDefinitionTest extends TestCase
{
    private function convert(string $source): string
    {
        return (new CarveConverter())->convert($source);
    }

    public function testADefinitionInsideVerseDoesNotResolveElsewhere(): void
    {
        $html = $this->convert("::: |\n[d]: http://x.de\n:::\n\nsee [d][]\n");

        $this->assertStringContainsString('[d]: http://x.de', $html);
        $this->assertStringNotContainsString('href="http://x.de"', $html);
    }

    public function testADefinitionAfterTheVerseStillResolves(): void
    {
        $html = $this->convert("::: |\nverse\n:::\n\n[d]: http://x.de\n\nsee [d][]\n");

        $this->assertStringContainsString('href="http://x.de"', $html);
    }

    public function testAWiderVerseFenceClosesOnItsOwnWidth(): void
    {
        $html = $this->convert(":::: |\n[d]: http://x.de\n:::\nstill verse\n::::\n\nsee [d][]\n");

        $this->assertStringNotContainsString('href="http://x.de"', $html);
    }

    public function testAFootnoteDefinitionInVerseIsStillLiteral(): void
    {
        $html = $this->convert("::: |\n[^f]: t\n:::\n");

        $this->assertStringContainsString('[^f]: t', $html);
        $this->assertStringNotContainsString('doc-endnotes', $html);
    }

    /**
     * WHERE the opener sits decides whether it is one, and both prepasses have
     * to answer that the same way. One trimmed the line and the other read it
     * raw, so an indented `::: |` was verse to the link-reference pass and prose
     * to the footnote pass, and a definition beside it registered in one and not
     * the other. Both now remove exactly the item's content column and nothing
     * else.
     *
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function verseColumnProvider(): array
    {
        return [
            // Three spaces is not any item's content column, so `::: |` is
            // prose and the definition under it is a definition.
            'an indented opener at top level is prose' => ["   ::: |\nDEF\n   :::\n\nUSE\n", true],
            // Inside the item it IS the content column, so the block opens and
            // the closer at that column ends it - the definition is below.
            'an opener at an item content column opens' => ["- item\n  ::: |\n  verse\n  :::\n\nDEF\n\nUSE\n", true],
            // The marker establishes the same content column on its own line;
            // both definition prepasses must strip it before probing the
            // line-block opener.
            'an opener on an item marker line opens' => ["- ::: |\n  DEF\n  :::\n\nUSE\n", false],
            // An indented `:::` inside a TOP-LEVEL line block is verse text and
            // closes nothing, so the definition under it is verse too.
            'an indented closer inside a top-level block is verse' => ["::: |\nline\n   :::\nDEF\n:::\n\nUSE\n", false],
            // An unclosed block ends with the item that held it, so a
            // definition written back at column 0 still registers.
            'an unclosed block in an item ends with the item' => ["- item\n  ::: |\n  verse\n\nDEF\n\nUSE\n", true],
        ];
    }

    #[DataProvider('verseColumnProvider')]
    public function testWhereTheVerseFenceSitsDecidesWhatItHolds(string $shape, bool $registers): void
    {
        $reference = $this->convert(str_replace(['DEF', 'USE'], ['[d]: http://x.de', 'see [d][]'], $shape));
        $footnote = $this->convert(str_replace(['DEF', 'USE'], ['[^f]: note', 'see [^f]'], $shape));

        if ($registers) {
            $this->assertStringContainsString('href="http://x.de"', $reference);
            $this->assertMatchesRegularExpression('/fnref|doc-noteref/', $footnote);

            return;
        }
        $this->assertStringNotContainsString('href="http://x.de"', $reference);
        $this->assertDoesNotMatchRegularExpression('/fnref|doc-noteref/', $footnote);
    }
}
