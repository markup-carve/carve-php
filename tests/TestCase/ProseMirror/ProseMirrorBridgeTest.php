<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\ProseMirror;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\ProseMirror\ProseMirrorRenderer;
use MarkupCarve\Carve\ProseMirror\ProseMirrorToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ProseMirrorBridgeTest extends TestCase
{
    protected ProseMirrorRenderer $renderer;

    protected ProseMirrorToCarve $converter;

    protected function setUp(): void
    {
        $this->renderer = new ProseMirrorRenderer();
        $this->converter = new ProseMirrorToCarve();
    }

    /**
     * Carve to ProseMirror and back, compared as rendered HTML.
     */
    protected function roundTrip(string $source): array
    {
        $document = (new CarveConverter())->parse($source);
        $expected = (new CarveConverter())->render($document);

        $proseMirror = $this->renderer->render($document);
        $actual = (new CarveConverter())->render($this->converter->convert($proseMirror));

        return ['expected' => $expected, 'actual' => $actual, 'pm' => $proseMirror];
    }

    public function testTheRootIsAProseMirrorDoc(): void
    {
        $pm = $this->renderer->render((new CarveConverter())->parse('text'));

        $this->assertSame('doc', $pm['type']);
    }

    public function testMarksAreFlattenedOntoTextNodes(): void
    {
        // Carve nests `Strong > Text`; ProseMirror hangs the mark off the text.
        $pm = $this->renderer->render((new CarveConverter())->parse('a *bold* b'));

        $inlines = $pm['content'][0]['content'];
        $this->assertSame('text', $inlines[1]['type']);
        $this->assertSame('bold', $inlines[1]['text']);
        $this->assertSame([['type' => 'bold']], $inlines[1]['marks']);
    }

    public function testNestedMarksComeBackAsOneElementNotThree(): void
    {
        // ProseMirror splits `*bold with /italic/ inside*` into three bolded
        // pieces; reassembling them literally would emit three <strong> runs.
        $result = $this->roundTrip('*bold with /italic/ inside*');

        $this->assertSame($result['expected'], $result['actual']);
        $this->assertStringContainsString('<strong>bold with <em>italic</em> inside</strong>', $result['actual']);
    }

    #[DataProvider('roundTripProvider')]
    public function testRoundTripsWithoutLoss(string $source): void
    {
        $result = $this->roundTrip($source);

        $this->assertSame([], $this->renderer->droppedTypes(), 'nothing should be dropped here');
        $this->assertSame($result['expected'], $result['actual']);
    }

    public static function roundTripProvider(): array
    {
        return [
            'heading' => ["## Title\n"],
            'marks' => ["Text *b* /i/ _u_ ~s~ `code`.\n"],
            'link with title' => ["[x](https://e.com \"T\")\n"],
            'link with empty title' => ["[x](u \"\")\n"],
            'bullet list' => ["- one\n- two\n"],
            'ordered list' => ["3. a\n4. b\n"],
            'loose list' => ["- one\n\n- two\n"],
            'task list' => ["- [ ] open\n- [x] done\n"],
            'table' => ["|= A |= B |\n| 1 | 2 |\n"],
            'table with caption' => ["|= A |\n| 1 |\n^ Caption\n"],
            'table spans' => ["| a | b |\n| c | < |\n"],
            'attributed container' => ["{#c1 .calc data-unit=kWh}\n::: calc\nValue\n:::\n"],
            'image' => ["![Alt](p.png \"T\")\n"],
            'inline math' => ['Formula $`E=mc^2`.' . "\n"],
            'block quote' => ["> quoted\n"],
            'fenced code' => ["``` php\necho 1;\n```\n"],
            'fenced code without language' => ["```\nplain\n```\n"],
            'definition list' => [":: Term\n:  Definition\n"],
            'thematic break' => ["a\n\n---\n\nb\n"],
        ];
    }

    public function testAnApplicationsOwnBlockSurvivesThroughAttributes(): void
    {
        // The case an app cares about: a container carrying data-* keys is what
        // lets a custom editor node exist without patching this library.
        $source = "{#calc-1 .calculation data-label=\"Wärmebedarf\" data-unit=kWh}\n::: calculation\n42\n:::\n";

        $result = $this->roundTrip($source);
        $attrs = $result['pm']['content'][0]['attrs'];

        $this->assertSame('carveDiv', $result['pm']['content'][0]['type']);
        $this->assertSame('Wärmebedarf', $attrs['data-label']);
        $this->assertSame('kWh', $attrs['data-unit']);
        $this->assertSame($result['expected'], $result['actual']);
    }

    public function testUnrepresentableTypesAreReportedNotSilentlyDropped(): void
    {
        // A comment has no editor node. The content is gone either way; the point
        // is that the caller can find out.
        $this->renderer->render((new CarveConverter())->parse("a\n\n%%%\nhidden\n%%%\n\nb\n"));

        $this->assertArrayHasKey('comment', $this->renderer->droppedTypes());
        $this->assertNotSame('', $this->renderer->droppedTypes()['comment']);
    }

    public function testTextBearingTypesDegradeToTextRatherThanVanish(): void
    {
        // A soft break has no ProseMirror node, but dropping it would run the
        // words together - so it degrades to a space and is reported separately.
        $pm = $this->renderer->render((new CarveConverter())->parse("one\ntwo\n"));

        $this->assertArrayHasKey('soft_break', $this->renderer->degradedTypes());
        $this->assertSame([], $this->renderer->droppedTypes());

        $text = implode('', array_column($pm['content'][0]['content'], 'text'));
        $this->assertSame('one two', $text);
    }

    public function testAnUnknownProseMirrorNameIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not in the schema map');

        $this->converter->convert(['type' => 'doc', 'content' => [['type' => 'someAppNode']]]);
    }

    public function testANonDocRootIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must be a ProseMirror doc');

        $this->converter->convert(['type' => 'paragraph']);
    }

    /**
     * The label is the note's identity: it binds a reference to its definition
     * and tells two references to the same note apart. The bridge left the
     * attribute unset, so every footnote in a document came back as the same
     * anonymous `[^]` - including, in this case, three of them.
     */
    public function testAFootnoteKeepsItsLabel(): void
    {
        $source = "See[^a], again[^a] and[^b].\n\n[^a]: first\n\n[^b]: second\n";
        $document = (new CarveConverter())->parse($source);

        $pm = $this->renderer->render($document);
        $refs = array_values(array_filter(
            $pm['content'][0]['content'],
            static fn (array $inline): bool => $inline['type'] === 'carveFootnote',
        ));

        $this->assertCount(3, $refs);
        $this->assertSame(['a', 'a', 'b'], array_column(array_column($refs, 'attrs'), 'label'));

        $back = $this->converter->convert($pm);
        $this->assertSame($source, CarveConverter::carve()->render($back));
    }

    public function testJsonHelpersAreSymmetric(): void
    {
        $document = (new CarveConverter())->parse("# A\n\n- one\n");

        $json = $this->renderer->renderJson($document);
        $back = $this->converter->convertJson($json);

        $this->assertJson($json);
        $this->assertSame(
            (new CarveConverter())->render($document),
            (new CarveConverter())->render($back),
        );
    }
}
