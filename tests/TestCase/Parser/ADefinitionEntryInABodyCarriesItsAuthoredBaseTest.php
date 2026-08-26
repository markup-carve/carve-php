<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * THE BASE BELONGS TO THE INNERMOST OPEN CONTAINER (PART 9 §24 C3).
 *
 * At or above a container's minimum content column, a recognized block opener
 * establishes a local block base - and that question is asked of the INNERMOST
 * container open where the line is written, never of an outer one. A line at a
 * nested body's own content column is that body's CONTENT, not a new base for
 * the outer body.
 *
 * ONE RULE, ONE STATEMENT, AND THE SAME ONE FOR EVERY CONTAINER. It replaced
 * three per-container spellings that disagreed (markup-carve/carve#1781), and
 * then had to be extended once more because the unification left a container
 * kind out: a list item is one too, and a quote at a nested item's content
 * column was being lifted out of the item it was written into
 * (markup-carve/carve#1791).
 *
 * THIS FILE USED TO PIN THE OPPOSITE for a list item, on the earlier reading
 * that carve#1752 made both spellings say the same thing there. That reading is
 * superseded: corpus
 * `423-one-authored-base-rule-reaches-a-definition-nested-in-a-list-item` pins
 * the item answering like the two bodies. What is pinned now is the agreement
 * itself - every host, every band, the same answer - because the defect this
 * rule replaced was three containers answering one question three ways
 * (markup-carve/carve-php#1783).
 *
 * The expectations are the corpus goldens of categories 419, 422 and 423, which
 * this repo's spec submodule predates.
 */
final class ADefinitionEntryInABodyCarriesItsAuthoredBaseTest extends TestCase
{
    /**
     * The three bands, in each of the three hosts that open a container.
     *
     * A host is named by the container the entry sits in; the source puts the
     * entry at that host's minimum content column, one column above it, or with
     * its payload written below the column the description's own marker hands
     * out.
     *
     * @return array<string, array{string, bool}>
     */
    public static function everyHostProvider(): array
    {
        return [
            // AT THE DESCRIPTION'S CONTENT COLUMN the payload is the
            // description's content, whether or not the entry itself is raised.
            'footnote body, entry raised, payload at its column' => [
                "[^n]: intro\n\n   :: term\n   :  definition\n\n      > quote\n\nsee[^n]\n",
                true,
            ],
            'definition body, entry raised, payload at its column' => [
                ":: outer\n:  intro\n\n    :: term\n    :  definition\n\n       > quote\n",
                true,
            ],
            'list item, entry raised, payload at its column' => [
                "- intro\n\n   :: term\n   :  definition\n\n      > quote\n",
                true,
            ],
            'footnote body, entry at the minimum' => [
                "[^n]: intro\n\n  :: term\n  :  definition\n\n     > quote\n\nsee[^n]\n",
                true,
            ],
            'definition body, entry at the minimum' => [
                ":: outer\n:  intro\n\n   :: term\n   :  definition\n\n      > quote\n",
                true,
            ],
            'list item, entry at the minimum' => [
                "- intro\n\n  :: term\n  :  definition\n\n     > quote\n",
                true,
            ],
            // BELOW THE DESCRIPTION'S CONTENT COLUMN the description ENDS, and
            // the surviving context is the host - where the line is still above
            // the minimum, so it takes a base of its own and stays STRUCTURAL.
            // It used to come back as escaped prose in all three hosts.
            'footnote body, payload below the description column' => [
                "[^n]: intro\n\n   :: term\n   :  definition\n\n     > quote\n\nsee[^n]\n",
                false,
            ],
            'definition body, payload below the description column' => [
                ":: outer\n:  intro\n\n    :: term\n    :  definition\n\n      > quote\n",
                false,
            ],
            'list item, payload below the description column' => [
                "- intro\n\n   :: term\n   :  definition\n\n     > quote\n",
                false,
            ],
        ];
    }

    /**
     * A NESTED LIST ITEM IS A CONTAINER TOO (carve#1791).
     *
     * @return array<string, array{string, bool}>
     */
    public static function nestedItemProvider(): array
    {
        return [
            'footnote body, quote at the nested item column' => [
                "[^n]: intro\n\n  - item\n\n    > quote\n\nsee[^n]\n",
                true,
            ],
            'definition body, quote at the nested item column' => [
                ":: outer\n:  intro\n\n   - item\n\n     > quote\n",
                true,
            ],
            'list item, quote at the nested item column' => [
                "- intro\n\n  - item\n\n    > quote\n",
                true,
            ],
            // The control: one column BELOW the nested item's content column the
            // item ends and the quote is the host's, still a quote.
            'footnote body, quote below the nested item column' => [
                "[^n]: intro\n\n  - item\n\n   > quote\n\nsee[^n]\n",
                false,
            ],
            'definition body, quote below the nested item column' => [
                ":: outer\n:  intro\n\n   - item\n\n    > quote\n",
                false,
            ],
            'list item, quote below the nested item column' => [
                "- intro\n\n  - item\n\n   > quote\n",
                false,
            ],
        ];
    }

    /**
     * A payload at the description's column is the description's; below it, it
     * is the host's - and it is a QUOTE either way, never escaped prose.
     */
    #[DataProvider('everyHostProvider')]
    public function testTheInnermostContainerOwnsThePayload(string $source, bool $inTheDescription): void
    {
        $html = (new CarveConverter())->convert($source);

        self::assertStringContainsString('<blockquote><p>quote</p></blockquote>', $html, $html);
        self::assertStringNotContainsString('&gt; quote', $html, $html);
        self::assertSame($inTheDescription, self::quoteIsInsideTheDescription($html), $html);
    }

    /**
     * The same question with a nested list item as the innermost container.
     */
    #[DataProvider('nestedItemProvider')]
    public function testANestedItemOwnsThePayloadAtItsColumn(string $source, bool $inTheItem): void
    {
        $html = (new CarveConverter())->convert($source);

        self::assertStringContainsString('<blockquote><p>quote</p></blockquote>', $html, $html);
        self::assertSame($inTheItem, self::quoteIsInsideTheNestedItem($html), $html);
    }

    /**
     * THE THREE HOSTS AGREE, which is the property the one rule exists to give.
     *
     * Asserted as its own statement rather than left implicit in the rows above:
     * a later change that repairs one host and drifts another passes every row
     * it did not touch, and this is what sees it.
     */
    public function testEveryHostAnswersTheSameWay(): void
    {
        $converter = new CarveConverter();
        $bands = [
            'at the column' => [
                "[^n]: intro\n\n  :: term\n  :  definition\n\n     > quote\n\nsee[^n]\n",
                ":: outer\n:  intro\n\n   :: term\n   :  definition\n\n      > quote\n",
                "- intro\n\n  :: term\n  :  definition\n\n     > quote\n",
            ],
            'below the column' => [
                "[^n]: intro\n\n   :: term\n   :  definition\n\n     > quote\n\nsee[^n]\n",
                ":: outer\n:  intro\n\n    :: term\n    :  definition\n\n      > quote\n",
                "- intro\n\n   :: term\n   :  definition\n\n     > quote\n",
            ],
        ];

        foreach ($bands as $band => $sources) {
            $answers = [];
            foreach ($sources as $source) {
                $answers[] = self::quoteIsInsideTheDescription($converter->convert($source));
            }
            self::assertCount(1, array_unique($answers), 'the hosts disagree ' . $band);
        }
    }

    /**
     * A raised entry with NO payload after its blank is the same document at
     * either column, in every host. The base only becomes observable once
     * something follows the blank.
     */
    public function testAnEntryWithNoPayloadIsTheSameDocumentAtEitherColumn(): void
    {
        $converter = new CarveConverter();
        $hosts = [
            'footnote body' => ["[^n]: intro\n\n%s:: term\n%s:  definition\n\nsee[^n]\n", 2],
            'definition body' => [":: outer\n:  intro\n\n%s:: term\n%s:  definition\n", 3],
            'list item' => ["- intro\n\n%s:: term\n%s:  definition\n", 2],
        ];

        foreach ($hosts as $name => [$template, $minimum]) {
            $pad = str_repeat(' ', $minimum);
            $exact = sprintf($template, $pad, $pad);
            $over = sprintf($template, $pad . ' ', $pad . ' ');
            self::assertSame($converter->convert($exact), $converter->convert($over), $name);
        }
    }

    /**
     * Is the `> quote` payload inside the INNER description, or a sibling of
     * the list that holds it?
     */
    private static function quoteIsInsideTheDescription(string $html): bool
    {
        $quote = strpos($html, '<blockquote>');
        if ($quote === false) {
            return false;
        }
        $close = strpos($html, '</dl>');

        return $close !== false && $quote < $close;
    }

    /**
     * Is the `> quote` payload inside the NESTED item, or a sibling of the list
     * that holds it?
     */
    private static function quoteIsInsideTheNestedItem(string $html): bool
    {
        $quote = strpos($html, '<blockquote>');
        if ($quote === false) {
            return false;
        }
        $close = strpos($html, '</ul>');

        return $close !== false && $quote < $close;
    }
}
