<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Targeted tests for the batch-conformance alignment to carve spec corpus
 * 48b088c: Trojan-Source heading-id + text/code hardening (#117/#118),
 * the list-item colon-fence opener with a nested-list body (#114), and
 * footnote definitions collected from inside a container (#115).
 *
 * These complement the corpus runner (CarveCorpusTest) with focused, readable
 * assertions so a regression points at the specific construct.
 */
class ConformanceBatchTest extends TestCase
{
    protected CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    /**
     * A precomposed `é` (U+00E9) and the decomposed `e` + combining acute
     * (U+0301) must NFC-normalize to the SAME heading id.
     */
    public function testHeadingIdNfcNormalizesDecomposedToPrecomposed(): void
    {
        $precomposed = $this->converter->convert("# Caf\u{00E9}\n");
        $decomposed = $this->converter->convert("# Cafe\u{0301}\n");

        $this->assertStringContainsString('<section id="Caf' . "\u{00E9}" . '">', $precomposed);
        $this->assertStringContainsString('<section id="Caf' . "\u{00E9}" . '">', $decomposed);
    }

    /**
     * A heading id strips bidi-override and zero-width controls so it can
     * never depend on invisible or reordering code points: `A<RLO>B<ZWSP>C`
     * yields the id `ABC`.
     */
    public function testHeadingIdStripsBidiAndZeroWidthControls(): void
    {
        $html = $this->converter->convert("# A\u{202E}B\u{200B}C\n");

        $this->assertStringContainsString('<section id="ABC">', $html);
    }

    /**
     * Rendered text strips the Trojan-Source bidi override/isolate controls
     * (removed, not entity-escaped -- an entity decodes back to the raw,
     * reorder-active control in the DOM).
     */
    public function testRenderedTextStripsBidiOverrideControls(): void
    {
        $html = $this->converter->convert("a\u{202E}b\n");

        $this->assertSame("<p>ab</p>\n", $html);
        $this->assertStringNotContainsString("\u{202E}", $html);
        $this->assertStringNotContainsString('202e', strtolower($html));
    }

    /**
     * Inline code strips the bidi controls too.
     */
    public function testRenderedCodeStripsBidiOverrideControls(): void
    {
        $html = $this->converter->convert("`a\u{202E}b`\n");

        $this->assertSame("<p><code>ab</code></p>\n", $html);
    }

    /**
     * LRM / zero-width characters are left untouched in normal text: only the
     * bidi reordering controls are stripped there (the wider strip set applies
     * only to heading ids).
     */
    public function testRenderedTextKeepsZeroWidthCharacters(): void
    {
        $html = $this->converter->convert("a\u{200B}b\n");

        $this->assertStringContainsString("a\u{200B}b", $html);
    }

    /**
     * A `::: note` opener that is the lead content of a list item opens as an
     * admonition wrapping its nested-list body when the matching closer sits
     * at the item content column.
     *
     * @param string $input
     * @param string $expected
     */
    #[DataProvider('fenceInListItemProvider')]
    public function testColonFenceOpenerCapturesNestedListBody(string $input, string $expected): void
    {
        $this->assertSame($expected, $this->converter->convert($input));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function fenceInListItemProvider(): array
    {
        return [
            'unordered nested list body' => [
                "- ::: note\n  - para text\n  :::\n",
                "<ul>\n  <li>\n    <aside class=\"admonition note\">\n"
                    . "      <ul>\n        <li>para text</li>\n      </ul>\n"
                    . "    </aside>\n  </li>\n</ul>\n",
            ],
            'ordered nested list body' => [
                "- ::: note\n  1. para text\n  :::\n",
                "<ul>\n  <li>\n    <aside class=\"admonition note\">\n"
                    . "      <ol>\n        <li>para text</li>\n      </ol>\n"
                    . "    </aside>\n  </li>\n</ul>\n",
            ],
            'empty body still opens' => [
                "- ::: note\n  :::\n",
                "<ul>\n  <li>\n    <aside class=\"admonition note\">\n\n"
                    . "    </aside>\n  </li>\n</ul>\n",
            ],
        ];
    }

    /**
     * Without a closer the `::: note` opener stays literal (no admonition).
     */
    public function testColonFenceOpenerStaysLiteralWithoutCloser(): void
    {
        $html = $this->converter->convert("- ::: note\n  - para text\n");

        $this->assertStringContainsString('<li>::: note', $html);
        $this->assertStringNotContainsString('admonition', $html);
    }

    /**
     * A closer dedented to column 0 leaves the item: the opener stays literal
     * and the `:::` is a top-level paragraph.
     */
    public function testColonFenceCloserAtColumnZeroKeepsOpenerLiteral(): void
    {
        $html = $this->converter->convert("- ::: note\n  - para text\n:::\n");

        $this->assertStringContainsString('<li>::: note', $html);
        $this->assertStringNotContainsString('admonition', $html);
        $this->assertStringContainsString('<p>:::</p>', $html);
    }

    /**
     * A footnote definition inside a blockquote is collected: the reference
     * resolves to an endnote and the blockquote renders empty.
     */
    public function testFootnoteDefinitionInsideBlockquoteIsCollected(): void
    {
        $html = $this->converter->convert("See [^a].\n\n> [^a]: note body\n");

        $this->assertStringContainsString('href="#fn1"', $html);
        $this->assertStringContainsString('role="doc-endnotes"', $html);
        $this->assertStringContainsString('note body', $html);
        $this->assertStringContainsString("<blockquote>\n\n</blockquote>", $html);
        $this->assertStringNotContainsString('[^a]:', $html);
    }

    /**
     * A footnote definition inside a list item is collected: the reference
     * resolves to an endnote and the list item renders empty.
     */
    public function testFootnoteDefinitionInsideListItemIsCollected(): void
    {
        $html = $this->converter->convert("See [^a].\n\n- [^a]: note body\n");

        $this->assertStringContainsString('href="#fn1"', $html);
        $this->assertStringContainsString('role="doc-endnotes"', $html);
        $this->assertStringContainsString('note body', $html);
        $this->assertStringContainsString("<ul>\n  <li></li>\n</ul>", $html);
    }

    /**
     * A footnote defined after an alpha (or other non-decimal) ordered marker
     * is collected -- the pre-pass uses the canonical list-marker parser, not a
     * hand-rolled subset, so every ordered marker is recognized.
     */
    public function testFootnoteDefinitionInsideAlphaOrderedItemIsCollected(): void
    {
        $html = $this->converter->convert("See [^a].\n\na. [^a]: note body\n");

        $this->assertStringContainsString('href="#fn1"', $html);
        $this->assertStringContainsString('role="doc-endnotes"', $html);
        $this->assertStringContainsString('note body', $html);
    }

    /**
     * Container-nested footnote definitions are collected only from a
     * single-line, non-empty body -- matching the oracle (carve-js). A
     * multi-line list body, an empty-bodied def, and a task-item `[^a]:` all
     * stay literal (the reference does not resolve).
     *
     * @param string $input
     */
    #[DataProvider('uncollectedContainerFootnoteProvider')]
    public function testContainerFootnoteVariantsStayLiteral(string $input): void
    {
        $html = $this->converter->convert($input);

        $this->assertStringNotContainsString('doc-endnotes', $html);
        $this->assertStringContainsString('[^a]', $html);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function uncollectedContainerFootnoteProvider(): array
    {
        return [
            'multi-line list body' => ["See [^a].\n\n- [^a]:\n  note body\n"],
            'empty list body' => ["See [^a].\n\n- [^a]:\n"],
            'task item is content not a footnote' => ["See [^a].\n\n- [ ] [^a]: note body\n"],
            // Reference AFTER the definition must agree with the reference-first
            // ordering: a variant that stays literal stays literal regardless of
            // where the reference sits (no second-pass collection of a skipped
            // container definition).
            'task item, reference after' => ["- [ ] [^a]: note body\n\nSee [^a].\n"],
            'multi-line list body, reference after' => ["- [^a]:\n  note body\n\nSee [^a].\n"],
            // A definition shown inside fenced code is literal code content,
            // never a real definition -- for top level and inside a container.
            'fenced code top-level definition' => ["See [^a].\n\n```\n[^a]: note\n```\n"],
            'fenced code blockquote definition' => ["See [^a].\n\n```\n> [^a]: note\n```\n"],
            // A code fence INSIDE a blockquote: the `> [^a]: note` is quoted code
            // content, not a real definition -- at any blockquote depth.
            'fenced code inside a blockquote' => ["See [^a].\n\n> ```\n> [^a]: note\n> ```\n"],
            'fenced code inside a nested blockquote' => ["See [^a].\n\n> > ```\n> > [^a]: note\n> > ```\n"],
            'fenced code inside a list item' => ["See [^a].\n\n- ```\n  [^a]: note\n  ```\n"],
            // An indented `- [^a]:` that lazily continues a preceding paragraph
            // is NOT a definition -- the real parser keeps it in the paragraph.
            'lazy continuation list marker' => ["Para\n  - [^a]: note\n\nSee [^a].\n"],
        ];
    }

    /**
     * A single-line container footnote resolves regardless of whether the
     * reference appears before or after the definition (the pre-pass collects
     * it in document order, independent of reference position).
     *
     * @param string $input
     */
    #[DataProvider('collectedContainerFootnoteOrderProvider')]
    public function testContainerFootnoteResolvesIndependentOfReferenceOrder(string $input): void
    {
        $html = $this->converter->convert($input);

        $this->assertStringContainsString('href="#fn1"', $html);
        $this->assertStringContainsString('role="doc-endnotes"', $html);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function collectedContainerFootnoteOrderProvider(): array
    {
        return [
            'blockquote, reference before' => ["See [^a].\n\n> [^a]: note body\n"],
            'blockquote, reference after' => ["> [^a]: note body\n\nSee [^a].\n"],
            'list, reference before' => ["See [^a].\n\n- [^a]: note body\n"],
            'list, reference after' => ["- [^a]: note body\n\nSee [^a].\n"],
            // Deeper nesting (blockquote-in-blockquote, list-in-blockquote) is
            // also collected -- the pre-pass strips container markers at any
            // depth.
            'blockquote in blockquote' => ["See [^a].\n\n> > [^a]: note body\n"],
            'list in blockquote' => ["See [^a].\n\n> - [^a]: note body\n"],
        ];
    }

    /**
     * The first definition of a footnote label wins: a later container-nested
     * definition does not overwrite an earlier one.
     */
    public function testFirstFootnoteDefinitionWinsOverLaterContainerDefinition(): void
    {
        $html = $this->converter->convert("[^a]: first\n\n> [^a]: second\n\nSee [^a].\n");

        $this->assertStringContainsString('first', $html);
        $this->assertStringNotContainsString('second', $html);
    }

    /**
     * First-wins also holds in the other order: an earlier container definition
     * is not overwritten by a later top-level definition of the same label.
     */
    public function testEarlierContainerDefinitionWinsOverLaterTopLevelDefinition(): void
    {
        $html = $this->converter->convert("> [^a]: first\n\n[^a]: second\n\nSee [^a].\n");

        $this->assertStringContainsString('first', $html);
        $this->assertStringNotContainsString('second', $html);
    }
}
