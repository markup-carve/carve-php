<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PART 4: THE INLINE INTERIOR IS SPACE-ONLY, THE BLOCK-ATTRIBUTE LINE IS NOT
 * (markup-carve/carve#906).
 *
 * Every whitespace slot of the INLINE attribute block is spelled `space`, which
 * is one character. There are FIVE of them and they are checked one at a time,
 * because they are five separate positions rather than one rule with five
 * spellings - narrowing the separator alone leaves `[x]{<TAB>}` a valid empty
 * block, and the corpus document that pins it stays green. The executable spec
 * needed two edits for exactly that reason.
 *
 * WHAT DOES NOT NARROW. The block-attribute LINE keeps `whitespace` at all
 * three of its slots, and that distinction is the ruling rather than an
 * omission: it is the one construct whose interior can hold a leading
 * indentation run, because after a `continuation` the next line's leading
 * whitespace IS indentation. Every case below has its block-line counterpart in
 * the same file, so a fix that narrowed both surfaces at once fails here as well
 * as on corpus category 273.
 *
 * A TAB INSIDE A QUOTED VALUE is content and does not move.
 */
class InlineAttributeInteriorIsSpaceOnlyTest extends TestCase
{
    private CarveConverter $converter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->converter = new CarveConverter();
    }

    /**
     * The five positions, each with its tab form and its space form.
     *
     * The tab forms are written with `\t` in a double-quoted string rather than
     * as literal whitespace, so no formatter can collapse the thing under test.
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function slotProvider(): array
    {
        return [
            'the run after the opening brace' => [
                "*y*{\t.c}\n",
                "*y*{ .c}\n",
                "<p><strong>y</strong>{\t.c}</p>\n",
            ],
            'the run between two attributes' => [
                "*x*{.a\t.b}\n",
                "*x*{.a .b}\n",
                "<p><strong>x</strong>{.a\t.b}</p>\n",
            ],
            'the run before the closing brace' => [
                "*z*{.d\t}\n",
                "*z*{.d }\n",
                "<p><strong>z</strong>{.d\t}</p>\n",
            ],
            // A tab after an unquoted value ENDS the value and then satisfies no
            // separator either, so the whole block fails.
            'the boundary after an unquoted value' => [
                "*x*{k=a\t.b}\n",
                "*x*{k=a .b}\n",
                "<p><strong>x</strong>{k=a\t.b}</p>\n",
            ],
            // A SEPARATE POSITION rather than a use of the separator, and the
            // one most likely to be missed.
            'the blessed empty block' => [
                "[x]{\t}\n",
                "[x]{ }\n",
                "<p>[x]{\t}</p>\n",
            ],
        ];
    }

    #[DataProvider('slotProvider')]
    public function testATabInTheSlotMakesTheBlockUnrecognized(string $tab, string $space, string $expected): void
    {
        $this->assertSame($expected, $this->converter->convert($tab));
    }

    /**
     * THE SPACE FORM STILL WORKS, in the same slot. Without this a fix that
     * rejected the whole construct would pass every assertion above.
     *
     * @param string $tab
     * @param string $space
     * @param string $expected
     */
    #[DataProvider('slotProvider')]
    public function testTheSpaceFormInTheSameSlotIsUnchanged(string $tab, string $space, string $expected): void
    {
        $html = $this->converter->convert($space);

        $this->assertStringNotContainsString('{', $html, 'the space form is a recognized block');
        $this->assertMatchesRegularExpression('/<(strong|span)[ >]/', $html);
    }

    /**
     * A SLOT RULE IMPLEMENTED AS "the first character is a space" passes a
     * tab-first fixture and still admits `<SP><TAB>`; the mirror spelling admits
     * `<TAB><SP>`. Both have been written for real in this org, so both orders
     * are checked in every slot that takes a run.
     *
     * @return array<string, array{string}>
     */
    public static function mixedRunProvider(): array
    {
        return [
            'space then tab after the opening brace' => ["*y*{ \t.c}\n"],
            'tab then space after the opening brace' => ["*y*{\t .c}\n"],
            'space then tab between two attributes' => ["*x*{.a \t.b}\n"],
            'tab then space between two attributes' => ["*x*{.a\t .b}\n"],
            'space then tab before the closing brace' => ["*z*{.d \t}\n"],
            'tab then space before the closing brace' => ["*z*{.d\t }\n"],
            'space then tab in the empty block' => ["[x]{ \t}\n"],
            'tab then space in the empty block' => ["[x]{\t }\n"],
        ];
    }

    #[DataProvider('mixedRunProvider')]
    public function testAMixedRunFailsInEitherOrder(string $source): void
    {
        $this->assertStringContainsString('{', $this->converter->convert($source), 'the braces show');
    }

    /**
     * A TAB INSIDE A QUOTED VALUE is content, and the block still applies.
     */
    public function testATabInsideAQuotedValueIsContent(): void
    {
        $this->assertSame(
            "<p><strong k=\"a\tb\">y</strong></p>\n",
            $this->converter->convert("*y*{k=\"a\tb\"}\n"),
        );
    }

    public function testATabInsideASingleQuotedValueIsContent(): void
    {
        $this->assertSame(
            "<p><strong k=\"a\tb\">y</strong></p>\n",
            $this->converter->convert("*y*{k='a\tb'}\n"),
        );
    }

    /**
     * An escaped quote inside a quoted value does NOT close it, so a tab after
     * it is still content. A scanner that tracked quoting without the escape
     * would leave the quoted state early and refuse this block.
     */
    public function testAnEscapedQuoteDoesNotEndTheQuotedValue(): void
    {
        $html = $this->converter->convert("*y*{k=\"a\\\"b\tc\"}\n");

        $this->assertStringNotContainsString('{', $html);
        $this->assertStringContainsString('<strong', $html);
    }

    /**
     * FIVE PRODUCTIONS ALIAS THE INLINE BLOCK, not one.
     *
     * The ruling is written about "the INLINE attribute block", and `attributes`
     * is also what `item_attributes`, `row_attributes`, `cell_attributes` and a
     * reference definition's trailing `[space, attributes]` slot resolve to. All
     * four read a tab as a separator too, and none of them is named anywhere in
     * the ticket - found by sweeping the grammar for `= attributes ;` rather
     * than by reading the write-up.
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function aliasProvider(): array
    {
        return [
            'a list item attribute block' => [
                "-{.a\t.b} x\n",
                "-{.a .b} x\n",
                'class="a b"',
            ],
            'a table cell attribute block' => [
                "|{.a\t.b} c |\n|---|\n| b |\n",
                "|{.a .b} c |\n|---|\n| b |\n",
                'class="a b"',
            ],
            'a table row attribute block' => [
                "| a |{.a\t.b}\n|---|\n| b |\n",
                "| a |{.a .b}\n|---|\n| b |\n",
                'class="a b"',
            ],
            'a reference definition attribute block' => [
                "[r]: /u {.a\t.b}\n\n[t][r]\n",
                "[r]: /u {.a .b}\n\n[t][r]\n",
                'class="a b"',
            ],
        ];
    }

    /**
     * @param string $tab
     * @param string $space
     * @param string $applied
     */
    #[DataProvider('aliasProvider')]
    public function testEveryAliasOfTheInlineBlockNarrowsToo(string $tab, string $space, string $applied): void
    {
        $this->assertStringNotContainsString($applied, $this->converter->convert($tab));
    }

    /**
     * And the SPACE form of each still applies, or the four assertions above
     * would pass on an alias that had simply stopped working.
     *
     * @param string $tab
     * @param string $space
     * @param string $applied
     */
    #[DataProvider('aliasProvider')]
    public function testTheSpaceFormOfEveryAliasIsUnchanged(string $tab, string $space, string $applied): void
    {
        $this->assertStringContainsString($applied, $this->converter->convert($space));
    }

    /**
     * A REJECTED TRAILING BLOCK ON A REFERENCE DEFINITION MAKES THE LINE PROSE.
     *
     * THE ANSWER HERE MOVED, and this docblock keeps both halves because the
     * file was written to be where they meet. It first recorded a `codex
     * review` finding - that `reference_definition` is ANCHORED AT END OF LINE,
     * so a rejected attribute block leaves trailing source the way `[r]: /u zzz`
     * does and should make the line prose - and DISMISSED it, on a measurement
     * against the executable spec at the then-pinned revision, where
     * `[r]: /u {.a<TAB>.b}` still resolved without its attributes.
     *
     * markup-carve/carve#933 has since ruled for the finding: `[space,
     * attributes]` names the `attributes` production, a balanced `{...}` that
     * production does not accept is not an instance of it, and the anchor
     * disposes of the leftover like any other. So every rejected trailer below
     * is a paragraph now. The reviewer was right and the measurement was of a
     * reader, which is what PART 0 says a measurement is.
     *
     * @return array<string, array{string, string}>
     */
    public static function definitionTrailerProvider(): array
    {
        return [
            'a valid block applies' => ["[r]: /u {.a .b}\n\n[t][r]\n", "<p><a href=\"/u\" class=\"a b\">t</a></p>\n"],
            'a tab-bearing block is prose'
                => ["[r]: /u {.a\t.b}\n\n[t][r]\n", "<p>[r]: /u {.a\t.b}</p>\n<p>[t][r]</p>\n"],
            'an invalid NAME is prose' => ["[r]: /u {.1}\n\n[t][r]\n", "<p>[r]: /u {.1}</p>\n<p>[t][r]</p>\n"],
            'trailing prose is not a definition at all'
                => ["[r]: /u zzz\n\n[t][r]\n", "<p>[r]: /u zzz</p>\n<p>[t][r]</p>\n"],
        ];
    }

    /**
     * @param string $source
     * @param string $expected
     */
    #[DataProvider('definitionTrailerProvider')]
    public function testARejectedDefinitionTrailerIsProse(string $source, string $expected): void
    {
        $this->assertSame($expected, $this->converter->convert($source));
    }

    /**
     * THE BLOCK-ATTRIBUTE LINE IS NOT NARROWED. Both forms the ruling names
     * stay valid, and each is the counterpart of an inline case above.
     *
     * @return array<string, array{string}>
     */
    public static function attributeLineProvider(): array
    {
        return [
            // All three slots of the LINE, in one document.
            'tabs at every slot of the line' => ["{\t.a\t.b\t}\n\nparagraph\n"],
            // After a `continuation`, the next line's leading whitespace IS
            // indentation - which is the whole reason this surface keeps
            // `whitespace`.
            'a tab-indented continuation line' => ["{.a\n\t.b}\n\nparagraph\n"],
        ];
    }

    #[DataProvider('attributeLineProvider')]
    public function testTheBlockAttributeLineStillTakesATab(string $source): void
    {
        $this->assertSame("<p class=\"a b\">paragraph</p>\n", $this->converter->convert($source));
    }

    /**
     * The narrowed forms SURVIVE THE WRITER.
     *
     * A block that no longer reads is now literal text, and literal text has to
     * come back out as the same literal text - otherwise the fix moves the
     * document on the way through the formatter instead of on the way in.
     *
     * @param string $tab
     * @param string $space
     * @param string $expected
     */
    #[DataProvider('slotProvider')]
    public function testTheNarrowedFormSurvivesTheWriter(string $tab, string $space, string $expected): void
    {
        $writer = new CarveRenderer();
        $written = $writer->render($this->converter->parse($tab));

        $this->assertSame($expected, $this->converter->convert($written), 'the writer changed the document');
        $this->assertSame(
            $written,
            $writer->render($this->converter->parse($written)),
            'the writer is not idempotent on this shape',
        );
    }

    /**
     * A VALUE CARRYING A TAB IS WRITTEN QUOTED.
     *
     * The narrowing makes an unquoted value's tab boundary fatal, so a tree
     * holding such a value - which the AST codec accepts - must not be written
     * unquoted, or the writer would produce a document the parser now refuses.
     */
    public function testAValueCarryingATabIsWrittenQuoted(): void
    {
        $document = $this->converter->parse("*x*{k=v}\n");
        $strong = $document->getChildren()[0]->getChildren()[0];
        $strong->setAttribute('k', "a\tb");

        $written = (new CarveRenderer())->render($document);

        $this->assertSame("*x*{k=\"a\tb\"}\n", $written);
        $this->assertStringContainsString('<strong', $this->converter->convert($written));
    }
}
