<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\ProseMirror;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\ProseMirror\ProseMirrorRenderer;
use MarkupCarve\Carve\ProseMirror\ProseMirrorToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The two things an editor round trip used to re-spell or swallow.
 *
 * Both were INVISIBLE to an HTML comparison, which is why they lasted: an
 * attribute run regrouped from `{key=c .a #b}` to `{.a #b key=c}` renders
 * byte-identically, and a mark with no content renders as nothing either way.
 * Every assertion here is therefore on the CARVE SOURCE that comes back, or on
 * the payload itself - never on HTML (markup-carve/carve-grammars#240).
 */
class AuthoredRunAndEmptyMarkTest extends TestCase
{
    private function roundTrip(string $source): string
    {
        $payload = (new ProseMirrorRenderer())->render((new CarveConverter())->parse($source));

        return CarveConverter::carve()->render((new ProseMirrorToCarve())->convert($payload));
    }

    /**
     * The comparison is the LITERAL source, not `fmt(parse(source))`.
     *
     * Both sides of that formulation run through the same writer, so a defect
     * in the writer cancels out and the assertion holds on output nobody would
     * accept: breaking the `#id` token to `%id` leaves a fmt-against-fmt
     * comparison green. Every case here is already fmt-stable, so the source
     * itself is the expectation and the writer is inside what is under test.
     */
    private function assertComesBackAsWritten(string $source): void
    {
        $this->assertSame($source, CarveConverter::carve()->render((new CarveConverter())->parse($source)));
        $this->assertSame($source, $this->roundTrip($source));
    }

    /**
     * The order is on the wire VERBATIM, not sorted into the canonical shape.
     *
     * A bridge that regrouped the run and then wrote a tidy `#id .class key`
     * order beside it would round-trip its own output happily and still hand a
     * different document to the editor it shares this schema with. The list is
     * the AST's `attrs.order`, slot for slot.
     */
    public function testTheAuthoredSlotOrderReachesTheWireVerbatim(): void
    {
        $payload = (new ProseMirrorRenderer())->render((new CarveConverter())->parse("[x]{key=c .a #b}\n"));
        $mark = $payload['content'][0]['content'][0]['marks'][0];

        $this->assertSame('carveSpan', $mark['type']);
        $this->assertSame(['key', '.class', '#id'], $mark['attrs']['carveAttrOrder']);
    }

    /**
     * A run that produced none of the three slots gets no order.
     *
     * An abbreviation's `abbr` becomes the mark's `title`, so an order naming
     * `abbr` would point at a slot the reading side never finds - a list of
     * names for nothing.
     */
    public function testASlotlessRunCarriesNoOrder(): void
    {
        $source = "A [HTML]{abbr=\"HyperText Markup Language\"} page.\n";
        $payload = (new ProseMirrorRenderer())->render((new CarveConverter())->parse($source));
        $mark = $payload['content'][0]['content'][1]['marks'][0];

        $this->assertSame('carveAbbreviation', $mark['type']);
        $this->assertArrayNotHasKey('carveAttrOrder', $mark['attrs']);
    }

    #[DataProvider('authoredRunProvider')]
    public function testAnAuthoredRunComesBackAsWritten(string $source): void
    {
        $this->assertComesBackAsWritten($source);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function authoredRunProvider(): array
    {
        return [
            'key before class and id' => ["[x]{key=c .a #b}\n"],
            'class before id' => ["[x]{.a #b}\n"],
            'id last on a paragraph' => ["{key=c .a #b}\nx\n"],
            'id last on a heading' => ["{.a #b}\n## x\n"],
            'id last on a div' => ["{key=c .a #b}\n:::\nx\n:::\n"],
            'key between id and class' => ["[x]{#b key=c .a}\n"],
            'two keys around a class' => ["[x]{a=1 .c b=2}\n"],
            // PART 2 - an attribute run on inline code belongs to the code
            // mark, which the stock Tiptap mark has no attributes for at all.
            'inline code' => ["A `code`{#i .cls k=v} span.\n"],
            'inline code, key first' => ["A `code`{k=v .cls #i} span.\n"],
            'inline code on a row of its own' => ["`code`{.cls}\n"],
        ];
    }

    #[DataProvider('emptyMarkProvider')]
    public function testAMarkWithNoContentComesBackAsWritten(string $source): void
    {
        $this->assertComesBackAsWritten($source);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function emptyMarkProvider(): array
    {
        return [
            'empty link label' => ["[](https://example.com)\n"],
            'empty link label with a title and a run' => ["[](https://example.com \"T\"){.a #i}\n"],
            'empty span' => ["a []{.x} b\n"],
            'empty span with no attributes at all' => ["a []{} b\n"],
            'empty abbreviation span' => ["a []{abbr=\"HyperText Markup Language\"} b\n"],
            'empty insert and delete' => ["a {++} b {--} c\n"],
            'all four in one paragraph' => ["[](https://e.com) []{.x} {++} {--}\n"],
        ];
    }

    /**
     * A paragraph that is ONLY an empty mark used to come back EMPTY.
     *
     * The round-trip assertions above would pass on a bridge that dropped the
     * construct on the way out and re-invented it on the way in, so the atom
     * itself is read off the payload.
     */
    public function testTheCarrierIsInThePayload(): void
    {
        $payload = (new ProseMirrorRenderer())->render((new CarveConverter())->parse("a []{.x} b {++}\n"));
        $inlines = $payload['content'][0]['content'];

        $this->assertSame('carveEmptyMark', $inlines[1]['type']);
        $this->assertSame('carveSpan', $inlines[1]['attrs']['markType']);
        $this->assertSame(['class' => 'x', 'carveAttrOrder' => ['.class']], $inlines[1]['attrs']['markAttrs']);
        $this->assertSame('carveEmptyMark', $inlines[3]['type']);
        $this->assertSame('carveInsert', $inlines[3]['attrs']['markType']);
        $this->assertArrayNotHasKey('markAttrs', $inlines[3]['attrs']);
    }

    /**
     * The atom names a mark, and only one the schema admits.
     *
     * Reading an unknown `markType` as "some span" would change the document
     * silently, which is the failure this whole node exists to end.
     */
    public function testACarrierNamingAMarkTheSchemaDoesNotKnowIsRefused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not one the schema map names');

        (new ProseMirrorToCarve())->convert([
            'type' => 'doc',
            'content' => [
                [
                    'type' => 'paragraph',
                    'content' => [['type' => 'carveEmptyMark', 'attrs' => ['markType' => 'bold']]],
                ],
            ],
        ]);
    }

    /**
     * The order is a REPLAY, not a spelling of what the parser saw.
     *
     * An editor edits: it deletes the id the author wrote and adds an attribute
     * nobody named. A slot with nothing behind it is skipped, and an attribute
     * the order does not name follows the ones it does - otherwise a stored
     * order would either write `{#}` for a deleted id or drop the new key.
     */
    public function testTheOrderIsReplayedAroundWhatTheDocumentStillHas(): void
    {
        $payload = (new ProseMirrorRenderer())->render((new CarveConverter())->parse("[x]{key=c .a #b}\n"));
        $attrs = &$payload['content'][0]['content'][0]['marks'][0]['attrs'];

        unset($attrs['id']);
        $attrs['carveKeyValues']['later'] = 'z';

        $back = (new ProseMirrorToCarve())->convert($payload);

        $this->assertSame("[x]{key=c .a later=z}\n", CarveConverter::carve()->render($back));
    }
}
