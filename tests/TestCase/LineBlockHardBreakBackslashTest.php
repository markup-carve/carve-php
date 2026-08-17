<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A line block's hard break keeps its backslash where a bare newline would be
 * re-read (PART 11 §7c; markup-carve/carve#1334).
 *
 * A line block hardens every line boundary of its own accord (PART 9 §23), so
 * the writer spelled every `hard_break` inside one as a bare newline. That is
 * right for most lines and wrong for two, and the two are where §7's own
 * precondition fails: §7 may strip a line's trailing whitespace only because
 * "where the PARSER discards trailing whitespace the writer may too", and the
 * parser does NOT discard it when a backslash follows, because PART 7 makes the
 * run before a line-break backslash INTERIOR.
 *
 * A RENDER ASSERTION CANNOT SEE ANY OF THIS, which is why all three failures
 * survived: the first parse is correct in every one of them, and only the
 * writer's own output re-read betrays the loss. So the assertions below are
 * PART 11 §1's invariant and its idempotence, plus the canonical bytes the
 * corpus pins.
 *
 * The backslash is NOT redundant syntax the writer may drop. It is what lets a
 * verse line keep a LONE trailing space, and a `\` alone on a body line is how
 * a stanza carries an EMPTY verse line - the blank line being the one spelling
 * that would end the stanza instead. Both are observable in the HTML.
 */
class LineBlockHardBreakBackslashTest extends TestCase
{
    private function convert(string $source): string
    {
        return (new CarveConverter())->convert($source);
    }

    /**
     * The three documents the ruling names, plus the control that must NOT gain
     * a backslash.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function canonicalFormProvider(): array
    {
        return [
            'a lone trailing space is held interior by the backslash' => [
                "::: |\na \\\nb\n:::\n",
                "::: |\na \\\nb\n:::\n",
            ],
            'a backslash-only line carries an empty verse line' => [
                "::: |\na\n\\\nb\n:::\n",
                "::: |\na\n\\\nb\n:::\n",
            ],
            'the last body line keeps its break and the space before it' => [
                "::: |\na \\\n:::\n",
                "::: |\na \\\n:::\n",
            ],
            'the last body line keeps a break with no space before it' => [
                "::: |\na\\\n:::\n",
                "::: |\na\\\n:::\n",
            ],
            // TWO OR MORE trailing columns are already NBSP CONTENT (§23 MEDIAL
            // GAPS), so the parser keeps them without help and the writer must
            // not add a backslash it does not need.
            'a medial gap needs no backslash' => [
                "::: |\na  \\\nb\n:::\n",
                "::: |\na  \nb\n:::\n",
            ],
            // AN ESCAPED SPACE IS NOT EXEMPT, and this one was broken the same
            // way before the rule. The line block drops a lone trailing COLUMN
            // before the inline reader sees the escape, so a bare newline
            // returns a hard break with the non-breaking space gone.
            'an escaped space still needs the backslash' => [
                "::: |\na\\ \\\nb\n:::\n",
                "::: |\na\\ \\\nb\n:::\n",
            ],
            // An ordinary boundary is still a bare newline. This is the row
            // that fails if the rule is widened to every break.
            'an ordinary line boundary stays a bare newline' => [
                "::: |\na\nb\n:::\n",
                "::: |\na\nb\n:::\n",
            ],
        ];
    }

    #[DataProvider('canonicalFormProvider')]
    public function testTheWriterEmitsTheCanonicalForm(string $source, string $expected): void
    {
        $this->assertSame($expected, CarveConverter::toCarve($source));
    }

    #[DataProvider('canonicalFormProvider')]
    public function testFormattingHoldsTheInvariantAndIsIdempotent(string $source, string $expected): void
    {
        $this->assertNotSame('', $expected);
        $formatted = CarveConverter::toCarve($source);

        $this->assertSame(
            $this->convert($source),
            $this->convert($formatted),
            'fmt changed the rendering of: ' . $source,
        );
        $this->assertSame(
            $formatted,
            CarveConverter::toCarve($formatted),
            'fmt is not idempotent on: ' . $source,
        );
    }

    /**
     * ONE LINE BOUNDARY, ONE BREAK. §23's rule hardens SOFT breaks, and a
     * `hard_break` consumes its own newline, so nothing survives for the
     * container to convert. The additive reading would synthesize a break node
     * no production yields.
     */
    public function testABackslashBreakIsNotAdditive(): void
    {
        $expected = <<<'HTML'
        <div class="line-block">
          <p>a <br>
        b</p>
        </div>

        HTML;

        $this->assertSame($expected, $this->convert("::: |\na \\\nb\n:::\n"));
    }

    public function testABackslashOnlyLineIsOneEmptyVerseLine(): void
    {
        $expected = <<<'HTML'
        <div class="line-block">
          <p>a<br>
        <br>
        b</p>
        </div>

        HTML;

        $this->assertSame($expected, $this->convert("::: |\na\n\\\nb\n:::\n"));
    }

    /**
     * The stanza that a bare newline used to return as TWO. The rendering is
     * unchanged by this fix, so only the round trip catches it.
     */
    public function testABackslashOnlyLineStillReturnsOneStanza(): void
    {
        $formatted = CarveConverter::toCarve("::: |\na\n\\\nb\n:::\n");

        $this->assertStringNotContainsString("\n\n", $formatted);
        $this->assertSame(1, substr_count($this->convert($formatted), '<p>'));
    }

    /**
     * Outside a line block nothing changes: a `hard_break` has always been
     * written with its backslash there, and it still is.
     */
    public function testAParagraphHardBreakIsUnchanged(): void
    {
        $this->assertSame("a\\\nb\n", CarveConverter::toCarve("a\\\nb\n"));
    }

    /**
     * A break NESTED inside an inline run ends that run's list, not the
     * stanza, so it keeps its newline.
     *
     * The parser never builds this tree - the promotion in the block parser
     * reaches direct children only - but an imported AST can, and dropping the
     * newline there closed the emphasis with an ESCAPED delimiter.
     */
    public function testANestedTrailingBreakKeepsItsNewline(): void
    {
        $document = (new AstCodec())->decode([
            'type' => 'document',
            'srcByteLength' => 0,
            'children' => [[
                'type' => 'line_block',
                'children' => [[
                    'type' => 'paragraph',
                    'children' => [[
                        'type' => 'emphasis',
                        'children' => [
                            ['type' => 'text', 'value' => 'a'],
                            ['type' => 'hard_break'],
                        ],
                    ]],
                ]],
            ]],
        ]);

        $converter = CarveConverter::carve();

        $this->assertStringNotContainsString('a\\/', $converter->getRenderer()->render($document));
    }

    /**
     * A line block nested in a container reaches the same writer through a
     * different path, so the rule is pinned there too.
     */
    public function testTheRuleHoldsInsideAQuote(): void
    {
        $source = "> ::: |\n> a \\\n> b\n> :::\n";
        $formatted = CarveConverter::toCarve($source);

        $this->assertSame($this->convert($source), $this->convert($formatted));
        $this->assertStringContainsString("a \\", $formatted);
    }
}
