<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\Test\LegacyCarveConverter as CarveConverter;
use MarkupCarve\Carve\Node\Block\CodeBlock;
use MarkupCarve\Carve\Node\Block\Footnote;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Node\Inline\Text;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PART 11 §7: THE WRITER NEVER EMITS A WHITESPACE-ONLY LINE.
 *
 * A blank line inside a fenced code block nested in a FOOTNOTE BODY or a
 * DEFINITION BODY came back as a line holding the container's indent and
 * nothing else (markup-carve/carve-php#1068). Such a line is not stable:
 * editors that strip trailing whitespace on save, `git apply --whitespace=fix`
 * and CI whitespace checks all rewrite it, so the formatter produced output
 * ordinary tooling changes behind it (markup-carve/carve#375).
 *
 * WHY THE INDENT SURVIVES A BLANK. A blank line inside verbatim content is
 * CONTENT, so protectVerbatim() encodes it under a sentinel to keep the
 * document-wide trim off it. The container then prefixes it with its indent,
 * like any other continuation line, and restoreVerbatim() maps the sentinel
 * back to nothing at the very end - leaving the indent alone on the line.
 *
 * The equivalent LIST spelling was already correct, because the list writer
 * carried its own blank-continuation rule. Three writers indent a block body
 * and one of them knew the rule; they share it now.
 *
 * Cross-engine, measured rather than assumed: carve-js `62e0e5a` emits a
 * genuinely empty line for both shapes and never carried this. carve-rs
 * `39e6968` carries BOTH: it emits the two-space and three-space indents on
 * their own lines.
 */
class ABlankInsideAnIndentedVerbatimBlockIsEmptyTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function shapes(): array
    {
        // Neither shape involves a `+` continuation marker: both reproduce from
        // ordinary source, which is what makes them the writer's bug rather than
        // an attachment one.
        return [
            'footnote body' => ["[^f]: n\n  ```\n  a\n\n  b\n  ```\n\nsee[^f]\n"],
            'definition body' => [":: t\n:  d\n   ```\n   a\n\n   b\n   ```\n"],
            'raw block in a footnote body' => ["[^f]: n\n  ```=html\n  a\n\n  b\n  ```\n\nsee[^f]\n"],
            'block comment in a definition body' => [":: t\n:  d\n   %%%\n   a\n\n   b\n   %%%\n"],
            'definition inside a list item' => ["- x\n\n  :: t\n  :  d\n     ```\n     a\n\n     b\n     ```\n"],
            'list inside a footnote body' => ["[^f]: n\n\n  - x\n    ```\n    a\n\n    b\n    ```\n\nsee[^f]\n"],
        ];
    }

    /**
     * @return list<int>
     */
    protected function whitespaceOnlyLines(string $out): array
    {
        $found = [];
        foreach (explode("\n", $out) as $i => $line) {
            if ($line !== '' && trim($line, " \t") === '') {
                $found[] = $i + 1;
            }
        }

        return $found;
    }

    #[DataProvider('shapes')]
    public function testTheBlankLineIsEmittedEmpty(string $source): void
    {
        $out = CarveConverter::toCarve($source);

        $this->assertSame(
            [],
            $this->whitespaceOnlyLines($out),
            "fmt emitted a whitespace-only line:\n" . str_replace(' ', '.', $out),
        );
    }

    #[DataProvider('shapes')]
    public function testTheBlankLineIsStillThereAndTheDocumentStillRoundTrips(string $source): void
    {
        // The blank is CONTENT - dropping the line entirely would satisfy §7 and
        // destroy the code block. Both invariants are asserted so that "no
        // whitespace-only line" cannot be reached by deletion.
        $out = CarveConverter::toCarve($source);
        $converter = new CarveConverter();

        $this->assertStringContainsString("\n\n", $out, 'the blank line was dropped, not emptied');
        $this->assertSame($converter->convert($source), $converter->convert($out));
        $this->assertSame($out, CarveConverter::toCarve($out), 'fmt is not idempotent here');
    }

    public function testTheFootnoteBodyKeepsItsTwoSpaceColumn(): void
    {
        // The exact bytes, so a fix that stopped indenting the body at all would
        // be caught. Only the blank line loses the indent.
        $this->assertSame(
            "see[^f]\n\n[^f]: n\n\n  ```\n  a\n\n  b\n  ```\n",
            CarveConverter::toCarve("[^f]: n\n  ```\n  a\n\n  b\n  ```\n\nsee[^f]\n"),
        );
    }

    public function testTheDefinitionBodyKeepsItsThreeSpaceColumn(): void
    {
        $this->assertSame(
            ":: t\n:  d\n\n   ```\n   a\n\n   b\n   ```\n",
            CarveConverter::toCarve(":: t\n:  d\n   ```\n   a\n\n   b\n   ```\n"),
        );
    }

    public function testTheListSpellingIsUnchanged(): void
    {
        // A CONTROL. The list writer already carried this rule, so this document
        // was correct before and after and no mutation of the footnote or
        // definition writers moves it. It is here because sharing the rule
        // touched the list writer too, and a control is what says that touch
        // changed nothing.
        $this->assertSame(
            "- x\n  ```\n  a\n\n  b\n  ```\n",
            CarveConverter::toCarve("- x\n  ```\n  a\n\n  b\n  ```\n"),
        );
    }

    public function testAVerbatimLineOfREALSpacesIsNotBlankAndSurvives(): void
    {
        // The other CONTROL, and the boundary the rule turns on: a code line
        // that genuinely holds spaces is NOT blank. It arrives under a different
        // sentinel and keeps its spaces. Treating "looks blank once restored"
        // as blank would delete an author's whitespace-only code line, and this
        // row is what would catch that.
        $source = "```\na\n   \nb\n```\n";

        $this->assertSame($source, CarveConverter::toCarve($source));
    }

    public function testTheSameLineInsideAContainerIsAlreadyBlankBeforeTheWriterRuns(): void
    {
        // Measured, and the reason the control above is written at TOP LEVEL: an
        // authored whitespace-only line inside an INDENTED fence is blanked by
        // the PARSER, which strips the container's column and leaves a line of
        // whitespace that PART 1 calls blank. So there is no three-space content
        // line for the writer to preserve here at all, and a probe placed inside
        // the container would have been asking the writer a question the parser
        // had already answered.
        //
        // carve-js 62e0e5a does exactly the same, on both the list and the
        // footnote spelling, so this is not a divergence and not this ticket.
        $this->assertStringContainsString(
            "<pre><code>a\n\nb\n</code></pre>",
            (new CarveConverter())->convert("- x\n  ```\n  a\n     \n  b\n  ```\n"),
        );
    }

    public function testARealSpaceVerbatimLineReachesTheIndenterThroughTheApiDoor(): void
    {
        // THE ROW THAT PINS THE BOUNDARY, through the door that reaches it. A
        // mutation widening the blank test to "whitespace once the sentinels are
        // resolved" SURVIVES every source-built probe, because the parser has
        // already blanked such a line before the writer sees it (the row above).
        // A host that builds the tree itself never passes that, so this is where
        // the widened test would delete an author's content - and the only place
        // the difference is observable at all.
        $document = new Document();
        $footnote = new Footnote('f');
        $paragraph = new Paragraph();
        $paragraph->appendChild(new Text('n'));
        $footnote->appendChild($paragraph);
        $code = new CodeBlock();
        $code->setContent("a\n   \nb");
        $footnote->appendChild($code);
        $document->appendChild($footnote);

        $this->assertSame(
            "[^f]: n\n\n  ```\n  a\n     \n  b\n  ```\n",
            (new CarveRenderer())->render($document),
        );
    }
}
