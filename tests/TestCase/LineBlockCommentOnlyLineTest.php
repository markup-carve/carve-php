<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\Comment;
use MarkupCarve\Carve\Node\Node;
use MarkupCarve\Carve\Parser\BlockParser;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A comment-only body line in a line block leaves an EMPTY verse line, on
 * EVERY line and not only the first (PART 9 §23; carve-php#1393).
 *
 * §23 spells the rule out: a comment-only body line "leaves an EMPTY verse line
 * rather than disappearing - the line was written, so the stanza keeps its
 * shape".
 *
 * IT IS DECIDED AT THE BLOCK LAYER (carve#1333). `comment_line` is a BLOCK -
 * PART 1 lists it among the invisible ones and §10 I5 rules it - so §23 removes
 * the line WITH the other block-layer decisions, before any inline content
 * exists. Deciding it during the stanza's one inline pass let an unclosed
 * verbatim run opened on an EARLIER line claim the line under §21's verbatim
 * exclusion and publish the comment, on a document whose only defect is a stray
 * backtick above it.
 *
 * The reach was the earlier half (carve-php#1393): a stanza is parsed as ONE
 * inline run, so every body line but the first reached the `%%` test with a
 * NEWLINE before it, matched neither arm, and fell through as ordinary text.
 * That is fixed and unchanged here; what moves is WHEN the line is decided.
 *
 * The TRAILING comment is a different construct and does not move with it.
 * `x %% secret` is `inline_comment` (PART 3, §21), and §21's third bullet
 * leaves it standing inside a verbatim run: an engine may leave a `%%` in a run
 * and may never delete author bytes out of one.
 *
 * Two things have to hold together and are asserted apart below: the comment
 * TEXT is gone, and the LINE is still there. Dropping the row would keep a
 * secret out of the output and still be wrong, because a line block exists to
 * preserve a layout.
 */
class LineBlockCommentOnlyLineTest extends TestCase
{
    private function convert(string $source): string
    {
        return (new CarveConverter())->convert($source);
    }

    public function testACommentBetweenTwoVerseLinesLeavesAnEmptyLine(): void
    {
        $source = <<<'CARVE'
        ::: |
        a
        %% secret comment
        c
        :::

        CARVE;

        $expected = <<<'HTML'
        <div class="line-block">
          <p>a<br>
        <br>
        c</p>
        </div>

        HTML;

        $this->assertSame($expected, $this->convert($source));
    }

    public function testTheCommentTextNeverReachesTheOutput(): void
    {
        $source = <<<'CARVE'
        ::: |
        a
        %% secret comment
        c
        :::

        CARVE;

        $this->assertStringNotContainsString('secret', $this->convert($source));
        $this->assertStringNotContainsString('%%', $this->convert($source));
    }

    public function testACommentOnTheLastLineLeavesAnEmptyLine(): void
    {
        $source = <<<'CARVE'
        ::: |
        a
        %% c
        :::

        CARVE;

        $expected = <<<'HTML'
        <div class="line-block">
          <p>a<br>
        </p>
        </div>

        HTML;

        $this->assertSame($expected, $this->convert($source));
    }

    public function testTheFirstLineStillDrops(): void
    {
        // The branch that always worked: at offset 0 of the stanza's inline run
        // the `%%` needs no preceding whitespace at all. Pinned so a fix to the
        // later lines cannot be bought by breaking this one.
        $source = <<<'CARVE'
        ::: |
        %% c
        b
        :::

        CARVE;

        $expected = <<<'HTML'
        <div class="line-block">
          <p><br>
        b</p>
        </div>

        HTML;

        $this->assertSame($expected, $this->convert($source));
    }

    public function testASoleCommentLineLeavesAnEmptyStanza(): void
    {
        $source = <<<'CARVE'
        ::: |
        %% c
        :::

        CARVE;

        $expected = <<<'HTML'
        <div class="line-block">
          <p></p>
        </div>

        HTML;

        $this->assertSame($expected, $this->convert($source));
    }

    public function testTwoCommentLinesLeaveTwoEmptyLines(): void
    {
        $source = <<<'CARVE'
        ::: |
        %% c1
        %% c2
        :::

        CARVE;

        $expected = <<<'HTML'
        <div class="line-block">
          <p><br>
        </p>
        </div>

        HTML;

        $this->assertSame($expected, $this->convert($source));
    }

    public function testEachStanzaKeepsItsOwnShape(): void
    {
        // A blank line ends the stanza, so the comment below it is the FIRST
        // line of the second one. Both paths meet here.
        $source = <<<'CARVE'
        ::: |
        a
        %% one
        b

        %% two
        c
        :::

        CARVE;

        $expected = <<<'HTML'
        <div class="line-block">
          <p>a<br>
        <br>
        b</p>
          <p><br>
        c</p>
        </div>

        HTML;

        $this->assertSame($expected, $this->convert($source));
    }

    public function testACommentWithNoSeparatingSpaceIsStillAComment(): void
    {
        // §21 requires whitespace BEFORE `%%`, never after it, so `%%c` is a
        // comment with `c` as its body.
        $source = <<<'CARVE'
        ::: |
        a
        %%c
        b
        :::

        CARVE;

        $expected = <<<'HTML'
        <div class="line-block">
          <p>a<br>
        <br>
        b</p>
        </div>

        HTML;

        $this->assertSame($expected, $this->convert($source));
    }

    public function testABareMarkerIsAnEmptyComment(): void
    {
        $source = <<<'CARVE'
        ::: |
        a
        %%
        b
        :::

        CARVE;

        $expected = <<<'HTML'
        <div class="line-block">
          <p>a<br>
        <br>
        b</p>
        </div>

        HTML;

        $this->assertSame($expected, $this->convert($source));
    }

    public function testATrailingCommentAfterTextStillStripsOnALaterLine(): void
    {
        // The case that always worked, because a SPACE precedes the marker.
        // The text before it survives, so this line is not an empty one.
        $source = <<<'CARVE'
        ::: |
        a
        x %% secret
        c
        :::

        CARVE;

        $expected = <<<'HTML'
        <div class="line-block">
          <p>a<br>
        x<br>
        c</p>
        </div>

        HTML;

        $this->assertSame($expected, $this->convert($source));
    }

    public function testAnIndentedCommentLineStaysVerse(): void
    {
        // Leading whitespace in verse is CONTENT, not indentation - it is
        // preserved rather than stripped, so the `%%` does not start the line
        // and no comment is recognized. carve-js reads it the same way. This is
        // the boundary of the fix and is pinned so widening the whitespace class
        // any further would fail here.
        $source = <<<'CARVE'
        ::: |
        a
          %% c
        b
        :::

        CARVE;

        $html = $this->convert($source);

        $this->assertStringContainsString('%% c', $html);
    }

    public function testAnEscapedMarkerOnALaterLineStaysLiteral(): void
    {
        $source = <<<'CARVE'
        ::: |
        a
        \%% c
        b
        :::

        CARVE;

        $expected = <<<'HTML'
        <div class="line-block">
          <p>a<br>
        %% c<br>
        b</p>
        </div>

        HTML;

        $this->assertSame($expected, $this->convert($source));
    }

    public function testAPercentRunThatIsNotAMarkerIsUntouched(): void
    {
        $source = <<<'CARVE'
        ::: |
        a
        50%% off
        b
        :::

        CARVE;

        $expected = <<<'HTML'
        <div class="line-block">
          <p>a<br>
        50%% off<br>
        b</p>
        </div>

        HTML;

        $this->assertSame($expected, $this->convert($source));
    }

    public function testACodeSpanReachingTheLineCannotClaimIt(): void
    {
        // THE BLOCK LAYER DECIDES FIRST (§23, carve#1333). The span is unclosed
        // on the line above and reaches the end of the BLOCK, but the comment
        // line is gone before the span exists, so there is nothing on that line
        // for the run to swallow but the line ending.
        //
        // The closing backtick is INSIDE the comment, which is what makes this
        // discriminating: it never closes the span, so the span still runs to
        // the end of the block and still holds the empty line the comment left.
        $source = <<<'CARVE'
        ::: |
        a `x
        %% c` y
        b
        :::

        CARVE;

        $expected = <<<'HTML'
        <div class="line-block">
          <p>a <code>x

        b</code></p>
        </div>

        HTML;

        $this->assertSame($expected, $this->convert($source));
    }

    /**
     * The reported document, and the one the ruling turns on: one stray
     * backtick above a comment used to PUBLISH it (carve#1333).
     */
    public function testAStrayBacktickAboveACommentDoesNotPublishIt(): void
    {
        $source = <<<'CARVE'
        ::: |
        a `b
        %% secret
        c
        :::

        CARVE;

        $expected = <<<'HTML'
        <div class="line-block">
          <p>a <code>b

        c</code></p>
        </div>

        HTML;

        $this->assertSame($expected, $this->convert($source));
        $this->assertStringNotContainsString('secret', $this->convert($source));
    }

    /**
     * IT DOES NOT SURVIVE A RUN THAT ATE ITS LINE -- NORMATIVE (PART 9 §23).
     *
     * What an unclosed run carries across an emptied line is the NEWLINE, the
     * same thing it carries across every boundary it swallows, so there is no
     * boundary left in the tree for a `comment` node to sit on: the run's value
     * holds an EMPTY LINE instead. Appending the node anyway puts its span
     * before the run that contains it and after the node that follows it,
     * which PART 12 containment refuses.
     *
     * The writer's answer for that empty line is PART 11 §7c, pinned below.
     */
    public function testACommentTheRunAteIsNotInTheTree(): void
    {
        $source = <<<'CARVE'
        ::: |
        a `b
        %% c
        d` e
        f
        :::

        CARVE;

        $paragraph = (new CarveConverter())->parse($source)->getChildren()[0]->getChildren()[0];
        $types = array_map(
            static fn ($node): string => $node->getType(),
            $paragraph->getChildren(),
        );

        $this->assertSame(['text', 'code', 'text', 'hard_break', 'text'], $types);
    }

    /**
     * THE BOUNDARY HAS TO BE IN THE TREE, not merely counted to.
     *
     * A run that swallowed the LAST of several boundaries lands the walk on the
     * right number by a different route, and the line it opens is inside the
     * run's value rather than between two nodes. Both comments here are the
     * run's; neither is a node.
     */
    public function testTheLastSwallowedCommentIsNotKeptByArithmetic(): void
    {
        $converter = new CarveConverter();

        $this->assertSame([], self::commentContents($converter->parse("::: |\na `x\n%% c\n%% d\n:::\n")));
        // The control: one boundary further out, where the second comment's own
        // line boundary survives the run and the node with it.
        $this->assertSame(
            ['d'],
            self::commentContents($converter->parse("::: |\na `x\n%% c\ny` z\n%% d\ne\n:::\n")),
        );
    }

    /**
     * The comment keeps the SPAN the inline reader used to give it (PART 12
     * §4). The text and the breaks around it still carry theirs, so a node
     * that lost its position when the deciding layer moved would show up
     * nowhere else.
     */
    public function testTheCommentNodeKeepsItsPosition(): void
    {
        $converter = CarveConverter::create(new BlockParser(false, false, false, true), new HtmlRenderer());
        $paragraph = $converter->parse("::: |\na\n%% c\nb\n:::\n")->getChildren()[0]->getChildren()[0];
        $comment = $paragraph->getChildren()[2];
        $position = $comment->getPos();

        $this->assertNotNull($position, 'the verse comment lost its position');
        $this->assertSame(3, $position->startLine);
        $this->assertSame(8, $position->startOffset);
        $this->assertSame(12, $position->endOffset);
    }

    /**
     * AND THE RUN THAT SWALLOWED A COMMENT GIVES UP ITS OWN POSITION.
     *
     * Taking the comment out of the middle of the run leaves the run's value
     * discontiguous in the source, which is PART 12 §4's reassembled-node case:
     * no offset pair equals that value, so the run omits `pos` rather than
     * publish one its value is not a slice of.
     */
    public function testTheRunThatSwallowedACommentGivesUpItsPosition(): void
    {
        $converter = CarveConverter::create(new BlockParser(false, false, false, true), new HtmlRenderer());
        $paragraph = $converter->parse("::: |\na `b\n%% c\nd` e\nf\n:::\n")->getChildren()[0]->getChildren()[0];
        $children = $paragraph->getChildren();

        $this->assertNull($children[1]->getPos(), 'the run reports a span its value is not a slice of');
        $this->assertNotNull($children[0]->getPos(), 'the text before it must keep its span');

        // And no two placed siblings overlap, which is what the omission buys.
        $placed = [];
        foreach ($children as $child) {
            if ($child->getPos() !== null) {
                $placed[] = [$child->getPos()->startOffset, $child->getPos()->endOffset];
            }
        }
        $sorted = $placed;
        usort($sorted, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $this->assertSame($placed, $sorted, 'placed siblings are out of document order');
        for ($i = 1, $last = count($placed); $i < $last; $i++) {
            $this->assertGreaterThanOrEqual($placed[$i - 1][1], $placed[$i][0], 'two placed siblings overlap');
        }
    }

    /**
     * A COMMENT LINE TAKES NO BACKSLASH. `%%` consumes the rest of its line, so
     * a backslash written after it is comment TEXT, not break syntax, and the
     * comment comes back holding a character the author never wrote.
     *
     * No rendering can see this - a comment renders nothing either way - so the
     * assertion is on the tree.
     */
    public function testAnEmptyOrSpaceEndingCommentSurvivesTheWriter(): void
    {
        foreach (["::: |\n%%\nb\n:::\n", "::: |\na\n%% x \nb\n:::\n"] as $source) {
            $converter = new CarveConverter();
            $before = $converter->parse($source);
            $after = $converter->parse(CarveConverter::toCarve($source));

            $this->assertSame(
                self::commentContents($before),
                self::commentContents($after),
                'the writer changed a comment for: ' . $source,
            );
        }
    }

    /**
     * A COMMENT UNDER AN INLINE CONTAINER IS KEPT, at the boundary it opens.
     *
     * The placement walked the stanza's TOP-LEVEL nodes only, so a container
     * spanning the emptied line held the boundary among its own children, the
     * walk stepped over the container in one move, and the author's text was
     * dropped entirely (markup-carve/carve-php#1411).
     *
     * NEITHER GATE COULD SEE IT. The comment publishes nothing, so an HTML
     * comparison agrees before and after; and the writer's bare `%%` re-parses
     * to the tree the loss produced, so `parse(fmt(x)) == parse(x)` holds while
     * the text is gone - the limit named on markup-carve/carve#1340. So the
     * assertions are on the TREE and on the written BYTES.
     *
     * @return array<string, array{string, string}>
     */
    public static function nestedVerseCommentProvider(): array
    {
        return [
            'strong' => ["::: |\n*a\n%% secret\nc*\n:::\n", 'strong'],
            'emphasis' => ["::: |\n/a\n%% secret\nc/\n:::\n", 'emphasis'],
            // Two containers deep: the walk has to recurse rather than look one
            // level down, which a fix written for the reported shape alone
            // would pass without doing.
            'emphasis inside strong' => ["::: |\n*/a\n%% secret\nc/*\n:::\n", 'emphasis'],
            // Not an emphasis run at all, so the descent cannot be keyed to the
            // constructs that happen to close at a line ending.
            'link label' => ["::: |\n[a\n%% secret\nc](/u)\n:::\n", 'link'],
        ];
    }

    #[DataProvider('nestedVerseCommentProvider')]
    public function testACommentUnderAnInlineContainerIsKept(string $source, string $container): void
    {
        $converter = new CarveConverter();
        $paragraph = $converter->parse($source)->getChildren()[0]->getChildren()[0];

        // THE TREE: the node sits inside the container, not beside it.
        $this->assertSame(['secret'], self::commentContents($paragraph));
        $held = self::commentHolder($paragraph);
        $this->assertNotNull($held, 'the comment is not under any container');
        $this->assertSame($container, $held->getType());

        // THE BYTES: the author's own text comes back, which is the whole of
        // what was lost. A bare `%%` re-parses to the same tree the loss
        // produced, so only the written bytes can tell the two apart.
        $this->assertSame($source, CarveConverter::toCarve($source));
    }

    /**
     * A comment on the stanza's FIRST line keeps its text too.
     *
     * It is the one line no boundary opens - the stanza's own opening does -
     * so it is drawn before the walk starts rather than after a break, and
     * that is a second arm which the rest of this file could not see: every
     * other case here asserts on HTML, and a comment renders nothing whether
     * it is in the tree or gone. Only the text can tell.
     */
    public function testAFirstLineCommentKeepsItsText(): void
    {
        $source = "::: |\n%% secret\nb\n:::\n";
        $paragraph = (new CarveConverter())->parse($source)->getChildren()[0]->getChildren()[0];

        $this->assertSame(['secret'], self::commentContents($paragraph));
        $this->assertSame($source, CarveConverter::toCarve($source));
    }

    /**
     * The break spelling at a nested boundary is NOT decided here.
     *
     * Whether a line block's break hardens at a nested boundary is a separate
     * and contested question (markup-carve/carve#1351). The comment belongs at
     * the boundary whichever way the boundary is spelled, so the placement
     * descends while the soft-to-hard conversion deliberately does not - and
     * this pins that the two stayed apart.
     */
    public function testTheNestedBreakSpellingIsUnchanged(): void
    {
        $paragraph = (new CarveConverter())
            ->parse("::: |\n*a\n%% secret\nc*\n:::\n")
            ->getChildren()[0]->getChildren()[0];

        $types = array_map(
            static fn (Node $node): string => $node->getType(),
            $paragraph->getChildren()[0]->getChildren(),
        );

        $this->assertSame(['text', 'soft_break', 'comment', 'soft_break', 'text'], $types);
    }

    /**
     * A run that ate the line still takes the comment with it, at DEPTH.
     *
     * The descent must not turn the normative §23 refusal above into a
     * placement: the boundary is inside the run's value wherever the run sits,
     * so a run nested in a container drops its comment exactly as a top-level
     * one does, and gives up its own position for the same reason.
     */
    public function testANestedRunThatAteTheLineStillDropsTheComment(): void
    {
        $converter = CarveConverter::create(new BlockParser(false, false, false, true), new HtmlRenderer());
        $paragraph = $converter->parse("::: |\n*a `b\n%% c\nd` e*\nf\n:::\n")->getChildren()[0]->getChildren()[0];
        $strong = $paragraph->getChildren()[0];

        $this->assertSame([], self::commentContents($paragraph));
        $this->assertNull(
            $strong->getChildren()[1]->getPos(),
            'the run reports a span its value is not a slice of',
        );
    }

    /**
     * The innermost container holding a `comment` child, or null.
     */
    private static function commentHolder(Node $node): ?Node
    {
        foreach ($node->getChildren() as $child) {
            $deeper = self::commentHolder($child);
            if ($deeper !== null) {
                return $deeper;
            }
            if ($child instanceof Comment) {
                return $node;
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private static function commentContents(Node $node): array
    {
        $found = [];
        foreach ($node->getChildren() as $child) {
            if ($child instanceof Comment) {
                $found[] = $child->getContent();
            }
            $found = array_merge($found, self::commentContents($child));
        }

        return $found;
    }

    /**
     * AN UNCLOSED RUN'S REMAINDER IS VERBATIM, TRAILING WHITESPACE INCLUDED.
     *
     * A run with no closer reaches the end of the BLOCK (PART 2) and what it
     * reaches is verbatim, so the boundary it swallowed is content like the
     * rest of it. Math used to rtrim the remainder where the code span it
     * shares its span rule with takes it raw - the one construct that parted
     * from the others, and only visible where a container leaves a boundary at
     * the end of a run.
     *
     * Asserted on the VALUE rather than through the writer, because the writer
     * has nothing to spell once the trailing line is gone: `fmt` closes the run
     * at the end of the content it was handed either way, so the round trip is
     * green on both readings and only the node says which one happened.
     */
    public function testAnUnclosedRunKeepsTheBoundaryItSwallowed(): void
    {
        $converter = new CarveConverter();

        foreach (['`x', '$`x', '!`x', '$$`x'] as $opener) {
            $paragraph = $converter->parse("::: |\na " . $opener . "\n%% c\n:::\n")
                ->getChildren()[0]
                ->getChildren()[0];
            $run = $paragraph->getChildren()[1];

            $this->assertSame(
                "x\n",
                $run->getContent(),
                'the run dropped the boundary it swallowed after: ' . $opener,
            );
        }
    }

    /**
     * THE RUN CARRIES THE LINE EVEN THOUGH IT DOES NOT CARRY THE NODE.
     *
     * The empty line the removal leaves survives inside the run's value as a
     * NEWLINE, and PART 11 §7c spells it `%%` - the one spelling of an empty
     * verse line that does not end the stanza. Math used to rtrim those
     * newlines away where a code span keeps them, so the line was gone too and
     * the writer produced a BLANK line, which returns one stanza as two.
     *
     * Every verbatim kind is checked, because the strip is per-construct and
     * the divergence was in exactly one of them.
     */
    public function testEveryVerbatimRunKeepsTheLineItSwallowed(): void
    {
        $converter = new CarveConverter();

        foreach (['`x', '$`x', '!`x', '$$`x'] as $opener) {
            $source = "::: |\na " . $opener . "\n%% c\n%% d\n:::\n";
            $formatted = CarveConverter::toCarve($source);

            $this->assertStringNotContainsString(
                "\n\n",
                $formatted,
                'the writer left a blank line, which ends the stanza, after: ' . $opener,
            );
            $this->assertSame($converter->convert($source), $converter->convert($formatted));
            $this->assertSame($formatted, CarveConverter::toCarve($formatted));
            // The author's own comment TEXT is not recoverable here and PART 11
            // §7c says it is not required to be: the run consumed it.
            $this->assertSame([], self::commentContents($converter->parse($formatted)));
        }
    }

    /**
     * The INLINE half is untouched, and the asymmetry is deliberate: an engine
     * may leave a `%%` standing inside a verbatim run, and may never delete
     * author bytes out of one (§21's third bullet).
     */
    public function testATrailingCommentInsideARunIsStillContent(): void
    {
        $source = <<<'CARVE'
        ::: |
        a `b
        x %% secret
        c
        :::

        CARVE;

        $expected = <<<'HTML'
        <div class="line-block">
          <p>a <code>b
        x %% secret
        c</code></p>
        </div>

        HTML;

        $this->assertSame($expected, $this->convert($source));
    }

    public function testTheRuleHoldsInsideAQuote(): void
    {
        $source = <<<'CARVE'
        > ::: |
        > a
        > %% c
        > b
        > :::

        CARVE;

        $html = $this->convert($source);

        $this->assertStringNotContainsString('%%', $html);
        $this->assertStringContainsString("a<br>\n<br>\nb", $html);
    }

    public function testFormattingKeepsTheCommentAtTheStartOfItsLine(): void
    {
        // `carve fmt` used to write the marker with a SEPARATOR SPACE in front
        // of it. Leading whitespace in verse is preserved content, so the
        // formatted line no longer started with `%%`, the reparse read the
        // marker as ordinary text, and the formatter PUBLISHED the comment it
        // was handed. carve-rs writes no space here; this is now byte-identical
        // to what it emits.
        $source = <<<'CARVE'
        ::: |
        a
        %% secret
        b
        :::

        CARVE;

        $expected = <<<'CARVE'
        ::: |
        a
        %% secret
        b
        :::

        CARVE;

        $this->assertSame($expected, CarveConverter::toCarve($source));
    }

    public function testFormattingAComentOnlyVerseLineRoundTrips(): void
    {
        // PART 10's invariant: toHtml(fmt(x)) == toHtml(x). The first-line form
        // failed it before this change too, so it is pinned alongside.
        foreach (
            [
                "::: |\n%% c\nb\n:::\n",
                "::: |\na\n%% c\nb\n:::\n",
                "::: |\na\n%% c\n:::\n",
                "::: |\na\nx %% c\nb\n:::\n",
                // The run shapes. A comment whose line an unclosed run reaches
                // has no boundary of its own to sit at, so the writer has to
                // put it back on the empty line the removal left - otherwise
                // that line is BLANK on the way in, ends the stanza, and the
                // comment is published besides.
                "::: |\na `b\n%% c\nd\n:::\n",
                "::: |\na `b\n%% c\n%% d\ne\n:::\n",
                "::: |\na `b\n%% c\nd\n\ne `f\n%% g\nh\n:::\n",
            ] as $source
        ) {
            $formatted = CarveConverter::toCarve($source);

            $this->assertSame(
                $this->convert($source),
                $this->convert($formatted),
                'fmt changed the rendering of: ' . $source,
            );
            $this->assertStringNotContainsString('%%', $this->convert($formatted));
        }
    }

    public function testFormattingKeepsALiteralMarkerEscaped(): void
    {
        // The dangerous direction of the same change. Recognizing `%%` at the
        // start of a later verse line makes that position MEANINGFUL, so a
        // verse line whose text merely begins with `%%` has to keep its escape
        // through the formatter - writing it bare would hand the next parse a
        // comment and delete the author's line. `%` is in the escapable set at
        // column 0 for exactly this reason.
        $source = <<<'CARVE'
        ::: |
        a
        \%% c
        b
        :::

        CARVE;

        $formatted = CarveConverter::toCarve($source);

        $this->assertStringContainsString('\\%% c', $formatted);
        $this->assertSame($this->convert($source), $this->convert($formatted));
    }

    public function testFormattingIsIdempotentOnAVerseComment(): void
    {
        $once = CarveConverter::toCarve("::: |\na\n%% c\nb\n:::\n");

        $this->assertSame($once, CarveConverter::toCarve($once));
    }

    public function testATrailingCommentKeepsItsSeparatorSpace(): void
    {
        // The other side of the same branch: a comment that FOLLOWS text still
        // needs the space, or the marker would weld onto the word before it and
        // stop being a marker at all (§21: `a%%b` is literal).
        $formatted = CarveConverter::toCarve("a %% c\n");

        $this->assertSame("a %% c\n", $formatted);
        $this->assertSame($this->convert("a %% c\n"), $this->convert($formatted));
    }

    public function testTheRuleHoldsInsideAListItem(): void
    {
        $source = <<<'CARVE'
        - ::: |
          a
          %% c
          b
          :::

        CARVE;

        $html = $this->convert($source);

        $this->assertStringNotContainsString('%%', $html);
        $this->assertStringContainsString("a<br>\n<br>\nb", $html);
    }
}
