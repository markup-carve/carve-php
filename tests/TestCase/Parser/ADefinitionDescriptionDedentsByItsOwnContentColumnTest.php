<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A `dd` is a container with a content column, and its body is dedented by
 * THAT COLUMN and by nothing more.
 *
 * The collector `ltrim`ed every body line instead, so the description was the
 * one container in this parser whose body could not say how far past its column
 * a line sat. Two rules are measured against exactly that number and neither
 * could be reached:
 *
 *   - PART 9 §16 states a footnote body's column RELATIVE to the definition,
 *     and PART 9 §10 I5 binds a definition to its container's content column.
 *     Ruled for the `dd` on markup-carve/carve-php#1650: the body's column is
 *     the container's content column PLUS TWO, wherever the container has one.
 *     All three engines already did this inside a LIST ITEM, and this engine's
 *     own writer encodes `$reachedCol + 2` - it simply never reached the `dd`.
 *   - a nested list's own column, so `- x` / ` - y` written in a `dd` came
 *     back as two SIBLINGS.
 *
 * A SECOND DEFECT SHARES THE SITE. A line one column past the content column
 * with no blank above it was appended to the previous body entry as lazy
 * paragraph text. Onto a DEFINITION that destroyed it: `[^1]: a\nb` matches no
 * definition pattern, so the note was registered by nobody while the `dd`
 * rendered the author's own source text and `[^1]` stayed literal - the
 * define-nothing family markup-carve/carve#624 forbids. The link-reference
 * spelling was worse, registering AND leaking, so the definition line reached
 * the reader as prose beside a working link.
 *
 * markup-carve/carve#918 is not touched. "Past the column is lazy text" governs
 * a line CONTINUING AN OPEN PARAGRAPH; a definition line leaves no paragraph
 * open, and a line after a blank is not continuing anything. Both readings are
 * pinned below as controls.
 *
 * EVERY EXPECTATION HERE WAS COMPARED AGAINST carve-js `ba42673`, built from
 * source, and is byte-identical to it.
 */
class ADefinitionDescriptionDedentsByItsOwnContentColumnTest extends TestCase
{
    private CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = CarveConverter::create();
    }

    private function html(string $source): string
    {
        return $this->converter->convert($source);
    }

    /**
     * The note's own text runs, read from the published tree rather than from
     * the HTML.
     *
     * THE METRIC MATTERS: `grep '<p>b'` misses a lazy join, and `grep 'b'`
     * matches `aria-label="Back to reference"`. Both were tried while this was
     * being measured and both gave a wrong cross-engine matrix.
     *
     * @return array<string>
     */
    private function noteTextRuns(string $source): array
    {
        $runs = [];
        $text = function (array $node) use (&$text, &$runs): void {
            if (($node['type'] ?? '') === 'text') {
                $runs[] = $node['value'];

                return;
            }
            foreach ($node['children'] ?? [] as $child) {
                $text($child);
            }
        };
        $find = function (array $node) use (&$find, $text): void {
            if (($node['type'] ?? '') === 'footnote') {
                $text($node);

                return;
            }
            foreach ($node['children'] ?? [] as $child) {
                $find($child);
            }
            foreach ($node['items'] ?? [] as $child) {
                $find($child);
            }
        };
        $find((new AstCodec())->encode($this->converter->parse($source)));

        return $runs;
    }

    /**
     * THE TICKET'S OWN MATRIX, every cell measured against carve-js.
     *
     * The `dd`'s content column is 3, so the note body's column is 5. Below it
     * the line belongs to the description; at or past it, to the note. Nine of
     * these twelve cells fail without this change - the three at indent 3 are
     * the controls that already agreed.
     *
     * @return array<string, array{int, int, array<string>}>
     */
    public static function matrix(): array
    {
        $cases = [];
        foreach ([0, 1, 2] as $blanks) {
            foreach ([3, 4, 5, 6] as $indent) {
                $cases["$blanks blank line(s), continuation at column $indent"] = [
                    $blanks,
                    $indent,
                    $indent >= 5 ? ['a', 'b'] : ['a'],
                ];
            }
        }

        return $cases;
    }

    /**
     * @param int $blanks
     * @param int $indent
     * @param array<string> $expected
     */
    #[DataProvider('matrix')]
    public function testTheNoteBodyReachesTheContainerContentColumnPlusTwo(
        int $blanks,
        int $indent,
        array $expected,
    ): void {
        $source = ":: t\n:  [^1]: a\n"
            . str_repeat("\n", $blanks)
            . str_repeat(' ', $indent) . "b\n\nsee[^1]\n";

        $this->assertSame($expected, $this->noteTextRuns($source), 'the note body');
        $this->assertStringContainsString(
            'href="#fn1"',
            $this->html($source),
            'the reference did not resolve, so the definition registered with nobody',
        );
    }

    /**
     * CLASS 1, and the worse half: at zero blank lines the definition line was
     * joined to the line below it, so nothing registered and the author's own
     * source reached the reader.
     */
    public function testAFootnoteDefinitionIsNotJoinedToTheLineBelowIt(): void
    {
        $this->assertSame(
            "<dl>\n  <dt>t</dt>\n  <dd>b</dd>\n</dl>\n"
                . "<p>see<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n"
                . "<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n"
                . "      <p>a<a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">\u{21a9}</a></p>\n"
                . "    </li>\n  </ol>\n</section>\n",
            $this->html(":: t\n:  [^1]: a\n    b\n\nsee[^1]\n"),
        );
    }

    /**
     * THE SAME SITE, THE OTHER DEFINITION KIND. This one registered AND leaked:
     * the link resolved while `[r]: /u` rendered as prose beside it, which is
     * the shape `tryParseReferenceDefinition()`'s own docblock warns about.
     */
    public function testALinkReferenceDefinitionIsNotJoinedToTheLineBelowItEither(): void
    {
        $this->assertSame(
            "<dl>\n  <dt>t</dt>\n  <dd>b</dd>\n</dl>\n<p><a href=\"/u\">see</a></p>\n",
            $this->html(":: t\n:  [r]: /u\n    b\n\n[see][r]\n"),
        );
    }

    /**
     * THE BOUND ON THAT GUARD, and the reason it asks with
     * `abbreviationCounts: false`. PART 12 §7 makes an abbreviation definition
     * one only as a direct child of the document, so inside a `dd` the same
     * shape is ordinary paragraph text that RENDERS - it does leave a paragraph
     * open, and the line below folds into it. Both engines have always agreed
     * here, and a guard that excluded every definition-shaped line would move
     * it.
     */
    public function testAnAbbreviationShapeInsideADescriptionStillFolds(): void
    {
        $this->assertSame(
            "<dl>\n  <dt>t</dt>\n  <dd>*[H]: Hyper\nb</dd>\n</dl>\n<p>H</p>\n",
            $this->html(":: t\n:  *[H]: Hyper\n    b\n\nH\n"),
        );
    }

    /**
     * The second rule the `ltrim` made unreachable: a nested list in a `dd`
     * came back as two siblings, because both markers arrived at column 0.
     */
    public function testANestedListInADescriptionKeepsItsNesting(): void
    {
        $this->assertSame(
            "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>a</p>\n    <ul>\n      <li>x\n"
                . "        <ul>\n          <li>y</li>\n        </ul>\n      </li>\n    </ul>\n  </dd>\n</dl>\n",
            $this->html(":: t\n:  a\n\n   - x\n     - y\n"),
        );
    }

    /**
     * carve#918's own shapes, UNMOVED. The first is the lazy continuation the
     * clause is about; the rest are the column bands it names. A fix that
     * dedented indiscriminately would turn the first into a nested quote, which
     * is exactly what the clause forbids.
     *
     * @return array<string, array{string, string}>
     */
    public static function unmovedShapes(): array
    {
        return [
            'past the column with an open paragraph: lazy text' => [
                ":: t\n:  body\n    > q\n",
                "<dl>\n  <dt>t</dt>\n  <dd>body\n&gt; q</dd>\n</dl>\n",
            ],
            'at the column after a blank: a real quote' => [
                ":: t\n:  body\n\n   > q\n",
                "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>body</p>\n    <blockquote><p>q</p></blockquote>\n  </dd>\n</dl>\n",
            ],
            'below the column: the body ends' => [
                ":: t\n:  body\n > q\n",
                "<dl>\n  <dt>t</dt>\n  <dd>body</dd>\n</dl>\n<p>&gt; q</p>\n",
            ],
            'flush left: a sibling quote' => [
                ":: t\n:  body\n> q\n",
                "<dl>\n  <dt>t</dt>\n  <dd>body</dd>\n</dl>\n<blockquote><p>q</p></blockquote>\n",
            ],
        ];
    }

    #[DataProvider('unmovedShapes')]
    public function testTheLazyContinuationClauseIsUnmoved(string $source, string $expected): void
    {
        $this->assertSame($expected, $this->html($source));
    }
}
