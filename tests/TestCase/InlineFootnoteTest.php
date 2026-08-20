<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

class InlineFootnoteTest extends TestCase
{
    private CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    public function testConformanceOutput(): void
    {
        $input = <<<'DJOT'
A note^[see *later*] inline. And a ref[^a].

[^a]: reference body.
DJOT;

        $expected = <<<'HTML'
<p>A note<a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a> inline. And a ref<a id="fnref2" href="#fn2" role="doc-noteref"><sup>2</sup></a>.</p>
<section role="doc-endnotes">
  <hr>
  <ol>
    <li id="fn1">
      <p>see <strong>later</strong><a href="#fnref1" role="doc-backlink" aria-label="Back to reference">↩</a></p>
    </li>
    <li id="fn2">
      <p>reference body.<a href="#fnref2" role="doc-backlink" aria-label="Back to reference">↩</a></p>
    </li>
  </ol>
</section>
HTML;

        $this->assertSame($expected . "\n", $this->converter->convert($input));
    }

    public function testInlineContentIsParsed(): void
    {
        $html = $this->converter->convert('Text^[a *bold*] here.');

        $this->assertStringContainsString('<p>a <strong>bold</strong><a href="#fnref1" role="doc-backlink" aria-label="Back to reference">↩</a></p>', $html);
    }

    public function testEmptyAndWhitespaceOnlyContentStayLiteral(): void
    {
        $html = $this->converter->convert('A ^[] and ^[ ] stay literal.');

        $this->assertStringContainsString('<p>A ^[] and ^[ ] stay literal.</p>', $html);
        $this->assertStringNotContainsString('doc-noteref', $html);
    }

    public function testUnclosedContentStaysLiteral(): void
    {
        $html = $this->converter->convert('A ^[unclosed note.');

        $this->assertStringContainsString('<p>A ^[unclosed note.</p>', $html);
        $this->assertStringNotContainsString('doc-noteref', $html);
    }

    public function testDoubleCaretIsLiteralCaretPlusNote(): void
    {
        // There is no bare superscript, so a `^` is plain text and the second
        // `^[` opens a note as anywhere else.
        $html = $this->converter->convert('A ^^[x] note.');

        $this->assertStringContainsString('doc-noteref', $html);
        $this->assertStringContainsString('A ^<a', $html);
    }

    public function testEscapedCaretStaysLiteral(): void
    {
        $html = $this->converter->convert('A \^[x] stays literal.');

        $this->assertStringContainsString('<p>A ^[x] stays literal.</p>', $html);
        $this->assertStringNotContainsString('doc-noteref', $html);
    }

    public function testEscapedBracketInContent(): void
    {
        $html = $this->converter->convert('A ^[a \] b] note.');

        $this->assertStringContainsString('<p>a ] b<a href="#fnref1" role="doc-backlink" aria-label="Back to reference">↩</a></p>', $html);
    }

    public function testCodeSpanBracketInContent(): void
    {
        $html = $this->converter->convert('A ^[a `]` b] note.');

        $this->assertStringContainsString('<p>a <code>]</code> b<a href="#fnref1" role="doc-backlink" aria-label="Back to reference">↩</a></p>', $html);
    }

    public function testNestedLinkInContent(): void
    {
        $html = $this->converter->convert('A ^[a [link](/u) b] note.');

        $this->assertStringContainsString('<p>a <a href="/u">link</a> b<a href="#fnref1" role="doc-backlink" aria-label="Back to reference">↩</a></p>', $html);
    }

    public function testFootnoteRefIsLiteralInsideInlineFootnote(): void
    {
        $input = <<<'DJOT'
A ^[inner [^a] ref] note.

[^a]: reference body.
DJOT;

        $html = $this->converter->convert($input);

        $this->assertSame(1, substr_count($html, 'role="doc-noteref"'));
        $this->assertStringContainsString('inner [^a] ref', $html);
        $this->assertStringNotContainsString('reference body', $html);
    }

    public function testNestedInlineFootnoteIsLiteralInsideInlineFootnote(): void
    {
        $html = $this->converter->convert('A ^[outer *^[inner]*] note.');

        $this->assertSame(1, substr_count($html, 'role="doc-noteref"'));
        $this->assertStringContainsString('outer <strong>^[inner]</strong>', $html);
    }

    public function testTrailingAttributesAttachToNoteref(): void
    {
        $html = $this->converter->convert('A ^[note]{.c} here.');

        $this->assertStringContainsString('<a id="fnref1" href="#fn1" role="doc-noteref" class="c"><sup>1</sup></a>', $html);
    }

    public function testReferenceTrailingAttributesAttachToNoteref(): void
    {
        // A trailing {attrs} on a reference noteref attaches to its <a>,
        // mirroring the inline-footnote form (grammar PART 9 §note).
        $html = $this->converter->convert("A ref[^a]{.c} here.\n\n[^a]: body.");

        $this->assertStringContainsString('<a id="fnref1" href="#fn1" role="doc-noteref" class="c"><sup>1</sup></a>', $html);
    }

    public function testReferenceTrailingAttributesOnlyOnAuthoredRef(): void
    {
        // The second reference to the same note carries no attribute block;
        // only the ref where the author wrote one gets the class.
        $html = $this->converter->convert("First[^a]{.c} then[^a].\n\n[^a]: body.");

        $this->assertStringContainsString('<a id="fnref1" href="#fn1" role="doc-noteref" class="c"><sup>1</sup></a>', $html);
        $this->assertStringContainsString('<a id="fnref1-2" href="#fn1" role="doc-noteref"><sup>1</sup></a>', $html);
    }

    public function testSuperscriptStillRenders(): void
    {
        $html = $this->converter->convert('x{^2^}');

        $this->assertSame("<p>x<sup>2</sup></p>\n", $html);
    }
}
