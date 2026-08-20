<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Lint;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Lint\RetiredSpellingLinter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The migration half of PART 9 §5 T10.
 *
 * T10 moved a cell's attribute block to AFTER the kind and alignment markers.
 * That reinterprets `|{#x}< content |` rather than erroring on it: the `<` used
 * to be the cell's alignment and is now content. A rewrite is the wrong
 * migration for it - it would ADD `text-align: left` and REMOVE a literal `<`
 * from a document that renders correctly today, so `toHtml(fmt(x)) ==
 * toHtml(x)` would fail on it. The migration is therefore a report, and this is
 * the report.
 */
class RetiredSpellingLintTest extends TestCase
{
    protected RetiredSpellingLinter $linter;

    protected function setUp(): void
    {
        $this->linter = new RetiredSpellingLinter();
    }

    /**
     * @return list<string>
     */
    protected function rules(string $source): array
    {
        return array_map(
            static fn ($warning): string => $warning->rule,
            $this->linter->lint($source),
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function retiredOrderProvider(): array
    {
        return [
            'left' => ["|{#x}< content |\n"],
            'right' => ["|{#x}> content |\n"],
            'center' => ["|{#x}~ content |\n"],
            'a class rather than an id' => ["|{.num}> 9 |\n"],
            'in a body row below a header' => ["|= H |\n|{.num}> 9 |\n"],
        ];
    }

    #[DataProvider('retiredOrderProvider')]
    public function testTheRetiredOrderIsReported(string $source): void
    {
        $this->assertSame(
            [RetiredSpellingLinter::RULE_TABLE_CELL_ATTRIBUTE_BEFORE_MARKER],
            $this->rules($source),
        );
    }

    /**
     * The message names BOTH spellings, because only the author knows which was
     * meant. One of them is a marker the cell no longer has; the other is the
     * literal character the cell now renders.
     */
    public function testTheMessageNamesBothSpellings(): void
    {
        $warnings = $this->linter->lint("|{#x}< content |\n");

        $this->assertCount(1, $warnings);
        // Both spellings carry the space that ENDS a marker run (PART 9 §5
        // T11): glued, the braces are content and neither reading applies.
        $this->assertStringContainsString('`<{#x} `', $warnings[0]->message);
        $this->assertStringContainsString('left-aligned', $warnings[0]->message);
        $this->assertStringContainsString('`{#x} <`', $warnings[0]->message);
    }

    /**
     * The finding points at the cell that carries it, not at the row.
     */
    public function testTheFindingIsPlacedOnItsOwnCell(): void
    {
        $warnings = $this->linter->lint("| a |{#x}> b |\n");

        $this->assertCount(1, $warnings);
        $this->assertSame(1, $warnings[0]->line);
        $this->assertSame(6, $warnings[0]->column);
        $this->assertSame('{#x}>', substr("| a |{#x}> b |\n", $warnings[0]->start, $warnings[0]->end - $warnings[0]->start));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unaffectedProvider(): array
    {
        return [
            // The T10 spelling itself. Reporting it would report the migration
            // target as the problem.
            'the block after the marker' => ["|<{#x} content |\n"],
            'the block after a kind marker' => ["|={.total} Total |\n"],
            'the block after both markers' => ["|=~{#score} Score |\n"],
            // A space in front of the sigil made it content under BOTH orders
            // (T4), so nothing was reinterpreted and there is nothing to pick.
            'a space before the sigil' => ["|{#x} < content |\n"],
            // `=` is not an alignment sigil. `|{#x}=R|` is a data cell whose
            // content starts with `=` under both orders - it is the shape that
            // made an attributed header cell unspellable, not a retired one.
            'the ambiguous header shape' => ["|{#x}=R|\n"],
            // A marker in FRONT of the block is a position the retired order
            // did not admit, so its `<` was content then and is content now.
            'a marker in front of the block' => ["|>{.x}< a |\n"],
            // A space before the brace is ordinary content, so there is no
            // attribute block to have bound in either position.
            'a spaced brace is content' => ["| {.x}< a |\n"],
            // Not an attribute block at all: the payload is invalid, so the
            // brace is literal.
            'an invalid payload' => ["|{not attrs!}< a |\n"],
            'a plain table' => ["|= H |\n| a |\n"],
            'no table at all' => ["A paragraph with {#x}< in it.\n"],
        ];
    }

    #[DataProvider('unaffectedProvider')]
    public function testNothingElseIsReported(string $source): void
    {
        $this->assertSame([], $this->rules($source));
    }

    /**
     * A markup EXAMPLE is not a document, and this pass never sees one: a
     * fenced block holds no cells. A LINE SCAN would have reported here, on
     * every page explaining the rule - including this package's own
     * `docs/lint.md`, which shows the retired spelling in a fenced example.
     */
    public function testAFencedExampleIsNotReported(): void
    {
        $this->assertSame([], $this->rules("```\n|{#x}< content |\n```\n"));
        $this->assertSame([], $this->rules("~~~\n|{#x}< content |\n~~~\n"));
        $this->assertSame([], $this->rules("```=html\n|{#x}< content |\n```\n"));
    }

    /**
     * CONTROL. A fenced block does not stop the pass at the fence: a real cell
     * after it is still reported, and at its own position.
     */
    public function testACellAfterAFencedExampleIsStillReported(): void
    {
        $source = "```\n|{#x}< fenced |\n```\n\n|{#x}> real |\n";
        $warnings = $this->linter->lint($source);

        $this->assertCount(1, $warnings);
        $this->assertSame(5, $warnings[0]->line);
        $this->assertSame('{#x}>', substr($source, $warnings[0]->start, $warnings[0]->end - $warnings[0]->start));
    }

    /**
     * A table row is a row wherever it stands. The rule reports it in a block
     * quote, in a list item, and on a continuation line at a list item's
     * content column - none of which begin with `|`, so a line scan looking for
     * a row shape would have missed every one.
     *
     * @return array<string, array{0: string}>
     */
    public static function containerProvider(): array
    {
        return [
            'in a block quote' => ["> |{#x}< a |\n"],
            'in a nested block quote' => ["> > |{#x}< a |\n"],
            'in a list item' => ["- |{#x}< a |\n"],
            'in an ordered item' => ["1. |{#x}< a |\n"],
            'at an item content column' => ["- x\n\n  |{#x}< a |\n"],
        ];
    }

    #[DataProvider('containerProvider')]
    public function testAContainerDoesNotHideIt(string $source): void
    {
        $this->assertSame(
            [RetiredSpellingLinter::RULE_TABLE_CELL_ATTRIBUTE_BEFORE_MARKER],
            $this->rules($source),
        );
    }

    /**
     * CONTROL for the pair above. These look like rows in a container and are
     * not tables at all - a block quote marker takes a space, and so does a
     * bullet - so nothing is reported and no marker-stripping heuristic gets to
     * decide otherwise.
     *
     * @return array<string, array{0: string}>
     */
    public static function containerShapedNonTableProvider(): array
    {
        return [
            'a quote marker with no space' => [">|{#x}< a |\n"],
            'a bullet with no space' => ["-|{#x}< a |\n"],
            'a doubled quote marker' => [">> |{#x}< a |\n"],
            'an indented line at top level' => ["  |{#x}< a |\n"],
        ];
    }

    #[DataProvider('containerShapedNonTableProvider')]
    public function testAContainerShapeThatIsNotATableIsNotReported(string $source): void
    {
        $this->assertStringNotContainsString('<table>', (new CarveConverter())->convert($source));
        $this->assertSame([], $this->rules($source));
    }

    /**
     * Offsets are BYTES, as the other passes emit them, while a source span
     * counts codepoints. A multibyte cell ahead of the finding is where the two
     * diverge.
     */
    public function testOffsetsAreBytes(): void
    {
        $source = "| ünïcøde |{#x}> b |\n";
        $warnings = $this->linter->lint($source);

        $this->assertCount(1, $warnings);
        $this->assertSame('{#x}>', substr($source, $warnings[0]->start, $warnings[0]->end - $warnings[0]->start));
    }

    /**
     * THE REASON THIS IS A REPORT AND NOT A REWRITE. The two spellings render
     * differently, so applying the edit in the default `fmt` path would change
     * the output of a document that is already correct.
     */
    public function testTheTwoSpellingsRenderDifferently(): void
    {
        $converter = new CarveConverter();

        $retired = $converter->convert("|{#x}< content |\n");
        $kept = $converter->convert("|{#x} < content |\n");
        $rewritten = $converter->convert("|<{#x} content |\n");

        // The retired spelling is now a third reading of its own: with no space
        // to end the marker run there is no block either, so the braces render
        // (PART 9 §5 T11). That is why the message names two SPACED spellings.
        $this->assertStringNotContainsString('id="x"', $retired);
        $this->assertStringNotContainsString('text-align', $retired);
        $this->assertStringContainsString('<td id="x">&lt; content</td>', $kept);
        $this->assertStringContainsString('<td id="x" style="text-align: left;">content</td>', $rewritten);
        $this->assertNotSame($retired, $rewritten);
        $this->assertNotSame($retired, $kept);
    }

    /**
     * And the default `fmt` path leaves it alone, which is the property the
     * report exists to protect.
     */
    public function testTheFormatterDoesNotRewriteIt(): void
    {
        $source = "|{#x}< content |\n";
        $converter = new CarveConverter();

        $this->assertSame(
            $converter->convert($source),
            $converter->convert(CarveConverter::toCarve($source)),
        );
    }
}
