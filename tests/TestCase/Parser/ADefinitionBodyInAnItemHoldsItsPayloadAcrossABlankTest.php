<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A definition body inside a list item keeps its payload across the blank line
 * that separates the body's own blocks (markup-carve/carve-php#1787).
 *
 * A DEFINITION BODY IS A BLOCK WITH A BODY, so its extent is the whole body,
 * BLANK LINES AND ALL (PART 1 S4) - the reading markup-carve/carve#1363 already
 * gave a footnote definition. The item collector ended the item's run at that
 * blank, so the payload below it reached the parser as a SECOND stream and the
 * definition list, closed by the stream boundary, could not claim it. The
 * payload came out beside the `<dl>` instead of inside the `<dd>`.
 *
 * The tell was that the SAME definition list answered differently by a line
 * that is not part of it: written with a blank above the `::` the payload
 * landed in the `<dd>`, and without one it did not. The spec oracle, carve-js
 * and carve-rs all put it in for both spellings; this engine was the only
 * outlier, and only on the no-blank half. All three writers narrow the base and
 * DROP that blank when they format, so every one of them produced a document
 * this engine then read differently from the one it was written from.
 *
 * THE TWO BANDS ARE ONE COLUMN APART AND ARE PINNED AGAINST EACH OTHER. A
 * payload AT the body's content column belongs to the body; one column BELOW it
 * does not and stays the ITEM's own block, which is where the nearest corpus
 * case (`422-...-8`) sits and where this engine was already right. Fixing one
 * band while the other drifts is the failure this pair exists to catch, and the
 * separator width moves both bands together (carve#1757), so each separator is
 * asserted with its own column rather than a constant.
 *
 * No corpus document pins the AT band for this host, which is why three engines
 * could disagree on it silently.
 */
class ADefinitionBodyInAnItemHoldsItsPayloadAcrossABlankTest extends TestCase
{
    protected function html(string $source): string
    {
        return (new CarveConverter())->convert($source);
    }

    /**
     * A payload AT the body's content column is inside the `<dd>`.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function insideTheDescription(): iterable
    {
        yield 'one-space separator, quote at the body column' => [
            "- intro\n  :: term\n  : definition\n\n    > quote\n",
            "<ul>\n  <li>intro\n    <dl>\n      <dt>term</dt>\n      <dd>\n        <p>definition</p>\n"
                . "        <blockquote><p>quote</p></blockquote>\n      </dd>\n    </dl>\n  </li>\n</ul>\n",
        ];

        yield 'two-space separator, quote one column deeper with it' => [
            "- intro\n  :: term\n  :  definition\n\n     > quote\n",
            "<ul>\n  <li>intro\n    <dl>\n      <dt>term</dt>\n      <dd>\n        <p>definition</p>\n"
                . "        <blockquote><p>quote</p></blockquote>\n      </dd>\n    </dl>\n  </li>\n</ul>\n",
        ];

        yield 'a fenced block at the body column' => [
            "- intro\n  :: term\n  : definition\n\n    ```\n    code\n    ```\n",
            "<ul>\n  <li>intro\n    <dl>\n      <dt>term</dt>\n      <dd>\n        <p>definition</p>\n"
                . "        <pre><code>code\n</code></pre>\n      </dd>\n    </dl>\n  </li>\n</ul>\n",
        ];

        yield 'the item leads with the term, so the body is on the marker line run' => [
            "- :: term\n  : definition\n\n    > quote\n",
            "<ul>\n  <li>\n    <dl>\n      <dt>term</dt>\n      <dd>\n        <p>definition</p>\n"
                . "        <blockquote><p>quote</p></blockquote>\n      </dd>\n    </dl>\n  </li>\n</ul>\n",
        ];

        // TWO blanks, so the lookahead walks past one to reach the payload. A
        // run of blanks is still one separator between the body's blocks.
        yield 'two blank lines above a payload at the body column' => [
            "- intro\n  :: term\n  : definition\n\n\n    > quote\n",
            "<ul>\n  <li>intro\n    <dl>\n      <dt>term</dt>\n      <dd>\n        <p>definition</p>\n"
                . "        <blockquote><p>quote</p></blockquote>\n      </dd>\n    </dl>\n  </li>\n</ul>\n",
        ];

        yield 'a second entry follows the payload in ONE list' => [
            "- intro\n  :: t1\n  : d1\n\n    > q\n  :: t2\n  : d2\n",
            "<ul>\n  <li>intro\n    <dl>\n      <dt>t1</dt>\n      <dd>\n        <p>d1</p>\n"
                . "        <blockquote><p>q</p></blockquote>\n      </dd>\n      <dt>t2</dt>\n      <dd>d2</dd>\n"
                . "    </dl>\n  </li>\n</ul>\n",
        ];
    }

    /**
     * ONE COLUMN BELOW the body's content column the payload is the item's own
     * block, the blank line separates two of the ITEM's blocks, and the list is
     * loose because of it. This band must not move.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function outsideTheDescription(): iterable
    {
        yield 'one-space separator, quote one column short' => [
            "- intro\n  :: term\n  : definition\n\n   > quote\n",
            "<ul>\n  <li>intro\n    <dl>\n      <dt>term</dt>\n      <dd>definition</dd>\n    </dl>\n"
                . "    <blockquote><p>quote</p></blockquote>\n  </li>\n</ul>\n",
        ];

        yield 'two-space separator, quote one column short of ITS column' => [
            "- intro\n  :: term\n  :  definition\n\n    > quote\n",
            "<ul>\n  <li>intro\n    <dl>\n      <dt>term</dt>\n      <dd>definition</dd>\n    </dl>\n"
                . "    <blockquote><p>quote</p></blockquote>\n  </li>\n</ul>\n",
        ];

        // The band split survives a RUN of blanks, so the lookahead's skip does
        // not quietly widen what the body claims.
        yield 'two blank lines above a payload one column short' => [
            "- intro\n  :: term\n  : definition\n\n\n   > quote\n",
            "<ul>\n  <li>intro\n    <dl>\n      <dt>term</dt>\n      <dd>definition</dd>\n    </dl>\n"
                . "    <blockquote><p>quote</p></blockquote>\n  </li>\n</ul>\n",
        ];

        yield 'a paragraph one column short is the item second block, and loosens it' => [
            "- intro\n  :: term\n  : definition\n\n   tail\n",
            "<ul>\n  <li><p>intro</p>\n    <dl>\n      <dt>term</dt>\n      <dd>definition</dd>\n    </dl>\n"
                . "    <p>tail</p>\n  </li>\n</ul>\n",
        ];

        yield 'a paragraph at the ITEM content column is the item second block' => [
            "- intro\n  :: term\n  : definition\n\n  tail\n",
            "<ul>\n  <li><p>intro</p>\n    <dl>\n      <dt>term</dt>\n      <dd>definition</dd>\n    </dl>\n"
                . "    <p>tail</p>\n  </li>\n</ul>\n",
        ];
    }

    #[DataProvider('insideTheDescription')]
    public function testPayloadAtTheBodyColumnIsInsideTheDescription(string $source, string $expected): void
    {
        $this->assertSame($expected, $this->html($source));
    }

    #[DataProvider('outsideTheDescription')]
    public function testPayloadBelowTheBodyColumnStaysOutsideTheDescription(string $source, string $expected): void
    {
        $this->assertSame($expected, $this->html($source));
    }

    /**
     * The blank-above spelling was already right in all three engines and is
     * the reading the no-blank one now shares. Asserted so a fix that moved
     * BOTH halves to a new answer cannot pass this file.
     */
    public function testTheBlankAboveTheTermSpellingDoesNotMove(): void
    {
        // TIGHT, blank and all: SS17 L1 loosens an item over a blank-separated
        // second PARAGRAPH, and `:: term` opens a block. The `tail` cases above
        // are the other half of that predicate and do loosen.
        $this->assertSame(
            "<ul>\n  <li>intro\n    <dl>\n      <dt>term</dt>\n      <dd>\n        <p>definition</p>\n"
                . "        <blockquote><p>quote</p></blockquote>\n      </dd>\n    </dl>\n  </li>\n</ul>\n",
            $this->html("- intro\n\n  :: term\n  : definition\n\n    > quote\n"),
        );
    }

    /**
     * The item ENDS at the blank, so nothing follows for the body to hold. The
     * lookahead answers no, the run ends there as it always did, and the
     * trailing blank leaves no empty block behind.
     */
    public function testAnItemEndingAtTheBlankHoldsNothingMore(): void
    {
        $this->assertSame(
            "<ul>\n  <li>intro\n    <dl>\n      <dt>term</dt>\n      <dd>definition</dd>\n"
                . "    </dl>\n  </li>\n</ul>\n",
            $this->html("- intro\n  :: term\n  : definition\n\n"),
        );
    }

    /**
     * THE WHOLE POINT, stated as one equivalence rather than case by case: the
     * blank above the term is the item's own business and changes nothing about
     * where the body's payload lands. Swept over three item bases, both
     * separator widths and four payload columns spanning both bands, so a fix
     * that only satisfies the headline geometry above does not pass.
     *
     * The lead's own paragraph wrapper is excluded deliberately - the blank
     * above the term DOES loosen the item that holds it, and that difference is
     * pinned by the cases above rather than swept away here.
     */
    public function testTheBlankAboveTheTermDoesNotDecideWhereThePayloadLands(): void
    {
        $bases = ['- ' => 2, '1. ' => 3, '10. ' => 4];
        $compared = 0;
        foreach ($bases as $marker => $base) {
            $indent = str_repeat(' ', $base);
            foreach ([1, 2] as $separator) {
                $bodyColumn = $base + 1 + $separator;
                foreach ([$bodyColumn + 1, $bodyColumn, $bodyColumn - 1, $base] as $payloadColumn) {
                    $payload = str_repeat(' ', $payloadColumn) . '> quote';
                    $tail = "{$indent}:: term\n{$indent}:" . str_repeat(' ', $separator)
                        . "definition\n\n{$payload}\n";
                    $withoutBlank = $this->html("{$marker}intro\n" . $tail);
                    $withBlank = $this->html("{$marker}intro\n\n" . $tail);
                    $this->assertSame(
                        str_replace('<p>intro</p>', 'intro', $withBlank),
                        str_replace('<p>intro</p>', 'intro', $withoutBlank),
                        "base {$base}, separator {$separator}, payload column {$payloadColumn}",
                    );
                    $compared++;
                }
            }
        }

        $this->assertSame(24, $compared);
    }
}
