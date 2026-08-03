<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use JsonException;
use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class AstCodecTest extends TestCase
{
    protected AstCodec $codec;

    protected CarveConverter $converter;

    protected function setUp(): void
    {
        $this->codec = new AstCodec();
        $this->converter = new CarveConverter();
    }

    public function testTheRootCarriesNoEnvelope(): void
    {
        // PART 12 §3 forbids a field the reference shape lacks, and carve-js's
        // root is exactly type + children + srcByteLength. A version envelope
        // here made the conformance checker report the root as non-conformant
        // on all 12 sampled documents.
        $encoded = $this->codec->encode($this->converter->parse('text'));

        $this->assertSame(['type', 'srcByteLength', 'children'], array_keys($encoded));
    }

    public function testAVersion1PayloadIsRejectedWithTheReasonNamed(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('`content` became `value`');

        $this->codec->decode(['ast' => 1, 'type' => 'document', 'children' => []]);
    }

    public function testNodeStateIsEncodedAlongsideChildren(): void
    {
        $encoded = $this->codec->encode($this->converter->parse('## Title'));

        $heading = $encoded['children'][0];
        $this->assertSame('heading', $heading['type']);
        $this->assertSame(2, $heading['level']);
        $this->assertSame('text', $heading['children'][0]['type']);
        // PART 12 §3: the reference calls a text node's payload `value`.
        $this->assertSame('Title', $heading['children'][0]['value']);
    }

    public function testAttributesAreEncodedUnderAttrsAndOmittedWhenEmpty(): void
    {
        $withAttrs = $this->codec->encode($this->converter->parse("{#slug .lead}\ntext"));
        $without = $this->codec->encode($this->converter->parse('text'));

        $this->assertSame('slug', $withAttrs['children'][0]['attrs']['id']);
        $this->assertArrayNotHasKey('attrs', $without['children'][0]);
        $this->assertArrayNotHasKey('children', $without['children'][0]['children'][0] ?? ['children' => null]);
    }

    public function testNodeValuedStateRoundTrips(): void
    {
        // A div's quoted opener is held as nodes, not a string - the encoding
        // must not need a second representation for that.
        $source = "::: note \"a *b*\"\nBody\n:::";

        $document = $this->converter->parse($source);
        $decoded = $this->codec->decode($this->codec->encode($document));

        $this->assertSame($this->converter->render($document), $this->converter->render($decoded));
    }

    public function testAForeignTreeIsRejectedRatherThanDecodedWrongly(): void
    {
        // The failure this replaces: carve-js writes `value`, this codec used to
        // read `content`, unrecognized keys were ignored and a missing content
        // field defaulted to '' - so every text node came back EMPTY and the
        // process exited 0.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Decoding lost 1 field');

        $this->codec->decode([

            'type' => 'document',
            'children' => [
                ['type' => 'paragraph', 'children' => [['type' => 'text', 'content' => 'Text']]],
            ],
        ]);
    }

    public function testAConformantTreeCarryingPositionsStillDecodes(): void
    {
        // This engine cannot emit `pos` yet (PART 12 §4), but it must not refuse
        // a tree from an engine that can, or conformance would be a trap.
        $document = $this->codec->decode([

            'type' => 'document',
            'children' => [
                [

                    'type' => 'paragraph',
                    'pos' => [
                        'startLine' => 1,
                    ],
                    'children' => [
                        ['type' => 'text', 'value' => 'Text', 'pos' => ['startLine' => 1]],
                    ],
                ],
            ],
        ]);

        $this->assertSame("<p>Text</p>\n", $this->converter->render($document));
    }

    public function testFieldNamesAreTheReferenceShape(): void
    {
        // PART 12 §3: field names are spec surface, taken from carve-js.
        $encoded = $this->codec->encode($this->converter->parse("[x](u)\n\n- one\n"));

        $link = $encoded['children'][0]['children'][0];
        $this->assertSame('href', array_key_first(array_diff_key($link, ['type' => 1, 'children' => 1])));

        $list = $encoded['children'][1];
        $this->assertArrayHasKey('items', $list, 'the reference calls a list\'s children `items`');
        $this->assertArrayHasKey('ordered', $list);
        $this->assertArrayNotHasKey('listType', $list, 'an internal must not be exported');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function authoredFormProvider(): array
    {
        return [
            // profiles.md: an autolink is its OWN type, "folding it into `link`
            // loses the authored form, so a round-trip could not restore it".
            'autolink' => ["<https://example.com>\n"],
            'mail autolink' => ["<a@b.com>\n"],
            // Same for an admonition versus a div carrying a class.
            'admonition' => ["::: warning\nBody.\n:::\n"],
            'titled admonition' => ["::: warning \"Pro Tip\"\nBody.\n:::\n"],
            'labelled admonition' => ["::: tip \"T\" [Build]\nBody.\n:::\n"],
            // Four internal list types collapse onto one `ordered` boolean.
            'task list' => ["- [x] done\n- [ ] todo\n"],
            'ordered list' => ["1. one\n"],
            'loose list' => ["- one\n\n- two\n"],
            // A title written in the fence, versus one on an attribute line.
            'code fence title' => ["``` php \"src/Auth.php\"\n\$ok = true;\n```\n"],
            'code fence label' => ["``` php [NPM]\nnpm install x\n```\n"],
            'abbreviation before' => ["*[HTML]: HyperText Markup Language\n\nHTML rocks.\n"],
            'abbreviation after' => ["HTML rocks.\n\n*[HTML]: HyperText Markup Language\n"],
            'table span marker' => ["|<| b |\n|---|---|\n| c | d |\n"],
        ];
    }

    /**
     * PART 12 section 6 is about the AUTHORED form, not the rendered one.
     *
     * Every case here rendered identical HTML while being corrupted, which is
     * how they survived a corpus gate that only compared HTML.
     */
    #[DataProvider('authoredFormProvider')]
    public function testTheAuthoredFormSurvivesARoundTrip(string $source): void
    {
        $renderer = new CarveRenderer();
        $document = $this->converter->parse($source);
        $decoded = $this->codec->decode($this->codec->encode($this->converter->parse($source)));

        $this->assertSame($renderer->render($document), $renderer->render($decoded));
    }

    public function testAnAutolinkIsItsOwnTypeOnTheWire(): void
    {
        $encoded = $this->codec->encode($this->converter->parse('<https://example.com>'));
        $link = $encoded['children'][0]['children'][0];

        $this->assertSame('autolink', $link['type']);
        $this->assertSame('https://example.com', $link['text']);
        $this->assertArrayNotHasKey('children', $link, 'the reference gives an autolink no children');
        $this->assertArrayNotHasKey('isAutolink', $link, 'the type name carries this, not a flag');
    }

    public function testAnAdmonitionIsItsOwnTypeOnTheWire(): void
    {
        $encoded = $this->codec->encode($this->converter->parse("::: warning\nBody.\n:::"));
        $div = $encoded['children'][0];

        $this->assertSame('admonition', $div['type']);
        $this->assertSame('warning', $div['kind']);
        $this->assertArrayNotHasKey('typed', $div);
    }

    public function testAPayloadSpellingOutADefaultIsNotALoss(): void
    {
        // The encoder omits a field holding the node's own default, so a payload
        // that writes one out explicitly is not losing anything - an empty
        // document is the common case, and rejecting it would break the most
        // trivial valid tree there is.
        $document = $this->codec->decode([
            'type' => 'document',
            'children' => [],
            'srcByteLength' => 0,
        ]);

        $this->assertSame('', $this->converter->render($document));
    }

    public function testAnExplicitNonDefaultValueIsStillCarried(): void
    {
        // The mirror case: `tight` defaults to true, so an explicit false has to
        // survive rather than be written off as a spelled-out default.
        $document = $this->codec->decode([
            'type' => 'document',
            'children' => [
                [
                    'type' => 'list',
                    'ordered' => false,
                    'tight' => false,
                    'items' => [
                        [

                            'type' => 'list_item',
                            'children' => [
                                ['type' => 'paragraph', 'children' => [['type' => 'text', 'value' => 'a']]],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertSame("- a\n", (new CarveRenderer())->render($document));
    }

    public function testDecodeRejectsAnUnknownNodeType(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unknown node type: not_a_node');

        $this->codec->decode(['type' => 'document', 'children' => [['type' => 'not_a_node']]]);
    }

    public function testDecodeRejectsAFutureEncodingVersion(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('AST encoding version');

        $this->codec->decode(['ast' => AstCodec::VERSION + 1, 'type' => 'document']);
    }

    public function testDecodeRejectsANonDocumentRoot(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('root must be a document');

        $this->codec->decode(['type' => 'paragraph']);
    }

    public function testJsonHelpersAreSymmetric(): void
    {
        $document = $this->converter->parse("# A\n\n- one\n- two");

        $json = $this->codec->encodeJson($document);
        $decoded = $this->codec->decodeJson($json);

        $this->assertJson($json);
        $this->assertSame($this->converter->render($document), $this->converter->render($decoded));
    }

    /**
     * The real gate: every corpus document must survive encode plus decode with
     * byte-identical HTML **and** byte-identical Carve source.
     *
     * HTML alone is not enough, and that is not theoretical - it passed while
     * three constructs were being corrupted. An autolink decoded as a plain
     * link renders the same HTML but writes back as `[url](url)`. A task list
     * decoded as a bullet list renders the same checkboxes (the item marker
     * drives them) but writes back without `[x]`. A titled admonition rendered
     * the same `<aside>` while losing its title entirely. Carve output is the
     * stricter surface because it has to reproduce what the AUTHOR wrote, which
     * is exactly what PART 12 §6 is about.
     *
     * The four exceptions are the ones §5 states outright, listed by name so
     * that a NEW loss fails here and so does one of these being FIXED - a
     * subset check would let the first through as soon as the list had an
     * entry.
     */
    public function testEveryCorpusDocumentSurvivesARoundTrip(): void
    {
        $directory = dirname(__DIR__, 3) . '/tests/spec/tests/corpus';
        $inputs = glob($directory . '/*.crv') ?: [];
        $this->assertGreaterThan(400, count($inputs), 'the corpus was not found');

        $renderer = new CarveRenderer();
        $htmlFailures = [];
        $carveFailures = [];
        foreach ($inputs as $input) {
            $source = (string)file_get_contents($input);

            $converter = new CarveConverter();
            $document = $converter->parse($source);
            $expected = $converter->render($document);
            $expectedCarve = $renderer->render($document);

            $roundTripped = new CarveConverter();
            $decoded = $this->codec->decode($this->codec->encode($roundTripped->parse($source)));

            if ($roundTripped->render($decoded) !== $expected) {
                $htmlFailures[] = basename($input);
            }
            if ($renderer->render($decoded) !== $expectedCarve) {
                $carveFailures[] = basename($input);
            }
        }

        $this->assertSame([], $htmlFailures, sprintf('%d corpus documents lost HTML', count($htmlFailures)));
        sort($carveFailures);
        $this->assertSame(
            self::AUTHORED_FORM_NOT_ON_THE_WIRE,
            $carveFailures,
            sprintf('%d corpus documents lost authored form: %s', count($carveFailures), implode(', ', array_slice($carveFailures, 0, 8))),
        );
    }

    /**
     * The documents a JSON round trip does not return to their authored form,
     * and the only ones.
     *
     * TWO causes, kept apart on purpose.
     *
     * Most hold an unresolved reference - `[a][]` - which this engine keeps as
     * a `raw_text` node so its writer reproduces the brackets the parser
     * declined to interpret. PART 12 §5 excludes that node from the wire, so it
     * publishes as `text` and comes back as one, and a text node's brackets are
     * metacharacters that the writer escapes: `\[a\]\[\]`.
     *
     * The `174-bare-dot-ordered-markers` pair is NOT that. A bare-dot ordered
     * list has no field on the wire at all: `list` carries `delim` and
     * `bulletChar` for every other marker flavor, but nothing records the bare
     * form, so `. first` decodes as `1. first`. That is a gap in the FORMAT
     * rather than in this codec - preserving it here would mean inventing a
     * field the other engines do not publish - and is tracked as
     * markup-carve/carve#480.
     *
     * The HTML is unaffected, and so is `fmt`, which reads the live tree rather
     * than a decoded payload. What is lost is authored form AFTER a trip
     * through the JSON, which is the cost §5 accepted. Reconstructing the node
     * on decode - "a text node that looks like reference source" - would trade
     * this stated loss for a silent one.
     *
     * @var array<string>
     */
    private const AUTHORED_FORM_NOT_ON_THE_WIRE = [
        '136-link-reference-definition-separator-must-be-a-space.crv',
        '159-indented-reference-and-footnote-definitions-stay-literal.crv',
        '173-implicit-heading-references-with-no-definition.crv',
        // No wire field for the bare-dot marker (markup-carve/carve#480).
        '174-bare-dot-ordered-markers-3.crv',
        '174-bare-dot-ordered-markers.crv',
        '18-unresolved-reference-link.crv',
        '78-reference-labels-are-case-sensitive.crv',
    ];

    /**
     * `raw_text` is this engine's node and no other engine has it, so an
     * unresolved reference publishes as TEXT rather than as a type the schema
     * rejects and the profile vocabulary does not name.
     *
     * It is now ONE text node, not three: PART 12 §1a coalesces adjacent runs,
     * and the declined source is text like the words around it.
     *
     * The comment this replaces said carve-js and carve-rs both publish three
     * text nodes here. carve-rs still does; carve-js publishes `text`, `link`,
     * `text` - it keeps the reference as a link node with `ref`, which is what
     * §3's "and `ref` when the author wrote a reference link" describes. So the
     * engines disagree about what an unresolved reference IS, which coalescing
     * surfaces rather than causes, and which is carve#486 to settle. This test
     * pins what this engine does today; it is not evidence the shape is right.
     */
    public function testAnUnresolvedReferencePublishesAsText(): void
    {
        $encoded = $this->codec->encode($this->converter->parse('see [a][] here'));
        $inlines = $encoded['children'][0]['children'];

        $this->assertSame(['text'], array_column($inlines, 'type'));
        // The content is the source the parser declined, not an escaped form of
        // it: escaping belongs to the writer, not to the wire.
        $this->assertSame('see [a][] here', $inlines[0]['value']);
        $this->assertArrayNotHasKey('content', $inlines[0], 'a text node publishes `value`');
    }

    /**
     * The mapping is on the way OUT only (PART 12 §1).
     *
     * The live tree keeps `raw_text`, so formatting a parsed document still
     * reproduces the brackets. This is the half that would break silently if
     * the node were dropped at parse time instead of at encode time, and it is
     * why `fmt` is not affected by any of this.
     */
    public function testFormattingAParsedDocumentStillReproducesDeclinedMarkup(): void
    {
        $source = "see [a][] here\n";

        $this->assertSame($source, (new CarveRenderer())->render($this->converter->parse($source)));
    }

    /**
     * Reading one back still works, because this engine wrote them.
     *
     * §5 governs what is PUBLISHED. A payload that names the node was produced
     * by an earlier version of this codec, and a stored document cannot be
     * recalled - rejecting it, or silently turning it into something else,
     * would make the fix a data-loss event for anyone who serialized before it.
     */
    public function testAStoredPayloadNamingTheInternalNodeStillDecodes(): void
    {
        $stored = [
            'type' => 'document',
            'children' => [
                [
                    'type' => 'paragraph',
                    'children' => [['type' => 'raw_text', 'content' => '[a][]']],
                ],
            ],
        ];

        $decoded = $this->codec->decode($stored);

        $paragraph = $decoded->getChildren()[0];
        $this->assertSame('raw_text', $paragraph->getChildren()[0]->getType());
        // And it writes back as the source it holds, which is the whole reason
        // the node exists.
        $this->assertSame("[a][]\n", (new CarveRenderer())->render($decoded));
    }

    public function testAnUnresolvedReferenceKeepsItsHtmlThroughARoundTrip(): void
    {
        $document = $this->converter->parse('see [a][] here');
        $decoded = $this->codec->decode($this->codec->encode($this->converter->parse('see [a][] here')));

        // HTML is the surface a consumer of the wire actually renders, and it is
        // unchanged - the brackets are literal text either way.
        $this->assertSame(
            $this->converter->render($document),
            (new CarveConverter())->render($decoded),
        );
    }

    public function testSmartPunctuationPublishesTheAuthorsSourceRun(): void
    {
        // `value` is what the AUTHOR wrote, `glyph` what the parser resolved -
        // and a `glyph` only exists where the resolution is locale-dependent
        // (quotes). Publishing the glyph AS `value` gave an ellipsis, which has
        // no glyph, a null value, and leaked the source run beside it under this
        // engine's own field name.
        $encoded = $this->codec->encode($this->converter->parse('a ... b'));
        $node = $encoded['children'][0]['children'][1];

        $this->assertSame('smart_punctuation', $node['type']);
        $this->assertSame('ellipsis', $node['kind']);
        $this->assertSame('...', $node['value']);
        $this->assertArrayNotHasKey('content', $node);
    }

    public function testSmartQuotesStillPublishTheResolvedGlyph(): void
    {
        // The mirror: a kind whose glyph IS locale-dependent keeps both halves,
        // so a test that only looked at the ellipsis could not tell "glyph is
        // gone" from "glyph is absent here".
        $encoded = $this->codec->encode($this->converter->parse('say "hi"'));
        $quotes = [];
        array_walk_recursive($encoded, static function ($value, $key) use (&$quotes): void {
            if ($key === 'glyph') {
                $quotes[] = $value;
            }
        });

        $this->assertNotSame([], $quotes, 'a smart quote must publish its resolved glyph');
    }

    public function testACommentSaysWhichFormTheAuthorWrote(): void
    {
        $encoded = $this->codec->encode($this->converter->parse("%%% b\n%%%\n\nx %% inline %% y\n"));
        $block = $encoded['children'][0];
        $inline = $encoded['children'][1]['children'][1];

        $this->assertSame('comment', $block['type']);
        $this->assertTrue($block['block']);
        $this->assertArrayNotHasKey('fenceLength', $block, 'fence width is a writer concern');
        $this->assertSame('comment', $inline['type']);
        $this->assertFalse($inline['block']);
    }

    public function testADecodedBlockCommentStaysABlockComment(): void
    {
        // The wire carries WHICH FORM, not the fence width, so decoding has to
        // restore blockness - and the writer has to widen the fence past any run
        // of `%` in the content, which is what a nested comment fence needs.
        $source = "%%%%\n%%% nested\n%%%%\n";
        $decoded = $this->codec->decode($this->codec->encode($this->converter->parse($source)));
        $rendered = (new CarveRenderer())->render($decoded);

        $this->assertSame(
            $this->converter->parse($source)->getChildren()[0]->getContent(),
            $this->converter->parse($rendered)->getChildren()[0]->getContent(),
        );
    }

    public function testTheRootCarriesNoAbbreviationFields(): void
    {
        // PART 12 §7 fixes the root at three fields. This engine kept the
        // `abbr => expansion` map and a placement flag there, so a consumer
        // walking the root found two fields the shape does not describe - and
        // the definitions, which ARE authored content, were nowhere in the tree.
        $encoded = $this->codec->encode($this->converter->parse("The HTML spec.\n\n*[HTML]: HyperText Markup Language\n"));

        $this->assertSame(['type', 'srcByteLength', 'children'], array_keys($encoded));
        $this->assertSame(
            ['paragraph', 'abbreviation_def'],
            array_column($encoded['children'], 'type'),
        );
        $this->assertSame('HTML', $encoded['children'][1]['abbr']);
        $this->assertSame('HyperText Markup Language', $encoded['children'][1]['expansion']);
    }

    public function testDefinitionsPublishedBeforeTheBodyWhenThatIsWhereTheyWere(): void
    {
        // The flag does not need a field: it says WHERE the definitions were,
        // and the nodes are now somewhere. Placement carries it.
        $encoded = $this->codec->encode($this->converter->parse("*[HTML]: HyperText\n\nThe HTML spec.\n"));

        $this->assertSame(
            ['abbreviation_def', 'paragraph'],
            array_column($encoded['children'], 'type'),
        );
    }

    public function testAbbreviationPlacementSurvivesARoundTrip(): void
    {
        // Which is the point of deriving the flag rather than publishing it: a
        // decoded document has to write the definition line back where the
        // author put it.
        $renderer = new CarveRenderer();
        foreach (["*[HTML]: HyperText\n\nThe HTML spec.\n", "The HTML spec.\n\n*[HTML]: HyperText\n"] as $source) {
            $document = $this->converter->parse($source);
            $decoded = $this->codec->decode($this->codec->encode($document));

            $this->assertSame(
                $renderer->render($document),
                $renderer->render($decoded),
                'the definitions must come back where they were written',
            );
        }
    }

    public function testAnOldRootAbbreviationsMapStillDecodes(): void
    {
        $decoded = $this->codec->decode([
            'type' => 'document',
            'srcByteLength' => 0,
            'children' => [
                ['type' => 'paragraph', 'children' => [['type' => 'text', 'value' => 'x']]],
            ],
            'abbreviations' => ['HTML' => 'HyperText'],
            'abbreviationsBeforeBody' => true,
        ]);

        $this->assertSame(['HTML' => 'HyperText'], $decoded->getAbbreviations());
        $this->assertTrue($decoded->hasAbbreviationsBeforeBody());
    }

    public function testAnInlineExtensionUsesTheReferenceFieldNames(): void
    {
        // `extensionType` and ordinary `children` are this engine's spelling;
        // the reference publishes `name` and `content`, and PART 12 §3 forbids
        // a synonym.
        $encoded = $this->codec->encode($this->converter->parse('a :ext[x] b'));
        $extension = $encoded['children'][0]['children'][1];

        $this->assertSame('inline_extension', $extension['type']);
        $this->assertSame('ext', $extension['name']);
        $this->assertSame('x', $extension['content'][0]['value']);
        $this->assertArrayNotHasKey('extensionType', $extension);
        $this->assertArrayNotHasKey('children', $extension);
    }

    /**
     * Everything the parser can produce has to survive encode then decode. The
     * reader used to inherit json_decode()'s default depth of 512, a number
     * nobody chose for this format; equating an ingest bound with the parser's
     * own cap is how carve-rs came to reject its own encoder's output
     * (carve-rs#389), so the shapes that cost the most structural levels per
     * AST level are pinned here.
     *
     * @param string $shape
     */
    #[DataProvider('parserCapShapeProvider')]
    public function testDecodeJsonAcceptsEverythingTheParserCanProduce(string $shape): void
    {
        $json = $this->codec->encodeJson($this->converter->parse($shape));
        $decoded = $this->codec->decodeJson($json);

        $this->assertSame($json, $this->codec->encodeJson($decoded));
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function parserCapShapeProvider(): array
    {
        $cap = 200;

        $divLadder = '';
        for ($i = 0; $i < $cap; $i++) {
            $divLadder .= str_repeat(':', $cap + 2 - $i) . "\n";
        }
        $divLadder .= "x\n";
        for ($i = $cap - 1; $i >= 0; $i--) {
            $divLadder .= str_repeat(':', $cap + 2 - $i) . "\n";
        }

        $list = '';
        for ($i = 0; $i < $cap; $i++) {
            $list .= str_repeat(' ', $i * 2) . "- item\n";
        }

        return [
            'div ladder' => [$divLadder],
            'blockquotes' => [str_repeat('> ', $cap) . "x\n"],
            'nested list' => [$list],
            'table under blockquotes' => [str_repeat('> ', $cap) . "\n| =a | =b |\n| 1 | 2 |\n"],
        ];
    }

    public function testDecodeJsonPassesAnOrdinaryJsonErrorThrough(): void
    {
        // Only the depth failure is reinterpreted; a syntax error is still the
        // JSON parser's to report, and rewrapping it would hide what is wrong.
        $this->expectException(JsonException::class);
        $this->codec->decodeJson('{"type":"document",');
    }

    public function testDecodeJsonRefusesAPayloadNoParseCouldHaveProduced(): void
    {
        $depth = AstCodec::MAX_JSON_DEPTH + 50;
        $json = '{"type":"document","srcByteLength":0,"children":'
            . str_repeat('[', $depth) . str_repeat(']', $depth) . '}';

        $this->expectException(RuntimeException::class);
        // Matched on the reason rather than the number: the bound is derived
        // from the parser's cap now, so pinning 1200 here would fail the day
        // that cap moves - for a reason that has nothing to do with this test.
        $this->expectExceptionMessageMatches('/nests deeper than \d+ levels/');
        $this->codec->decodeJson($json);
    }
}
