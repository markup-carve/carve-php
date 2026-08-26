<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\Ast\AstSchema;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Exception\AstDecodeException;
use MarkupCarve\Carve\Extension\CitationsExtension;
use MarkupCarve\Carve\Node\Block\CitationDefinition;
use MarkupCarve\Carve\Renderer\AnsiRenderer;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use MarkupCarve\Carve\Renderer\MarkdownRenderer;
use MarkupCarve\Carve\Renderer\PlainTextRenderer;
use PHPUnit\Framework\TestCase;

/**
 * PART 12 §18 (markup-carve/carve#1276): the `[@key]: {author= year=} entry`
 * bibliography line is a `citation_definition` node.
 *
 * WHY THESE ASSERTIONS ARE ON THE TREE AND NOT ON THE OUTPUT. The definition
 * renders nothing where it sits, and its entry renders in the references list
 * either way, so HTML is byte-identical whether the line is a node or is
 * consumed during the collect pass. This engine consumed it, which discarded
 * `pos` and deleted the line from an AST round trip, and no fixture in a
 * 500-document corpus could see that. A rendered-output assertion is
 * structurally incapable of catching it; only the tree can.
 *
 * The output assertions below are therefore the OTHER half: the references list
 * and every non-HTML target must not move, because §18 changes the tree and
 * nothing else.
 */
class ACitationDefinitionIsANodeTest extends TestCase
{
    /**
     * The measured document. Line 3 carries the metadata block, line 4 does
     * not, and the entry on line 4 holds inline markup.
     *
     * @var string
     */
    private const SOURCE = "Smith [@smith2020] and others [see @jones2019, p. 4; -@doe2021] agree.\n"
        . "\n"
        . "[@smith2020]: {author=\"Smith\" year=\"2020\"} Smith, J. (2020). *A Study*. Pub.\n"
        . "[@jones2019]: Jones, A. (2019). /Another/. Pub.\n";

    protected AstCodec $codec;

    protected function setUp(): void
    {
        $this->codec = new AstCodec();
    }

    protected function converter(bool $positions = false): CarveConverter
    {
        $converter = new CarveConverter();
        $converter->addExtension(new CitationsExtension());
        if ($positions) {
            $converter->getParser()->enablePositionTracking();
        }

        return $converter;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function definitions(string $source, bool $positions = false): array
    {
        $encoded = $this->codec->encode($this->converter($positions)->parse($source));

        return array_values(array_filter(
            $encoded['children'],
            static fn (array $child): bool => $child['type'] === 'citation_definition',
        ));
    }

    public function testTheDefinitionLineIsANodeCarryingItsKey(): void
    {
        $definitions = $this->definitions(self::SOURCE);

        $this->assertCount(2, $definitions, 'both authored definition lines are nodes');
        $this->assertSame('citation_definition', $definitions[0]['type']);
        // The key WITHOUT the `@`, and under `key` rather than §10's `label`:
        // `citation.key` already names the same string at the use site.
        $this->assertSame('smith2020', $definitions[0]['key']);
        $this->assertArrayNotHasKey('label', $definitions[0]);
    }

    public function testTheMetadataBlockLandsInAttrs(): void
    {
        $definitions = $this->definitions(self::SOURCE);

        $this->assertSame(
            ['author' => 'Smith', 'year' => '2020'],
            $definitions[0]['attrs']['keyValues'],
        );
        // `data-cite-refs-def` is this engine's own marker on the citation
        // group. PART 12 §3 forbids publishing an internal field, so it must
        // not ride along into the definition's attributes.
        $this->assertArrayNotHasKey('data-cite-refs-def', $definitions[0]['attrs']['keyValues']);
    }

    public function testADefinitionWithoutAMetadataBlockHasNoAttrs(): void
    {
        $definitions = $this->definitions(self::SOURCE);

        $this->assertSame('jones2019', $definitions[1]['key']);
        $this->assertArrayNotHasKey('attrs', $definitions[1]);
    }

    public function testTheEntryIsInlineContentAndKeepsItsMarkup(): void
    {
        $definitions = $this->definitions(self::SOURCE);

        // Shaped after §10's link reference definition, not after the footnote:
        // a footnote body holds BLOCKS, this holds one line of inline content.
        $this->assertSame(
            ['text', 'strong', 'text'],
            array_column($definitions[0]['children'], 'type'),
        );
        $this->assertSame('Smith, J. (2020). ', $definitions[0]['children'][0]['value']);
        $this->assertSame('A Study', $definitions[0]['children'][1]['children'][0]['value']);
        $this->assertSame(
            ['text', 'emphasis', 'text'],
            array_column($definitions[1]['children'], 'type'),
        );
    }

    public function testTwoDefinitionsAreTwoNodesInSourceOrder(): void
    {
        $encoded = $this->codec->encode($this->converter()->parse(self::SOURCE));

        $this->assertSame(
            ['paragraph', 'citation_definition', 'citation_definition'],
            array_column($encoded['children'], 'type'),
        );
        $this->assertSame(
            ['smith2020', 'jones2019'],
            [$encoded['children'][1]['key'], $encoded['children'][2]['key']],
        );
    }

    /**
     * Losing `pos` is the specific defect §18 names: a consumed line has no
     * position, so nothing can reproduce it and an AST round trip deletes it.
     */
    public function testThePositionSpansTheWholeAuthoredLine(): void
    {
        $definitions = $this->definitions(self::SOURCE, positions: true);

        foreach ([0 => 3, 1 => 4] as $index => $line) {
            $pos = $definitions[$index]['pos'] ?? null;
            $this->assertIsArray($pos, "definition {$index} has no pos");
            $this->assertSame($line, $pos['startLine']);
            $this->assertSame($line, $pos['endLine']);
            $this->assertSame(1, $pos['startColumn'], 'the span opens at the `[` of `[@key]`');
        }

        // The span is checked against the SOURCE rather than against literals:
        // an offset pair that slices the authored line back out is correct by
        // construction, and stays correct when the document is edited.
        $lines = explode("\n", self::SOURCE);
        foreach ([0, 1] as $index) {
            $pos = $definitions[$index]['pos'];
            $this->assertSame(
                $lines[$pos['startLine'] - 1],
                substr(self::SOURCE, $pos['startOffset'], $pos['endOffset'] - $pos['startOffset']),
            );
        }
    }

    public function testTheWireShapeIsExactlyTheFieldsTheClauseNames(): void
    {
        $definitions = $this->definitions(self::SOURCE, positions: true);

        // §18 names `key`, `children`, `attrs` and `pos`, and the schema's
        // `additionalProperties: false` refuses anything else - so an internal
        // field leaking here is a payload this engine's own decoder rejects.
        $this->assertSame(
            ['type', 'key', 'attrs', 'pos', 'children'],
            array_keys($definitions[0]),
        );
        $this->assertSame(
            ['type', 'key', 'pos', 'children'],
            array_keys($definitions[1]),
        );
    }

    /**
     * Tier-2: the node exists only where citations are enabled.
     */
    public function testADefaultProfileParseNeverProducesTheNode(): void
    {
        $encoded = (new AstCodec())->encode((new CarveConverter())->parse(self::SOURCE));

        $this->assertNotContains(
            'citation_definition',
            array_column($encoded['children'], 'type'),
            'with the extension off the line is ordinary paragraph text',
        );
    }

    /**
     * The regression risk in this direction: the definition now feeds the
     * bibliography from a node in the tree rather than from state captured
     * during the collect pass, and the references list must not notice.
     */
    public function testTheRenderedBibliographyIsUnchanged(): void
    {
        $html = $this->converter()->convert(self::SOURCE);

        $this->assertSame(
            '<p>Smith [<a data-cite-key="smith2020" href="#ref-smith2020">1</a>] and others '
                . "[see @jones2019, p. 4; -@doe2021] agree.</p>\n"
                . "<ol class=\"references\">\n"
                . "  <li id=\"ref-smith2020\">Smith, J. (2020). <strong>A Study</strong>. Pub.</li>\n"
                . '</ol>',
            rtrim($html, "\n"),
        );
    }

    /**
     * §18 moves the TREE and no rendered output on any target. The dispatch in
     * every renderer falls back to rendering a node's children, so a node
     * holding the entry's inlines and no arm of its own would print the
     * bibliography entry into the document flow on all five.
     */
    public function testTheDefinitionRendersNothingOnEveryTarget(): void
    {
        $expected = 'Smith  and others  agree.';

        foreach ([MarkdownRenderer::class, PlainTextRenderer::class, AnsiRenderer::class] as $class) {
            $converter = $this->converter();
            $rendered = (new $class())->render($converter->parse(self::SOURCE));
            $this->assertSame($expected, rtrim($rendered, "\n"), $class . ' emitted the definition');
        }

        $converter = $this->converter();
        $this->assertSame(
            'Smith [@smith2020] and others [see @jones2019, p. 4; -@doe2021] agree.',
            rtrim((new CarveRenderer())->render($converter->parse(self::SOURCE)), "\n"),
        );
    }

    /**
     * A definition's entry is not a use site. Its inlines used to leave the
     * tree with the line; now that they stay, the numbering walk has to skip
     * the subtree or a key cited only inside an entry gains a number and a
     * references row it never had.
     */
    public function testACitationInsideAnEntryIsNotAUseSite(): void
    {
        $source = "Text [@a] here.\n\n[@a]: Alpha, see [@b].\n[@b]: Beta.\n";

        $html = $this->converter()->convert($source);

        $this->assertStringContainsString('<li id="ref-a">', $html);
        $this->assertStringNotContainsString('<li id="ref-b">', $html);
    }

    /**
     * A paragraph mixing prose with a definition line keeps ONE paragraph: the
     * prose must not be split around the definition, or one `<p>` becomes two.
     */
    public function testAMixedParagraphKeepsOneParagraphAndGainsTheNode(): void
    {
        $source = "Prose one.\n[@a]: Alpha.\nProse two.\n";

        $encoded = $this->codec->encode($this->converter()->parse($source));

        $this->assertSame(
            ['paragraph', 'citation_definition'],
            array_column($encoded['children'], 'type'),
        );
        $this->assertSame('a', $encoded['children'][1]['key']);
        $this->assertSame(1, substr_count($this->converter()->convert($source), '<p>'));
    }

    /**
     * Every block the collect pass rearranges is still a CHILD of the document.
     *
     * `replaceChildWithMany()` clears the replaced child's parent last, so
     * splicing the paragraph in as its own replacement left it in the
     * document's children with no parent - present to an index walk, detached
     * to anything that walks upward.
     */
    public function testEveryBlockKeepsItsParentPointer(): void
    {
        foreach (["Prose one.\n[@a]: Alpha.\nProse two.\n", self::SOURCE] as $source) {
            $document = $this->converter()->parse($source);
            foreach ($document->getChildren() as $child) {
                $this->assertSame(
                    $document,
                    $child->getParent(),
                    $child->getType() . ' is in the document but not parented to it',
                );
            }
        }
    }

    /**
     * The round trip through this engine's own codec, and where it stands.
     *
     * PART 12 §12(d) validates a payload against the VENDORED schema at decode,
     * and that copy tracks the `tests/spec` submodule byte for byte. This
     * branch's pin predates §18, so the schema does not name the type yet and
     * decode refuses the payload this encoder now produces - by name, with the
     * typed error §9(b) requires, rather than by silently reinterpreting it.
     *
     * Written as a measurement rather than a sentence: when the pin moves it
     * becomes the positive round-trip assertion automatically, so nobody has to
     * remember that this branch was waiting on it.
     */
    public function testTheNodeRidesThisEnginesOwnCodecOnceTheSchemaNamesIt(): void
    {
        // A citation is now a positioned wire node, so an AST intended for
        // round-trip decoding is parsed with the source positions its schema
        // requires.
        $payload = $this->codec->encode($this->converter(true)->parse(self::SOURCE));

        /** @var array<mixed> $enum */
        $enum = AstSchema::schema()['$defs']['blockNode']['properties']['type']['enum'] ?? [];
        if (!in_array('citation_definition', $enum, true)) {
            try {
                $this->codec->decode($payload);
                $this->fail('the pinned schema does not name the type, so §12(d) must refuse the payload');
            } catch (AstDecodeException $exception) {
                $this->assertStringContainsString('citation_definition', $exception->getMessage());
            }

            return;
        }

        $decoded = $this->codec->decode($payload);
        $definition = $decoded->getChildren()[1];

        $this->assertInstanceOf(CitationDefinition::class, $definition);
        $this->assertSame('smith2020', $definition->getKey());
        $this->assertSame('Smith', $definition->getAttribute('author'));
        $this->assertSame('2020', $definition->getAttribute('year'));
        $this->assertSame(
            ['text', 'strong', 'text'],
            array_map(static fn ($child): string => $child->getType(), $definition->getChildren()),
        );
    }
}
