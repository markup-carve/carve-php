<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\Ast\AstSchema;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\DefinitionList;
use MarkupCarve\Carve\Node\Block\ListBlock;
use MarkupCarve\Carve\ProseMirror\ProseMirrorRenderer;
use MarkupCarve\Carve\ProseMirror\ProseMirrorToCarve;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PART 9 §17 L7 (markup-carve/carve#1612, markup-carve/carve#1623,
 * markup-carve/carve-php#1634).
 *
 * A container's preceding BLOCK-ATTRIBUTE LINE may carry the boolean `loose`.
 * It says the container's children render as BLOCKS rather than as inline runs,
 * and it is CONSUMED: it never reaches the output as an HTML attribute. The
 * precedent is PART 12 §15's `header-rows`, which rides the same line, carries a
 * structural fact as a boolean, and is likewise consumed rather than emitted.
 *
 * The key reaches the shapes a blank line cannot spell, because a blank line
 * needs two things to stand between:
 *
 *   - a ONE-ITEM list has no "between items" to put one in;
 *   - a definition description holding ONE block has none at ANY entry count,
 *     since a blank line between two ENTRIES does not loosen a `<dl>` at all.
 */
class AConsumedLooseBooleanSpellsTheLoosenessNoBlankLineCanTest extends TestCase
{
    private CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = CarveConverter::create();
    }

    private function html(string $source): string
    {
        return trim($this->converter->convert($source));
    }

    private function fmt(string $source): string
    {
        return $this->converter->toCarve($source);
    }

    public function testItLoosensAOneItemList(): void
    {
        $this->assertSame(
            "<ul>\n  <li><p>Note text.</p></li>\n</ul>",
            $this->html("{loose}\n- Note text.\n"),
        );
    }

    public function testItLoosensAOneBlockDefinitionDescription(): void
    {
        $this->assertSame(
            "<dl>\n  <dt>Term</dt>\n  <dd><p>Definition.</p></dd>\n</dl>",
            $this->html("{loose}\n:: Term\n:  Definition.\n"),
        );
    }

    /**
     * CONSUMPTION IS ITS OWN ASSERTION. A fixture that only checks the container
     * is loose passes with `loose=""` still on the tag, because the `<p>` and the
     * stray attribute are independent. Both containers assert it separately, so
     * neither half can be bought by breaking the other.
     */
    public function testTheListEmitsNoLooseAttribute(): void
    {
        $this->assertStringNotContainsString('loose', $this->html("{loose}\n- Note text.\n"));
    }

    public function testTheDefinitionListEmitsNoLooseAttribute(): void
    {
        $this->assertStringNotContainsString('loose', $this->html("{loose}\n:: Term\n:  Definition.\n"));
    }

    public function testItConsumesOnlyLooseAndKeepsTheIdAndClassBesideIt(): void
    {
        $this->assertSame(
            "<ul id=\"i\" class=\"note\">\n  <li><p>x</p></li>\n</ul>",
            $this->html("{#i loose .note}\n- x\n"),
        );
    }

    /**
     * PART 4 makes `{loose}` and `{loose=""}` the SAME attribute, so both are
     * this key.
     */
    public function testAnEmptyValueIsTheSameKey(): void
    {
        $this->assertSame("<ul>\n  <li><p>x</p></li>\n</ul>", $this->html("{loose=\"\"}\n- x\n"));
    }

    /**
     * `loose=x` names a value this key does not take, so it is not this key at
     * all: it stays an ordinary attribute and renders. There is no error state.
     */
    public function testAValuedLooseStaysAnOrdinaryAttribute(): void
    {
        $this->assertSame("<ul loose=\"x\">\n  <li>x</li>\n</ul>", $this->html("{loose=x}\n- x\n"));
    }

    /**
     * THE NAME IS RESERVED NOWHERE ELSE. The tight/loose axis exists in exactly
     * two containers, so on anything else `loose` has no special meaning.
     */
    public function testItStaysAnOrdinaryBooleanOnAContainerWithNoSuchAxis(): void
    {
        $this->assertSame('<blockquote loose=""><p>q</p></blockquote>', $this->html("{loose}\n> q\n"));
    }

    public function testAnOrderedListTakesTheSameKey(): void
    {
        $this->assertSame("<ol>\n  <li><p>a</p></li>\n</ol>", $this->html("{loose}\n1. a\n"));
    }

    /**
     * At the nested container's OWN indent, so it loosens the sub-list and not
     * its parent - the outer item stays tight and keeps its lead text inline.
     */
    public function testItLoosensTheSubListAndNotItsParent(): void
    {
        $this->assertSame(
            "<ul>\n  <li>outer\n    <ul>\n      <li><p>inner</p></li>\n    </ul>\n  </li>\n</ul>",
            $this->html("- outer\n  {loose}\n  - inner\n"),
        );
    }

    /**
     * REDUNDANT USE IS A LEGAL NO-OP. Rejecting it would make the key
     * context-sensitive, and a producer that always emits it is simpler than one
     * that has to decide.
     */
    public function testItChangesNothingOnAListTheBlankLinesAlreadyLoosened(): void
    {
        $this->assertSame($this->html("- a\n\n- b\n"), $this->html("{loose}\n- a\n\n- b\n"));
    }

    public function testItChangesNothingOnADescriptionThatAlreadyHoldsTwoBlocks(): void
    {
        $this->assertSame(
            $this->html(":: T\n:  a\n\n   b\n"),
            $this->html("{loose}\n:: T\n:  a\n\n   b\n"),
        );
    }

    /**
     * A SIBLING'S SECOND BLOCK SAYS NOTHING ABOUT THIS DESCRIPTION. Only a second
     * block inside the SAME description wraps it, so the key is not redundant.
     */
    public function testItLoosensEveryDescriptionNotOnlyTheOnesThatAlreadyHadTwoBlocks(): void
    {
        $this->assertSame(
            "<dl>\n  <dt>T</dt>\n  <dd><p>a</p></dd>\n  <dt>U</dt>\n  <dd>\n    <p>x</p>\n    <p>y</p>\n  </dd>\n</dl>",
            $this->html("{loose}\n:: T\n:  a\n:: U\n:  x\n\n   y\n"),
        );
    }

    /**
     * PART 12 §8 GREW THE FIELD (markup-carve/carve#1624), so the answer this
     * file used to pin is reversed rather than deleted: the flag no longer
     * survives in SOURCE only. It is published, and only where it was SPELLED.
     *
     * NO HTML CHECK REACHES THIS. The direct render is right either way - the
     * `<p>` wrapper comes off the live node - so a green HTML corpus says
     * nothing about it. The wire is the only place the answer appears.
     */
    public function testTheSpelledLoosenessIsPublished(): void
    {
        $payload = (new AstCodec())->encode($this->converter->parse("{loose}\n:: T\n:  a\n"));
        $list = $payload['children'][0];

        $this->assertSame('definition_list', $list['type']);
        $this->assertTrue($list['loose'] ?? null, 'the spelled looseness did not reach the wire');
        $this->assertNull(AstSchema::firstViolation($payload), 'the published tree left the schema');
    }

    /**
     * `const: true`, so ABSENT rather than `false`. It is deliberately not a
     * `tight` field: an absent boolean read as false would say LOOSE, the
     * opposite of the default - which is why asserting the KEY is missing is
     * the assertion, and asserting a false value would pin the wrong shape.
     */
    public function testADerivedDefinitionListPublishesNoLooseKey(): void
    {
        $payload = (new AstCodec())->encode($this->converter->parse(":: T\n:  a\n"));
        $list = $payload['children'][0];

        $this->assertSame('definition_list', $list['type']);
        $this->assertArrayNotHasKey('loose', $list);
        $this->assertNull(AstSchema::firstViolation($payload));
    }

    /**
     * THE FACT THE FIELD EXISTS FOR. A `<dl>` has no blank-line spelling for a
     * one-block description at any entry count, so with nothing on the wire
     * saying the key was written, the tree comes back deriving each wrapper
     * from its block count and the `<p>` the author asked for is gone. Corpus
     * `407-one-consumed-boolean-spells-the-looseness-no-blank-line-can-2` is
     * this document.
     */
    public function testTheLoosenessSurvivesAnAstRoundTrip(): void
    {
        $source = "{loose}\n:: Term\n:  Definition.\n";
        $codec = new AstCodec();
        $back = $codec->decode($codec->encode($this->converter->parse($source)));

        $this->assertSame(
            "<dl>\n  <dt>Term</dt>\n  <dd><p>Definition.</p></dd>\n</dl>",
            trim((new HtmlRenderer())->render($back)),
        );
    }

    /**
     * The same fact over the editor bridge, which carries it under its own
     * prefixed name beside PART 12 §8's.
     */
    public function testTheLoosenessSurvivesTheProseMirrorBridge(): void
    {
        $source = "{loose}\n:: Term\n:  Definition.\n";
        $payload = (new ProseMirrorRenderer())->render($this->converter->parse($source));
        $back = (new ProseMirrorToCarve())->convert($payload);

        $this->assertSame(
            "<dl>\n  <dt>Term</dt>\n  <dd><p>Definition.</p></dd>\n</dl>",
            trim((new HtmlRenderer())->render($back)),
        );
    }

    /**
     * THE BOUND, on the ingest side: a payload that does not spell the key
     * decodes to a list that derives its wrappers, so a decoder that read the
     * absence as anything but the default would show up here.
     */
    public function testAPayloadWithNoLooseKeyDecodesToADerivedList(): void
    {
        $codec = new AstCodec();
        $payload = $codec->encode($this->converter->parse("{loose}\n:: Term\n:  Definition.\n"));
        unset($payload['children'][0]['loose']);
        $list = $codec->decode($payload)->getChildren()[0];

        $this->assertInstanceOf(DefinitionList::class, $list);
        $this->assertFalse($list->isLoose());
        $this->assertSame(
            "<dl>\n  <dt>Term</dt>\n  <dd>Definition.</dd>\n</dl>",
            trim((new HtmlRenderer())->render($codec->decode($payload))),
        );
    }

    public function testTheListSetsItsExistingTightFieldAndKeepsNoAttributes(): void
    {
        $list = $this->converter->parse("{loose}\n- a\n")->getChildren()[0] ?? null;
        $this->assertInstanceOf(ListBlock::class, $list);
        $this->assertFalse($list->isTight());
        $this->assertSame([], $list->getAttributes());
    }

    /**
     * PART 9 §17 L7's WRITER RULE: the key is spelled only where the blank-line
     * spelling cannot express the looseness.
     *
     * This is the load-bearing rule for churn - emitting it on every loose
     * container would rewrite a large share of every document anyone has written.
     * It follows PART 12 §15's writer, which retains `header-rows` rather than
     * deriving it onto every table, and PART 11 §2, which spends a mark only
     * where omitting it would change the re-parsed document.
     */
    public function testTheWriterSpellsItOnTheOneItemList(): void
    {
        $this->assertSame("{loose}\n- Note text.\n", $this->fmt("{loose}\n- Note text.\n"));
    }

    public function testTheWriterSpellsItOnTheOneBlockDefinitionDescription(): void
    {
        $this->assertSame(
            "{loose}\n:: Term\n: Definition.\n",
            $this->fmt("{loose}\n:: Term\n:  Definition.\n"),
        );
    }

    /**
     * The corpus control: a multi-item loose list whose blank lines already say
     * it. The HTML is byte-identical with and without the key, so only the
     * written source can see this rule.
     */
    public function testTheWriterDoesNotDeriveItOntoAListTheBlankLinesAlreadyLoosened(): void
    {
        $this->assertSame("- alpha\n\n- beta\n", $this->fmt("- alpha\n\n- beta\n"));
    }

    /**
     * A redundant key the AUTHOR wrote is dropped too: the parser consumed it, so
     * the writer re-derives the spelling from the tree rather than echoing it.
     */
    public function testTheWriterDropsARedundantKeyTheAuthorWrote(): void
    {
        $this->assertSame("- alpha\n\n- beta\n", $this->fmt("{loose}\n- alpha\n\n- beta\n"));
    }

    /**
     * ON A DEFINITION LIST THE ANSWER IS UNCONDITIONAL (§17 L7, ruled in
     * markup-carve/carve-rs#1305 / markup-carve/carve#1639). The looseness field
     * is set ONLY where the key was spelled, because a blank line between two
     * ENTRIES does not loosen a `<dl>` at any count - so a body written without
     * the key can never read back with the field set, and the re-parse test says
     * "emit" every time.
     *
     * A description already holding two blocks does not change it. The key is
     * redundant in the RENDER there - both spellings wrap the `<dd>` - and it is
     * NOT redundant in the tree, and the tree is what PART 11 §1's equality is
     * taken over. This engine read the redundancy off the render and dropped the
     * key, so `fmt` deleted a fact the document stated.
     */
    public function testTheWriterDecoratesADefinitionListUnconditionally(): void
    {
        $this->assertSame("{loose}\n:: T\n: a\n\n  b\n", $this->fmt("{loose}\n:: T\n:  a\n\n   b\n"));
        $this->assertSame("{loose}\n:: T\n: a\n:: U\n: b\n", $this->fmt("{loose}\n:: T\n:  a\n:: U\n:  b\n"));
    }

    /**
     * And a `<dl>` that never carried the key still never gains one.
     */
    public function testTheWriterDoesNotDeriveTheKeyOntoADefinitionListThatDidNotSpellIt(): void
    {
        $this->assertSame(":: T\n: a\n\n  b\n", $this->fmt(":: T\n:  a\n\n   b\n"));
        $this->assertSame(":: T\n: a\n", $this->fmt(":: T\n:  a\n"));
    }

    /**
     * THE TEST IS A RE-PARSE OVER THE DOCUMENT, not over the render. Written
     * without the key this `<dl>` reads back with no looseness at all - which is
     * exactly what the render cannot see, since both spellings wrap the `<dd>`.
     */
    public function testTheLoosenessSurvivesAFormatPassWhereTheRenderCannotSeeIt(): void
    {
        $source = "{loose}\n:: T\n:  a\n\n   b\n";
        $this->assertSame($this->html($source), $this->html($this->fmt($source)));

        $written = $this->converter->parse($this->fmt($source))->getChildren()[0] ?? null;
        $this->assertInstanceOf(DefinitionList::class, $written);
        $this->assertTrue($written->isLoose());

        $bare = $this->converter->parse(":: T\n:  a\n\n   b\n")->getChildren()[0] ?? null;
        $this->assertInstanceOf(DefinitionList::class, $bare);
        $this->assertFalse($bare->isLoose());
    }

    /**
     * A blank line loosens an item only before a genuine PARAGRAPH (§17 L2), so a
     * one-item list whose second child is a sub-block re-reads TIGHT and the key
     * is the only spelling left.
     */
    public function testTheWriterSpellsItWhereTheItemsOwnBlankLineWouldNotLoosen(): void
    {
        $source = "{loose}\n- a\n\n  ```\n  code\n  ```\n";
        $this->assertStringContainsString('{loose}', $this->fmt($source));
        $this->assertSame($this->html($source), $this->html($this->fmt($source)));
    }

    /**
     * THE NEAR MISS a naive reading of the rule also decorates. This one-item list
     * is loose, and its lead CONTAINER carries a blank line of its own, so the
     * written source re-reads loose without any key - the mark would be idle. The
     * looseness is not even observable in the HTML here, since the `<li>` holds no
     * paragraph of its own.
     */
    public function testTheWriterDoesNotDecorateAListWhoseLeadContainerAlreadySpellsIt(): void
    {
        $source = "- ::: d\n  b\n\n  tail\n  :::\n";
        $this->assertSame($source, $this->fmt($source));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function shapes(): array
    {
        return [
            'one-item list' => ["{loose}\n- Note text.\n"],
            'one-block description' => ["{loose}\n:: Term\n:  Definition.\n"],
            'multi-item loose list' => ["- alpha\n\n- beta\n"],
            'redundant on a loose list' => ["{loose}\n- a\n\n- b\n"],
            'mixed descriptions' => ["{loose}\n:: T\n:  a\n:: U\n:  x\n\n   y\n"],
            'id and class beside it' => ["{#i loose .note}\n- x\n"],
            'valued loose' => ["{loose=x}\n- x\n"],
            'no such axis' => ["{loose}\n> q\n"],
            'nested sub-list' => ["- outer\n  {loose}\n  - inner\n"],
            'lead container' => ["- ::: d\n  b\n\n  tail\n  :::\n"],
        ];
    }

    #[DataProvider('shapes')]
    public function testEveryShapeRoundTripsThroughTheWriter(string $source): void
    {
        $formatted = $this->fmt($source);
        $this->assertSame($this->html($source), $this->html($formatted), 'fmt must not change the document');
        $this->assertSame($formatted, $this->fmt($formatted), 'fmt must be idempotent');
    }
}
