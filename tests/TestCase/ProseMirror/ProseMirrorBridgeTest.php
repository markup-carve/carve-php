<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\ProseMirror;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\Div;
use MarkupCarve\Carve\Node\Inline\Span;
use MarkupCarve\Carve\Node\Node;
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
        $back = $this->converter->convert($proseMirror);
        $actual = (new CarveConverter())->render($back);

        return [
            'expected' => $expected,
            'actual' => $actual,
            'pm' => $proseMirror,
            // Comparing HTML alone is a check that cannot fail for a whole
            // class of defect: `::: note` and `{.note}` plus a bare fence are
            // the same document, so a re-spelling passes no matter which side
            // comes out. 55 losses hid behind exactly that (carve-php#519).
            // Canonical Carve is strictly stronger - it subsumes the HTML
            // comparison except for constructs that render AND spell alike.
            'expectedCarve' => CarveConverter::carve()->render((new CarveConverter())->parse($source)),
            'actualCarve' => CarveConverter::carve()->render($back),
        ];
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
        $this->assertSame($result['expectedCarve'], $result['actualCarve'], 'the authored spelling changed');
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

    public function testAProseMirrorTableCellParagraphRendersToCarveSource(): void
    {
        $pm = [

            'type' => 'doc',
            'content' => [
                [

                    'type' => 'table',
                    'content' => [
                        [

                            'type' => 'tableRow',
                            'content' => [
                                [

                                    'type' => 'tableHeader',
                                    'attrs' => [
                                        'colspan' => 1,
                                        'rowspan' => 1,
                                        'colwidth' => null,
                                    ],
                                    'content' => [
                                        [

                                            'type' => 'paragraph',
                                            'content' => [
                                                ['type' => 'text', 'text' => 'Kopf A'],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        [

                            'type' => 'tableRow',
                            'content' => [
                                [

                                    'type' => 'tableCell',
                                    'attrs' => [
                                        'colspan' => 1,
                                        'rowspan' => 1,
                                        'colwidth' => null,
                                    ],
                                    'content' => [
                                        [

                                            'type' => 'paragraph',
                                            'content' => [
                                                ['type' => 'text', 'text' => 'A1'],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $document = $this->converter->convert($pm);
        $source = CarveConverter::carve()->render($document);
        $html = (new CarveConverter())->render($document);
        $roundTripped = (new CarveConverter())->parse($source);

        $this->assertSame("|=Kopf A|\n| A1 |\n", $source);
        $this->assertSame($html, (new CarveConverter())->render($roundTripped));
    }

    public function testAProseMirrorTableCellParagraphKeepsMarksWhenLifted(): void
    {
        $pm = [

            'type' => 'doc',
            'content' => [
                [

                    'type' => 'table',
                    'content' => [
                        [

                            'type' => 'tableRow',
                            'content' => [
                                [

                                    'type' => 'tableCell',
                                    'attrs' => [
                                        'colspan' => 1,
                                        'rowspan' => 1,
                                        'colwidth' => null,
                                    ],
                                    'content' => [
                                        [

                                            'type' => 'paragraph',
                                            'content' => [
                                                [

                                                    'type' => 'text',
                                                    'text' => 'marked',
                                                    'marks' => [
                                                        ['type' => 'bold'],
                                                        ['type' => 'link', 'attrs' => ['href' => 'https://example.com', 'title' => 'Title']],
                                                    ],
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $source = CarveConverter::carve()->render($this->converter->convert($pm));

        $this->assertSame("| *[marked](https://example.com \"Title\")* |\n", $source);
    }

    public function testAProseMirrorTableCellWithTwoParagraphsIsSpaceJoined(): void
    {
        $pm = [

            'type' => 'doc',
            'content' => [
                [

                    'type' => 'table',
                    'content' => [
                        [

                            'type' => 'tableRow',
                            'content' => [
                                [

                                    'type' => 'tableCell',
                                    'attrs' => [
                                        'colspan' => 1,
                                        'rowspan' => 1,
                                        'colwidth' => null,
                                    ],
                                    'content' => [
                                        [

                                            'type' => 'paragraph',
                                            'content' => [
                                                ['type' => 'text', 'text' => 'first'],
                                            ],
                                        ],
                                        [

                                            'type' => 'paragraph',
                                            'content' => [
                                                ['type' => 'text', 'text' => 'second'],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $source = CarveConverter::carve()->render($this->converter->convert($pm));

        $this->assertSame("| first second |\n", $source);
    }

    public function testAProseMirrorTableCellWithBareInlinesIsUnchanged(): void
    {
        $pm = [

            'type' => 'doc',
            'content' => [
                [

                    'type' => 'table',
                    'content' => [
                        [

                            'type' => 'tableRow',
                            'content' => [
                                [

                                    'type' => 'tableCell',
                                    'attrs' => [
                                        'colspan' => 1,
                                        'rowspan' => 1,
                                        'colwidth' => null,
                                    ],
                                    'content' => [
                                        ['type' => 'text', 'text' => 'bare '],
                                        ['type' => 'text', 'text' => 'cell', 'marks' => [['type' => 'bold']]],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $source = CarveConverter::carve()->render($this->converter->convert($pm));

        $this->assertSame("| bare *cell* |\n", $source);
    }

    /**
     * A shift-enter in a cell is ordinary Tiptap. Lifted as a hard break it
     * would be written as a backslash line break, which ends the Carve table
     * row: the source reparses as a paragraph and the table is gone.
     */
    public function testAProseMirrorTableCellHardBreakDegradesToASpace(): void
    {
        $pm = $this->tableWithOneCell([
            [

                'type' => 'paragraph',
                'content' => [
                    ['type' => 'text', 'text' => 'first'],
                    ['type' => 'hardBreak'],
                    ['type' => 'text', 'text' => 'second'],
                ],
            ],
        ]);

        $source = CarveConverter::carve()->render($this->converter->convert($pm));

        $this->assertSame("| first second |\n", $source);
        $this->assertStringContainsString('<table>', (new CarveConverter())->convert($source));
    }

    public function testAProseMirrorTableCellHardBreakInsideAMarkDegradesToASpace(): void
    {
        $pm = $this->tableWithOneCell([
            [

                'type' => 'paragraph',
                'content' => [
                    ['type' => 'text', 'text' => 'first', 'marks' => [['type' => 'bold']]],
                    ['type' => 'hardBreak', 'marks' => [['type' => 'bold']]],
                    ['type' => 'text', 'text' => 'second', 'marks' => [['type' => 'bold']]],
                ],
            ],
        ]);

        $source = CarveConverter::carve()->render($this->converter->convert($pm));

        $this->assertSame("| *first second* |\n", $source);
        $this->assertStringContainsString('<table>', (new CarveConverter())->convert($source));
    }

    public function testAProseMirrorTableCellListKeepsItsWordBoundaries(): void
    {
        $pm = $this->tableWithOneCell([
            [

                'type' => 'bulletList',
                'content' => [
                    [

                        'type' => 'listItem',
                        'content' => [
                            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'one']]],
                        ],
                    ],
                    [

                        'type' => 'listItem',
                        'content' => [
                            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'two']]],
                        ],
                    ],
                ],
            ],
        ]);

        $source = CarveConverter::carve()->render($this->converter->convert($pm));

        $this->assertSame("| one two |\n", $source);
    }

    /**
     * @param array<int, array<string, mixed>> $content
     *
     * @return array<string, mixed>
     */
    protected function tableWithOneCell(array $content): array
    {
        return [

            'type' => 'doc',
            'content' => [
                [

                    'type' => 'table',
                    'content' => [
                        [

                            'type' => 'tableRow',
                            'content' => [
                                [
                                    'type' => 'tableCell',
                                    'attrs' => ['colspan' => 1, 'rowspan' => 1, 'colwidth' => null],
                                    'content' => $content,
                                ],
                            ],
                        ],
                    ],
                ],
            ],
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

    /**
     * A child with no usable type reaches the block builder rather than being
     * mistaken for an inline: the inline check answers "not inline" for a
     * payload it cannot classify, so the diagnostic comes from the one place
     * that owns it instead of the node being silently wrapped in a paragraph.
     */
    public function testABlockChildWithoutAStringTypeIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('needs a string type');

        $this->converter->convert(['type' => 'doc', 'content' => [['attrs' => ['x' => 1]]]]);
    }

    /**
     * The cell path decides block-versus-inline by building the node, so it
     * rejects an untyped payload rather than reading `$data['type']` as an
     * empty name and lifting nothing.
     */
    public function testATableCellChildWithoutAStringTypeIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('needs a string type');

        $this->converter->convert([

            'type' => 'doc',
            'content' => [
                [

                    'type' => 'table',
                    'content' => [
                        [

                            'type' => 'tableRow',
                            'content' => [
                                ['type' => 'tableCell', 'content' => [['attrs' => ['x' => 1]]]],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }

    /**
     * A Carve attribute holds a string, so a non-scalar value has no form here
     * and used to fall off the end of the passthrough loop without a word.
     * Tiptap's resizable table stores `colwidth` as an array, which is the case
     * with a real producer behind it (carve-php#541).
     */
    public function testANonScalarAttributeIsReportedRatherThanDiscarded(): void
    {
        $this->converter->convert($this->cellWithAttributes([
            'colspan' => 1,
            'rowspan' => 1,
            'colwidth' => [220],
        ]));

        $this->assertArrayHasKey('colwidth', $this->converter->droppedAttributes());
        $this->assertNotSame('', $this->converter->droppedAttributes()['colwidth']);
    }

    /**
     * `null` is how the editor spells "unset", so it carries nothing to lose
     * and reporting it would be noise. A scalar reaches the tree as before.
     *
     * @param mixed $value
     */
    #[DataProvider('carriedAttributeProvider')]
    public function testACarriedAttributeIsNotReported(mixed $value): void
    {
        $this->converter->convert($this->cellWithAttributes([
            'colspan' => 1,
            'rowspan' => 1,
            'colwidth' => $value,
        ]));

        $this->assertSame([], $this->converter->droppedAttributes());
    }

    public static function carriedAttributeProvider(): array
    {
        return [
            'unset' => [null],
            'int' => [220],
            'string' => ['220'],
            'bool' => [true],
        ];
    }

    /**
     * The report describes the LAST conversion, so a clean document after a
     * lossy one does not inherit its findings.
     */
    public function testTheReportIsResetPerConversion(): void
    {
        $this->converter->convert($this->cellWithAttributes([
            'colspan' => 1,
            'rowspan' => 1,
            'colwidth' => [220],
        ]));
        $this->assertNotSame([], $this->converter->droppedAttributes());

        $this->converter->convert([

            'type' => 'doc',
            'content' => [
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'clean']]],
            ],
        ]);
        $this->assertSame([], $this->converter->droppedAttributes());
    }

    /**
     * @param array<string, mixed> $attrs
     *
     * @return array<string, mixed>
     */
    private function cellWithAttributes(array $attrs): array
    {
        return [

            'type' => 'doc',
            'content' => [
                [

                    'type' => 'table',
                    'content' => [
                        [

                            'type' => 'tableRow',
                            'content' => [
                                [
                                    'type' => 'tableCell',
                                    'attrs' => $attrs,
                                    'content' => [
                                        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'A']]],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * An application's own editor node cannot go upstream - nobody else has
     * `placeholderToken` - so "in the published map" and "throw" left it no
     * route at all. Registration is the third state (carve-php#542).
     */
    public function testARegisteredInlineNodeConverts(): void
    {
        $this->converter->register('placeholderToken', static function (array $data): Node {
            $span = new Span();
            $span->addClass('placeholder');

            return $span;
        });

        $document = $this->converter->convert([

            'type' => 'doc',
            'content' => [
                [

                    'type' => 'paragraph',
                    'content' => [
                        ['type' => 'text', 'text' => 'Hello '],
                        [
                            'type' => 'placeholderToken',
                            'attrs' => ['data-key' => 'customer.name'],
                            'content' => [['type' => 'text', 'text' => '{{name}}']],
                        ],
                    ],
                ],
            ],
        ]);

        // The factory returns the shell; attributes and children come from the
        // normal path, which is what makes the hook worth having.
        $this->assertSame(
            "Hello [{{name}}]{.placeholder data-key=customer.name}\n",
            CarveConverter::carve()->render($document),
        );
        $this->assertStringContainsString(
            '<span class="placeholder" data-key="customer.name">{{name}}</span>',
            (new CarveConverter())->render($document),
        );
    }

    public function testARegisteredBlockNodeConvertsWithItsChildren(): void
    {
        $this->converter->register('dataBlock', static function (array $data): Node {
            $div = new Div();
            $div->addClass('data-block');

            return $div;
        });

        $document = $this->converter->convert([

            'type' => 'doc',
            'content' => [
                [
                    'type' => 'dataBlock',
                    'attrs' => ['data-id' => '7'],
                    'content' => [
                        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Body']]],
                    ],
                ],
            ],
        ]);

        $this->assertSame(
            "{data-id=7}\n::: data-block\nBody\n:::\n",
            CarveConverter::carve()->render($document),
        );
    }

    /**
     * A payload from a plain Tiptap editor uses `mention`, the name
     * tiptap/extension-mention emits, with the shape that extension emits: an
     * atom carrying `id` and `label`, no css class and no text child. Accepting
     * only the name would resolve the node and then lose the visible name - the
     * mention dropped out of the source altogether.
     *
     * The `id` survives all the way to Carve source, and the source renders as
     * the node did: a Mention has no attribute slot of its own, so the writer
     * spells out the form a destination-less mention RENDERS as - a strong
     * inside a classed span, sigil escaped so the label stays text rather than
     * re-parsing as a second mention (carve-php#567). Before it, the bridge
     * wrote a bare `@Alice` and the id was gone with nothing reported.
     */
    #[DataProvider('stockMentionProvider')]
    public function testAStockTiptapMentionConvertsWithoutRegistration(array $attrs, string $expected): void
    {
        $document = $this->converter->convert([
            'type' => 'doc',
            'content' => [
                [
                    'type' => 'paragraph',
                    'content' => [
                        ['type' => 'text', 'text' => 'ping '],
                        ['type' => 'mention', 'attrs' => $attrs],
                    ],
                ],
            ],
        ]);

        $this->assertSame($expected, CarveConverter::carve()->render($document));
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function stockMentionProvider(): array
    {
        return [
            'a label is the visible name' => [['id' => 'alice', 'label' => 'Alice'], "ping [*\\@Alice*]{.mention #alice}\n"],
            // Tiptap renders the id when nothing labelled it, so the id is the
            // name rather than a second attribute beside an empty mention.
            'an unlabelled mention falls back to the id' => [['id' => 'alice'], "ping @alice\n"],
        ];
    }

    /**
     * A payload that spells the text out keeps what it spelled: the label is
     * only a substitute for a missing child, never a second copy of one.
     */
    public function testALabelDoesNotDuplicateAnExplicitTextChild(): void
    {
        $document = $this->converter->convert([
            'type' => 'doc',
            'content' => [
                [
                    'type' => 'paragraph',
                    'content' => [
                        [
                            'type' => 'mention',
                            'attrs' => ['label' => 'Alice'],
                            'content' => [['type' => 'text', 'text' => '@alice']],
                        ],
                    ],
                ],
            ],
        ]);

        // `label` stays an attribute here rather than becoming text, which is
        // the point: one visible name, not two. It reaches the source as an
        // ordinary key/value on the written span (carve-php#567) instead of
        // being dropped.
        $this->assertSame("[*\\@alice*]{.mention label=Alice}\n", CarveConverter::carve()->render($document));
    }

    /**
     * The alias is inbound only. Carve still serializes to `carveMention`, the
     * name CarveKit registers - accepting a second spelling on the way in must
     * not make the engine emit one the editor's own schema does not define.
     */
    public function testAMentionStillSerializesUnderTheCarveKitName(): void
    {
        $document = (new CarveConverter())->parse('ping [@alice]{.user}');

        $this->assertSame(
            'carveMention',
            $this->renderer->render($document)['content'][0]['content'][1]['type'],
        );
    }

    /**
     * A rowspan and a colspan meeting in one row: the editor sends `p` with
     * colspan 2 and `b` with rowspan 2, and rebuilding the placeholder row has
     * to interleave them as `| p | ^ | < | e |`. Draining pending rowspans only
     * before a cell put the `<` in the column the `^` owned, so no `^` was
     * emitted at all and the rowspan was silently flattened.
     */
    public function testARowspanAndAColspanMeetingInOneRowBothSurvive(): void
    {
        $result = $this->roundTrip("| p | q | r | s |\n|---|---|---|---|\n| a | b | c | d |\n| p | ^ | < | e |\n");

        $this->assertStringContainsString('<td rowspan="2">b</td>', $result['expected']);
        $this->assertSame($result['expected'], $result['actual']);
    }

    /**
     * The hook is a door, not a hole: a name nobody registered still throws,
     * so a typo cannot become a silent skip.
     */
    public function testAnUnregisteredNameStillThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not in the schema map');

        $this->converter->convert(['type' => 'doc', 'content' => [['type' => 'someAppNode']]]);
    }

    /**
     * Registration is per converter instance, so one application's vocabulary
     * cannot leak into another's - the reason this is not static state.
     */
    public function testRegistrationDoesNotLeakBetweenConverters(): void
    {
        $this->converter->register('dataBlock', static fn (array $data): Node => new Div());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not in the schema map');

        (new ProseMirrorToCarve())->convert(['type' => 'doc', 'content' => [['type' => 'dataBlock']]]);
    }

    public function testANonDocRootIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must be a ProseMirror doc');

        $this->converter->convert(['type' => 'paragraph']);
    }

    /**
     * Lifting a cell's content has to decide block from inline per child, so it
     * reads each child's name. A typeless child is rejected there for the same
     * reason it is everywhere else - guessing is worse than refusing.
     */
    public function testATypelessTableCellChildIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('needs a string type');

        $this->converter->convert($this->tableWithOneCell([['content' => []]]));
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

    /**
     * A type can map cleanly and still lose something: the node survives, one
     * of its fields does not. Those losses appeared in neither report, so a
     * caller storing documents had no way to find out.
     *
     * @param string $source
     * @param string $expected
     */
    #[DataProvider('unrepresentableStateProvider')]
    public function testStateTheEditorCannotHoldIsReported(string $source, string $expected): void
    {
        $this->renderer->render((new CarveConverter())->parse($source));

        $this->assertArrayHasKey($expected, $this->renderer->degradedTypes());
        $this->assertNotSame('', $this->renderer->degradedTypes()[$expected]);
    }

    public static function unrepresentableStateProvider(): array
    {
        return [
            // A mark needs text to attach to; an empty label has none, so the
            // link is not represented at all.
            'empty link label' => ["[](https://example.com)\n", 'link'],
            // Nested emphasis and strong are one unordered mark set in the
            // editor model, so which delimiter was outermost is not recoverable.
            'nested emphasis order' => ["/*x*/\n", 'emphasis'],
            // The same shape one node over: a span with no content has no text
            // to carry the mark, so it and its attributes go unrepresented.
            // Corpus `307-an-empty-inline-note-is-literal-3` round-tripped
            // `x ^[]{.c}` back as `x ^` with neither report saying so.
            'empty span' => ["x []{.c}\n", 'span'],
            'empty span after a caret' => ["x ^[]{.c}\n", 'span'],
        ];
    }

    /**
     * The other half of the same contract. Each of these WAS declared degraded
     * and is now carried, so asserting only that the report is empty would pass
     * for a bridge that quietly stopped reporting - the round trip has to be
     * identical as well, which is the thing the report was standing in for
     * (carve-php#519).
     *
     * @param string $source
     */
    #[DataProvider('carriedStateProvider')]
    public function testStateOnceReportedLostIsNowCarried(string $source): void
    {
        $pm = $this->renderer->render((new CarveConverter())->parse($source));

        $this->assertSame([], $this->renderer->degradedTypes());
        $this->assertSame([], $this->renderer->droppedTypes());
        $this->assertSame(
            CarveConverter::carve()->render((new CarveConverter())->parse($source)),
            CarveConverter::carve()->render($this->converter->convert($pm)),
        );
    }

    /**
     * The autolink flag is a hint, not truth.
     *
     * An editor can change the visible text of an autolink while its href stays
     * put. The writer spells an autolink from its TEXT, so restoring the flag
     * unconditionally would publish the new text as the destination and lose
     * the real one - `<https://example.com>` retyped comes back `<changed>`,
     * which is not even a link. Same shape as carve-php#516, different door.
     *
     * @param string $source
     * @param string $expected
     */
    #[DataProvider('editedAutolinkProvider')]
    public function testAnEditedAutolinkKeepsItsDestination(string $source, string $expected): void
    {
        $pm = $this->renderer->render((new CarveConverter())->parse($source));
        $pm['content'][0]['content'][0]['text'] = 'changed';

        $this->assertSame($expected, CarveConverter::carve()->render($this->converter->convert($pm)));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function editedAutolinkProvider(): array
    {
        return [
            'url' => ["<https://example.com>\n", "[changed](https://example.com)\n"],
            // The parser adds `mailto:`, so the destination is not the text and
            // the check has to allow for it - both intact and once edited.
            'email' => ["<mark@example.com>\n", "[changed](mailto:mark@example.com)\n"],
            // A blocked scheme must not be laundered into something else here
            // either; it stays exactly what the author wrote.
            'blocked scheme' => ["<vbscript:msgbox>\n", "[changed](vbscript:msgbox)\n"],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function carriedStateProvider(): array
    {
        return [
            // An autolink is written differently from a link, so a formatter
            // has to know which one the author used.
            'autolink' => ["Visit <https://example.com>.\n"],
            // A blocked scheme still has to come back as it was written - the
            // bridge must not launder it into an explicit link.
            'autolink with a blocked scheme' => ["<vbscript:msgbox>\n"],
            'inline code attributes' => ["`code`{.cls}\n"],
            // The other side of the empty-span declaration: a span WITH content
            // has text for the mark, so it must be carried and reported clean.
            // Without this, declaring every span degraded passes - and every
            // span carrying one would drop out of the covered population with
            // nothing to say so.
            'span with content' => ["x [y]{.c}\n"],
            'alphabetic list' => ["a. apple\nb. pear\n"],
            'roman list' => ["iv. four\nv. five\n"],
            'parenthesis delimiter' => ["1) one\n"],
            'asterisk bullet' => ["* a\n"],
            'bare dot ordered list' => [". first\n. second\n"],
            // A collapsed reference that reaches a heading. `href` alone is
            // what it renders by, not what it was written as, so carrying only
            // that baked the generated id into the source (carve-php#1006).
            // Corpus 275 rows 1 and 2 are these two spellings; a label holding
            // markup is the whole difficulty, since the check that keeps the
            // reference has to read the label the way the resolver does.
            'collapsed heading reference' => ["# plain heading\n\n[plain heading][]\n"],
            'collapsed heading reference with a bold label' => ["# *bold* heading\n\n[*bold* heading][]\n"],
            'collapsed heading reference with a code label' => ["# `code()` heading\n\n[`code()` heading][]\n"],
        ];
    }

    /**
     * The reference SPELLING reaches the editor model.
     *
     * The round-trip cases above pass whenever the writer produces the right
     * source, which it could in principle do from state restored by luck. This
     * reads the payload itself, so it fails if the attributes stop being
     * emitted even while something downstream compensates.
     */
    public function testACollapsedHeadingReferenceCarriesItsSpellingOnTheMark(): void
    {
        $pm = $this->renderer->render((new CarveConverter())->parse("# *bold* heading\n\n[*bold* heading][]\n"));
        $mark = $pm['content'][1]['content'][0]['marks'][0];

        $this->assertSame('link', $mark['type']);
        $this->assertSame('#bold-heading', $mark['attrs']['href']);
        $this->assertTrue($mark['attrs']['carveHeadingRef']);
        $this->assertSame('bold heading', $mark['attrs']['carveRef']);
        $this->assertSame('[*bold* heading][]', $mark['attrs']['carveRawRef']);
    }

    /**
     * CONTROL. An inline link is not a reference and must carry none of it, or
     * the writer would respell `[text](/u)` as a reference to a label that does
     * not exist.
     *
     * @param string $source
     */
    #[DataProvider('nonReferenceLinkProvider')]
    public function testALinkThatIsNotAHeadingReferenceCarriesNoSpelling(string $source): void
    {
        $pm = $this->renderer->render((new CarveConverter())->parse($source));
        $attrs = $pm['content'][0]['content'][0]['marks'][0]['attrs'];

        $this->assertArrayNotHasKey('carveHeadingRef', $attrs);
        $this->assertArrayNotHasKey('carveRef', $attrs);
        $this->assertArrayNotHasKey('carveRawRef', $attrs);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nonReferenceLinkProvider(): array
    {
        return [
            'inline link' => ["[text](https://example.com)\n"],
            'autolink' => ["<https://example.com>\n"],
        ];
    }

    /**
     * The same lesson as testAnEditedAutolinkKeepsItsDestination, one attribute
     * over.
     *
     * A collapsed reference resolves by the heading's RENDERED TEXT, so an
     * editor that retypes the visible text has changed which heading it would
     * find. Restoring the authored `[old text][]` there would both point at a
     * heading the document no longer names and discard the edit without a word.
     * The spelling is a hint to be re-derived; when it no longer holds, the
     * link falls back to its inline form, which always renders correctly.
     */
    public function testAnEditedHeadingReferenceKeepsTheEdit(): void
    {
        $pm = $this->renderer->render((new CarveConverter())->parse("# plain heading\n\n[plain heading][]\n"));
        $pm['content'][1]['content'][0]['text'] = 'changed text';

        $this->assertSame(
            "# plain heading\n\n[changed text](#plain-heading)\n",
            CarveConverter::carve()->render($this->converter->convert($pm)),
        );
    }

    /**
     * The other half of the same edit, and it needs its own check because the
     * text one passes without it.
     *
     * The writer emits the REFERENCE, not the href, so a spelling kept after
     * the destination was repointed does not merely respell the link - it
     * republishes the old destination and the edit is gone. Editors update a
     * mark's attrs while leaving the rest in place, so this arrives with
     * `carveRawRef` intact and only `href` changed.
     *
     * @param string $href
     * @param string $expected
     */
    #[DataProvider('retargetedHeadingReferenceProvider')]
    public function testARetargetedHeadingReferenceKeepsItsNewDestination(string $href, string $expected): void
    {
        $pm = $this->renderer->render((new CarveConverter())->parse("# plain heading\n\n[plain heading][]\n"));
        $pm['content'][1]['content'][0]['marks'][0]['attrs']['href'] = $href;

        $this->assertSame($expected, CarveConverter::carve()->render($this->converter->convert($pm)));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function retargetedHeadingReferenceProvider(): array
    {
        return [
            'off the document' => [
                'https://example.com',
                "# plain heading\n\n[plain heading](https://example.com)\n",
            ],
            // Still a fragment, so a check for "does it look like an anchor"
            // would pass this one and lose the edit anyway.
            'to another fragment' => ['#other', "# plain heading\n\n[plain heading](#other)\n"],
        ];
    }

    /**
     * A `[text][label]` reference is a DIFFERENT case, and the asymmetry is the
     * reason only the heading class is carried.
     *
     * A heading reference resolves against a `heading` node the bridge carries.
     * A label reference resolves against a `link_reference_definition`, which
     * the schema map declares unmapped - so by the time the writer runs the
     * definition is gone, and `[text][label]` without one is the literal text
     * rather than a link. Respelling it would turn a working link into prose,
     * so the inline form is written and the loss is reported instead.
     */
    public function testALabelReferenceIsReportedRatherThanRespelled(): void
    {
        $document = (new CarveConverter())->parse("[text][label]\n\n[label]: https://example.com\n");
        $pm = $this->renderer->render($document);

        $this->assertArrayHasKey('link', $this->renderer->degradedTypes());
        $this->assertStringContainsString('definition it points at is not carried', $this->renderer->degradedTypes()['link']);
        $this->assertSame(
            "[text](https://example.com)\n",
            CarveConverter::carve()->render($this->converter->convert($pm)),
        );
    }

    /**
     * And the report is the ONLY record when the definition never existed as a
     * node to begin with. A document decoded from AST JSON can hold a resolved
     * reference with no definition beside it, so the "the dropped definition
     * already says so" reasoning does not cover this door.
     */
    public function testALabelReferenceWithNoDefinitionNodeIsStillReported(): void
    {
        $document = (new CarveConverter())->parse("[text](https://example.com)\n");
        $paragraph = $document->getChildren()[0];
        /** @var \MarkupCarve\Carve\Node\Inline\Link $link */
        $link = $paragraph->getChildren()[0];
        $link->setReferenceLabel('label');
        $link->setRawReferenceLabel('[text][label]');

        $this->renderer->render($document);

        $this->assertArrayHasKey('link', $this->renderer->degradedTypes());
        $this->assertStringContainsString('definition it points at is not carried', $this->renderer->degradedTypes()['link']);
    }

    /**
     * The report has to stay quiet for what the model DOES hold, or it is
     * noise rather than a signal.
     */
    public function testRepresentableStateIsNotReported(): void
    {
        $this->renderer->render((new CarveConverter())->parse("- a\n\n1. b\n\n[x](u) and `c`\n"));

        $this->assertSame([], $this->renderer->degradedTypes());
        $this->assertSame([], $this->renderer->droppedTypes());
    }

    /**
     * An authored `{href=...}` is a plain attribute, not the link's
     * destination. The HTML target already refuses to promote it; the bridge
     * used to, so the editor model carried a destination the document does not
     * have - and writing that model back out made it the real one.
     */
    public function testAnAuthoredAttributeDoesNotBecomeTheLinkDestination(): void
    {
        $source = '[safe](https://example.com){href=javascript:steal}';
        $document = (new CarveConverter())->parse($source);

        $pm = $this->renderer->render($document);
        $link = $pm['content'][0]['content'][0]['marks'][0];

        $this->assertSame('link', $link['type']);
        $this->assertSame('https://example.com', $link['attrs']['href']);

        $back = $this->converter->convert($pm);
        $this->assertStringContainsString(
            '[safe](https://example.com)',
            CarveConverter::carve()->render($back),
        );
    }

    /**
     * The same precedence, on the other node that carries a URL.
     */
    public function testAnAuthoredAttributeDoesNotBecomeTheImageSource(): void
    {
        $document = (new CarveConverter())->parse('![alt](real.png){src=evil.png}');

        // A lone image is a BLOCK image node, so it is a direct child of the
        // doc rather than wrapped in a paragraph (#633). This test is about
        // attribute precedence, not shape - the bridge already accepted a
        // top-level image on the way in (see
        // testABlockPositionImageIsWrappedForCarveSource).
        $pm = $this->renderer->render($document);
        $image = $pm['content'][0];

        $this->assertSame('image', $image['type']);
        $this->assertSame('real.png', $image['attrs']['src']);
    }

    /**
     * Only colliding keys are held back - everything else still reaches the
     * editor, or an application node's own attributes would vanish.
     */
    public function testANonCollidingAuthoredAttributeStillReachesTheEditor(): void
    {
        $document = (new CarveConverter())->parse('[a](u){data-role=cta}');

        $pm = $this->renderer->render($document);
        $link = $pm['content'][0]['content'][0]['marks'][0];

        $this->assertSame('u', $link['attrs']['href']);
        $this->assertSame('cta', $link['attrs']['data-role']);
    }

    /**
     * A container's quoted title is content, not spelling. It was dropped
     * outright, because `carveDiv` never carried it - and a bare fence cannot
     * express one even if it had.
     */
    public function testATypedDivKeepsItsOpenerAndTitle(): void
    {
        $source = "::: tip \"Pro Tip\"\nbody\n:::\n";

        $pm = $this->renderer->render((new CarveConverter())->parse($source));
        $this->assertSame('tip', $pm['content'][0]['attrs']['class']);
        $this->assertSame('Pro Tip', $pm['content'][0]['attrs']['title']);
        $this->assertTrue($pm['content'][0]['attrs']['carveTyped']);

        $back = $this->converter->convert($pm);
        $this->assertSame($source, CarveConverter::carve()->render($back));
    }

    /**
     * A single authored class on a bare div is just an attribute, not the type
     * word. The payload now carries that fact explicitly instead of forcing the
     * converter to guess from the class count.
     */
    public function testAGenericSingleClassDivKeepsItsAuthoredClass(): void
    {
        $source = "{#s .sidebar}\n:::\nA div with attributes.\n:::\n";

        $result = $this->roundTrip($source);

        $this->assertFalse($result['pm']['content'][0]['attrs']['carveTyped']);
        $this->assertSame(
            ['id' => 's', 'class' => 'sidebar'],
            $result['pm']['content'][0]['attrs']['carveAttrs'],
        );
        $this->assertSame($result['expected'], $result['actual']);
    }

    /**
     * The quoted opener title and an authored `title` attribute are two
     * different facts. Both need to survive even though ProseMirror has one
     * ordinary `attrs.title` slot.
     */
    public function testADivKeepsAnAuthoredTitleAttributeThatCollidesWithTheOpenerTitle(): void
    {
        $source = "{title=\"attr title\"}\n::: note \"opener title\"\nBody.\n:::\n";

        $result = $this->roundTrip($source);

        $this->assertSame('opener title', $result['pm']['content'][0]['attrs']['title']);
        $this->assertSame('attr title', $result['pm']['content'][0]['attrs']['carveAttrs']['title']);
        $this->assertSame('note', $result['pm']['content'][0]['attrs']['carveAttrs']['class']);
        $this->assertSame($result['expected'], $result['actual']);
    }

    public function testATypedDivWithNoAuthorAttributesIsUnchanged(): void
    {
        $source = "::: sidebar\nBody.\n:::\n";

        $result = $this->roundTrip($source);

        $this->assertTrue($result['pm']['content'][0]['attrs']['carveTyped']);
        $this->assertSame(['class' => 'sidebar'], $result['pm']['content'][0]['attrs']['carveAttrs']);
        $this->assertSame($result['expected'], $result['actual']);
    }

    public function testAGenericDivWithSeveralClassesIsUnchanged(): void
    {
        $source = "{.alpha .beta}\n:::\nBody.\n:::\n";

        $result = $this->roundTrip($source);

        $this->assertFalse($result['pm']['content'][0]['attrs']['carveTyped']);
        $this->assertSame(['class' => 'alpha beta'], $result['pm']['content'][0]['attrs']['carveAttrs']);
        $this->assertSame($result['expected'], $result['actual']);
    }

    /**
     * An empty title is not a missing one: `::: note ""` suppresses the
     * default heading, so the distinction has to survive.
     */
    public function testAnEmptyContainerTitleIsNotTheSameAsNone(): void
    {
        $source = "::: note \"\"\nbody\n:::\n";

        $pm = $this->renderer->render((new CarveConverter())->parse($source));
        $this->assertSame('', $pm['content'][0]['attrs']['title']);

        $back = $this->converter->convert($pm);
        $this->assertSame($source, CarveConverter::carve()->render($back));
    }

    /**
     * Payloads produced before `carveTyped` still fall back to the historical
     * one-class heuristic.
     */
    public function testADivPayloadWithoutCarveTypedFallsBackToTheSingleClassHeuristic(): void
    {
        $pm = [
            'type' => 'doc',
            'content' => [
                [
                    'type' => 'carveDiv',
                    'attrs' => ['class' => 'custom'],
                    'content' => [
                        [
                            'type' => 'paragraph',
                            'content' => [['type' => 'text', 'text' => 'body']],
                        ],
                    ],
                ],
            ],
        ];

        $this->assertSame(
            "::: custom\nbody\n:::\n",
            CarveConverter::carve()->render($this->converter->convert($pm)),
        );
    }

    public function testADivPayloadWithoutCarveAttrsStillUsesOrdinaryAttributes(): void
    {
        $pm = [
            'type' => 'doc',
            'content' => [
                [
                    'type' => 'carveDiv',
                    'attrs' => ['carveTyped' => false, 'id' => 's', 'class' => 'alpha beta'],
                    'content' => [
                        [
                            'type' => 'paragraph',
                            'content' => [['type' => 'text', 'text' => 'body']],
                        ],
                    ],
                ],
            ],
        ];

        $this->assertSame(
            "{#s .alpha .beta}\n:::\nbody\n:::\n",
            CarveConverter::carve()->render($this->converter->convert($pm)),
        );
    }

    /**
     * An abbreviation without its expansion is just a word: the definition
     * lives nowhere else in the editor model, so dropping the title lost it.
     */
    public function testAnAbbreviationKeepsItsExpansion(): void
    {
        $source = "*[HTML]: HyperText Markup Language\n\nThe HTML spec.\n";
        $document = (new CarveConverter())->parse($source);

        $pm = $this->renderer->render($document);
        $marks = $pm['content'][0]['content'][1]['marks'];

        $this->assertSame('carveAbbreviation', $marks[0]['type']);
        $this->assertSame('HyperText Markup Language', $marks[0]['attrs']['title']);

        $back = $this->converter->convert($pm);
        $this->assertStringContainsString(
            'title="HyperText Markup Language"',
            (new CarveConverter())->render($back),
        );
    }

    /**
     * A semantic span without its name is not valid Carve - `:kbd[x]` came
     * back as `:[x]`. The schema's `carveSource` exists for exactly this.
     */
    public function testASemanticSpanKeepsItsName(): void
    {
        $source = "Press :kbd[Ctrl+C] now.\n";

        $pm = $this->renderer->render((new CarveConverter())->parse($source));
        $this->assertSame(':kbd', $pm['content'][0]['content'][1]['attrs']['carveSource']);

        $back = $this->converter->convert($pm);
        $this->assertSame($source, CarveConverter::carve()->render($back));
    }

    public function testABlockPositionImageIsWrappedForCarveSource(): void
    {
        $pm = [

            'type' => 'doc',
            'content' => [
                ['type' => 'image', 'attrs' => ['src' => '/x.png', 'alt' => 'Plan', 'title' => 'T']],
            ],
        ];

        $document = $this->converter->convert($pm);
        $source = CarveConverter::carve()->render($document);
        $html = (new CarveConverter())->render($document);

        $this->assertStringContainsString('![Plan](/x.png "T")', $source);
        $this->assertSame($html, (new CarveConverter())->convert($source));
    }

    public function testABlockPositionImageInsideABlockQuoteIsWrapped(): void
    {
        $pm = [

            'type' => 'doc',
            'content' => [
                [

                    'type' => 'blockquote',
                    'content' => [
                        ['type' => 'image', 'attrs' => ['src' => '/x.png', 'alt' => 'Plan', 'title' => 'T']],
                    ],
                ],
            ],
        ];

        $source = CarveConverter::carve()->render($this->converter->convert($pm));

        $this->assertSame("> ![Plan](/x.png \"T\")\n", $source);
    }

    public function testAdjacentBlockPositionInlinesShareOneParagraph(): void
    {
        $pm = [

            'type' => 'doc',
            'content' => [
                ['type' => 'image', 'attrs' => ['src' => '/x.png', 'alt' => 'Plan']],
                ['type' => 'text', 'text' => ' Caption'],
            ],
        ];

        $source = CarveConverter::carve()->render($this->converter->convert($pm));

        $this->assertSame("![Plan](/x.png) Caption\n", $source);
    }

    public function testAParagraphPositionImageIsUnchanged(): void
    {
        $pm = [

            'type' => 'doc',
            'content' => [
                [

                    'type' => 'paragraph',
                    'content' => [
                        ['type' => 'image', 'attrs' => ['src' => '/x.png', 'alt' => 'Plan', 'title' => 'T']],
                    ],
                ],
            ],
        ];

        $source = CarveConverter::carve()->render($this->converter->convert($pm));

        $this->assertSame("![Plan](/x.png \"T\")\n", $source);
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
