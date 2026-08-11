<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A definition inside a footnote body is collected at the body's own column of
 * TWO and nowhere else (PART 9 §16, spelled out in carve#717).
 *
 * The prepass used to strip ALL leading whitespace inside a note body, so a
 * definition at ANY indent was registered - including at columns where the body
 * renders the line as prose. At indents 1, 3 and 4 the reader saw `[r]: /u` in
 * the note text while `[t][r]` silently resolved through the same line. That is
 * the `VA` rows of carve#669 and carve#701, and it is the combination
 * carve-php#767 already ruled out from the other side: a definition is either
 * collected or left as text, never both.
 *
 * Each case asserts BOTH halves, because either alone passes on a wrong answer -
 * "renders" alone passes when the line is also active, "resolves" alone passes
 * when the line is also printed. Measured against the executable spec, which
 * agrees on every row.
 */
class FootnoteBodyDefinitionColumnTest extends TestCase
{
    protected function html(string $source): string
    {
        return (new CarveConverter())->convert($source);
    }

    protected function document(int $indent): string
    {
        return "[^a]: note\n\n" . str_repeat(' ', $indent) . "[r]: /u\n\nsee[^a] and [t][r]\n";
    }

    public function testDefinesAtTheBodyColumnOfTwo(): void
    {
        $html = $this->html($this->document(2));

        $this->assertStringNotContainsString('[r]: /u', $html);
        $this->assertStringContainsString('href="/u"', $html);
    }

    public function testDefinesAtColumnZeroWhereTheLineEndsTheBody(): void
    {
        // A flush-left line is not a continuation at all, so this is the
        // document's own definition. Kept as the control that shows the column
        // test did not simply reject everything.
        $html = $this->html($this->document(0));

        $this->assertStringNotContainsString('[r]: /u', $html);
        $this->assertStringContainsString('href="/u"', $html);
    }

    public function testIsTextBelowTheBodyColumnAndInert(): void
    {
        // One space is too little for a continuation, so the line is the
        // document's next block - and the production allows no leading indent.
        $html = $this->html($this->document(1));

        $this->assertStringContainsString('[r]: /u', $html);
        $this->assertStringNotContainsString('href="/u"', $html);
    }

    /**
     * @return array<string, array<int>>
     */
    public static function pastTheColumnProvider(): array
    {
        return ['one column in' => [3], 'two columns in' => [4]];
    }

    /**
     * Inside the body, but above its content column: the body's blocks read the
     * residual indent and the line is paragraph text there, exactly as above a
     * list item's content column (§24 C3).
     */
    #[DataProvider('pastTheColumnProvider')]
    public function testIsTextPastTheBodyColumnAndInert(int $indent): void
    {
        $html = $this->html($this->document($indent));

        $this->assertStringContainsString('[r]: /u', $html);
        $this->assertStringNotContainsString('href="/u"', $html);
    }

    public function testNeverRendersALineItAlsoDefinesFrom(): void
    {
        // The invariant behind every case above, stated once: whatever the
        // indent, the line is content or metadata, never both.
        for ($indent = 0; $indent <= 6; $indent++) {
            $html = $this->html($this->document($indent));
            $both = str_contains($html, '[r]: /u') && str_contains($html, 'href="/u"');
            $this->assertFalse($both, "indent {$indent}: {$html}");
        }
    }

    public function testStillCollectsADefinitionInsideAListInsideTheBody(): void
    {
        // Two is the body's floor, not a ceiling: an item opened at two puts its
        // content column at four and a definition there belongs to the item.
        $html = $this->html("see[^a] and [t][r]\n\n[^a]: note\n\n  - item\n\n[r]: /u\n");

        $this->assertStringNotContainsString('[r]: /u', $html);
        $this->assertStringContainsString('href="/u"', $html);
    }

    public function testCollectsAPlusAttachedDefinitionAtColumnZero(): void
    {
        // The continuation marker attaches a FLUSH-LEFT block to the note
        // (§17 L4), so the column that counts after a `+` is zero, not two.
        $html = $this->html("see[^a] and [t][r]\n\n[^a]: note\n\n[r]: /u\n");

        $this->assertStringNotContainsString('[r]: /u', $html);
        $this->assertStringContainsString('href="/u"', $html);
    }
}
