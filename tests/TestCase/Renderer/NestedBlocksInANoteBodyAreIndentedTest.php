<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * A nested block inside a footnote body lands on the same columns the other
 * engines use.
 *
 * `indentFootnoteBody()` pads the first line and any line that STARTS with a
 * tag. carve-php#815 fixed the half where that reached inside a `<pre>`; the
 * other half is that a nested block line does not start with the tag at all -
 * it starts with its own indentation - so a table, a list or a task list in a
 * note was left under-indented against carve-js and carve-rs.
 *
 * Only whitespace outside verbatim content, so nothing here changes what a
 * document means. It changes whether a corpus case pinning one of these shapes
 * can pass in all three engines, which today it cannot.
 */
class NestedBlocksInANoteBodyAreIndentedTest extends TestCase
{
    protected function html(string $source): string
    {
        return (new CarveConverter())->convert($source);
    }

    public function testATableInANoteBodyIsIndentedLikeItsSiblings(): void
    {
        $html = $this->html("[^a]: note\n\n  | a |\n  | - |\n  | b |\n\nsee[^a]\n");

        $this->assertStringContainsString('      <table>', $html);
        $this->assertStringContainsString('        <thead>', $html, 'the nested row was left under-indented');
    }

    public function testAListInANoteBodyIsIndentedLikeItsSiblings(): void
    {
        $html = $this->html("[^a]: note\n\n  - one\n  - two\n\nsee[^a]\n");

        $this->assertStringContainsString('      <ul>', $html);
        $this->assertStringContainsString('        <li>one</li>', $html);
    }

    /**
     * The boundary the original tag test was protecting: a paragraph's
     * soft-break continuation is plain text at column 0 and must stay there,
     * because indenting it would add whitespace to the rendered text.
     */
    public function testASoftBreakContinuationStaysAtColumnZero(): void
    {
        $html = $this->html("[^a]: first\n  second\n\nsee[^a]\n");

        $this->assertStringContainsString("\nsecond", $html);
    }
}
