<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * THE AST EXIT PUBLISHES THE DOCUMENT, NOT THE SOURCE WRITER
 * (`markup-carve/carve-php#1716`).
 *
 * This engine reads its tree back from its own written source, which is what
 * makes the two exits one invariant rather than two goldens nobody compares.
 * The cost is that everything the WRITER does on the way through was reaching
 * the published tree: its escapes, and its ceiling.
 *
 * `docs/html-import.md` draws the line the other way. For a structure Carve
 * SOURCE cannot spell, "the AST-returning entry point loses nothing and reports
 * nothing; the one that writes source reports this" - an AST exit has nothing to
 * spell, so a shape the text cannot express is exactly the shape its tree still
 * can.
 *
 * TWO CAUSES, and this file pins both plus the two things that must NOT move:
 * the source exit's own bytes, and the report the two exits share.
 */
class TheAstExitPublishesTheDocumentNotTheWriterTest extends TestCase
{
    /**
     * CAUSE ONE: the writer's escapes.
     *
     * PART 12 section 1a makes `escaped_text` a node of its own that never
     * merges with `text`, "because an escape is authored form" - and on this
     * exit no escape is authored. HTML has no Carve escapes, so every backslash
     * in the source this importer just wrote was put there by the writer to
     * keep a character from meaning what it means in Carve. Reading them back
     * as nodes published the writer's bookkeeping.
     *
     * @return array<string, array{string, array<int, array<string, mixed>>}>
     */
    public static function escapeProvider(): array
    {
        return [
            'a symbol sigil and a tag sigil' => [
                '<p>a :rocket: b and a #t tag</p>',
                [['type' => 'text', 'value' => 'a :rocket: b and a #t tag']],
            ],
            'a caption caret the writer had to escape' => [
                '<p>^ c</p>',
                [['type' => 'text', 'value' => '^ c']],
            ],
            'a run whose escape sits at the very start' => [
                '<p>^1 and more</p>',
                [['type' => 'text', 'value' => '^1 and more']],
            ],
            'two escapes in one run coalesce with everything between them' => [
                '<p>:a: and #b and :c:</p>',
                [['type' => 'text', 'value' => ':a: and #b and :c:']],
            ],
            // A BACKSLASH THE AUTHOR WROTE is content, and the writer doubles it
            // to keep it. Folding is right for that too: what the document says
            // is one backslash, whatever the source had to spell.
            'a literal backslash the writer had to double' => [
                '<p>a \\ b</p>',
                [['type' => 'text', 'value' => 'a \\ b']],
            ],
        ];
    }

    /**
     * @param string $html
     * @param array<int, array<string, mixed>> $expected
     */
    #[DataProvider('escapeProvider')]
    public function testTheWritersEscapesDoNotReachThePublishedTree(string $html, array $expected): void
    {
        $ast = (new HtmlToCarve())->convertToAst($html);
        $this->assertSame($expected, $ast['children'][0]['children']);
    }

    /**
     * The fold reaches every container, not just a paragraph's children.
     *
     * The recursion is over LISTS rather than over a roster of container keys,
     * so a table cell and a span are reached by the same three lines that reach
     * a paragraph. Pinned because a roster is what would rot.
     */
    public function testTheFoldReachesACellAndASpan(): void
    {
        $cell = (new HtmlToCarve())->convertToAst(
            '<table><tr><td>a</td></tr><tr><td>^</td></tr></table>',
        );
        $this->assertSame(
            [['type' => 'text', 'value' => '^']],
            $cell['children'][0]['rows'][1]['cells'][0]['children'],
        );

        $span = (new HtmlToCarve())->convertToAst('<p><abbr title="y">^1</abbr></p>');
        $this->assertSame(
            [['type' => 'text', 'value' => '^1']],
            $span['children'][0]['children'][0]['children'],
        );
    }

    /**
     * CAUSE TWO: a structure Carve source cannot spell.
     *
     * An empty `<dd>` has no spelling - six were probed on
     * `markup-carve/carve#1608` and every one leaks a colon, folds into the
     * term, or renders a non-breaking space - so the source exit drops it and
     * declares the drop. The tree has no such problem, and this exit is the one
     * that is not allowed to lose it.
     */
    public function testAnEmptyDescriptionSurvivesOnTheAstExit(): void
    {
        $ast = (new HtmlToCarve())->convertToAst('<dl><dt>term</dt><dd></dd></dl>');

        $this->assertSame(
            [
                ['type' => 'definition_term', 'children' => [['type' => 'text', 'value' => 'term']]],
                ['type' => 'definition_description', 'children' => []],
            ],
            $ast['children'][0]['items'],
        );
    }

    /**
     * The same shape in the middle, where the source exit additionally has to
     * BREAK the list: consecutive `::` lines share the description written
     * below them, so dropping an entry would hand the term above it the next
     * entry's description. The tree keeps the description, so there is nothing
     * to break around and the list stays one list.
     */
    public function testAnEmptyMiddleDescriptionKeepsTheListWhole(): void
    {
        $ast = (new HtmlToCarve())->convertToAst('<dl><dt>t1</dt><dd></dd><dt>t2</dt><dd>d2</dd></dl>');

        $this->assertCount(1, $ast['children']);
        $this->assertSame('definition_list', $ast['children'][0]['type']);
        $this->assertSame(
            ['definition_term', 'definition_description', 'definition_term', 'definition_description'],
            array_column($ast['children'][0]['items'], 'type'),
        );
        $this->assertSame([], $ast['children'][0]['items'][1]['children']);
    }

    /**
     * THE STAND-IN NEVER REACHES A CALLER.
     *
     * The empty description is carried across as a sentinel the parser can
     * build a node from, and the node is emptied again before the tree is
     * returned. A sentinel that leaked would be far worse than the divergence
     * it closes, so it is asserted absent rather than assumed so.
     */
    public function testTheStandInLeaksNowhere(): void
    {
        $importer = new HtmlToCarve();
        $html = '<dl><dt>t1</dt><dd></dd><dt>t2</dt><dd>d2</dd></dl>';

        $this->assertStringNotContainsString("\x01", json_encode($importer->convertToAst($html), JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString("\x01", $importer->convert($html));
    }

    /**
     * THE SOURCE EXIT DOES NOT MOVE. Its bytes are asserted against
     * `expected.crv` with no carve-out and were already correct, so the AST
     * exit's needs may not reach them - and the converter is reusable, so a
     * flag left standing by the AST exit would write a sentinel into the next
     * caller's source.
     */
    public function testTheSourceExitIsUnchangedByTheAstExit(): void
    {
        $importer = new HtmlToCarve();
        $html = '<dl><dt>t1</dt><dd></dd><dt>t2</dt><dd>d2</dd></dl>';

        $before = $importer->convert($html);
        $importer->convertToAst($html);
        $after = $importer->convert($html);

        $this->assertSame(":: t1\n: {empty}\n:: t2\n: d2\n", $before);
        $this->assertSame($before, $after);
    }

    /**
     * THE TWO EXITS AGREE ON DIAGNOSTICS even where their values differ. The
     * marks the writer records are set whichever exit is running, so the row
     * that declares the drop reads the same from both - which is what the
     * shared fixtures assert one line below the tree comparison.
     */
    public function testTheTwoExitsReportTheSame(): void
    {
        foreach (
            [
                '<dl><dt>term</dt><dd></dd></dl>',
                '<dl><dt>t1</dt><dd></dd><dt>t2</dt><dd>d2</dd></dl>',
                '<p>a :rocket: b</p>',
            ] as $html
        ) {
            $importer = new HtmlToCarve();
            $this->assertSame(
                $importer->convertWithReport($html)->report(),
                $importer->convertToAstWithReport($html)->report(),
                $html,
            );
        }
    }
}
