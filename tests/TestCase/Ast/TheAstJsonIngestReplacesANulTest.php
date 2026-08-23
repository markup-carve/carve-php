<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use JsonException;
use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\BbcodeToCarve;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use MarkupCarve\Carve\Renderer\AnsiRenderer;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use MarkupCarve\Carve\Renderer\MarkdownRenderer;
use MarkupCarve\Carve\Renderer\PlainTextRenderer;
use MarkupCarve\Carve\Renderer\RendererInterface;
use PHPUnit\Framework\TestCase;

/**
 * PART 12 §21: the AST-JSON ingest replaces every U+0000 with U+FFFD in every
 * string value, before it reads that value for anything else.
 *
 * `BlockParser` has always done this to Carve source, which is why PART 9 §29
 * carves the character out of the content class. The AST is a SECOND DOOR into
 * the same renderers and it had none, so an authored NUL and an ingested one
 * stood on different footings, and the three engines then disagreed about the
 * ingested one: every one emitted it on html, markdown and plain, ANSI stripped
 * it, and the canonical writer split three ways - carve-js and carve-rs deleted
 * it, this engine EMITTED it, so `fmt` produced source the parser does not read
 * back the same way.
 *
 * THE DOOR IS NOT THE JSON PARSER. RFC 8259 forbids an unescaped U+0000 inside
 * a string, so a raw byte in JSON text is the `Control character error`
 * `decodeJson()` already raised, and stays one. What reaches the ingest is the
 * escape, or a string a host built in memory - `decode()` takes an ARRAY, so
 * there is no JSON layer there at all, and that is the door the clause exists
 * for. Both are exercised below.
 */
class TheAstJsonIngestReplacesANulTest extends TestCase
{
    /**
     * @var string
     */
    private const NUL = "\0";

    /**
     * @var string
     */
    private const FFFD = "\u{FFFD}";

    /**
     * A different C0 control, and the control for every row here. §29 still
     * makes U+000B ordinary content - the carve-out is U+0000 alone - so
     * nothing about this change may move it.
     *
     * @var string
     */
    private const VT = "\u{000B}";

    /**
     * @return array<string, mixed>
     */
    private function textDocument(string $value): array
    {
        return [
            'type' => 'document',
            'srcByteLength' => 3,
            'children' => [
                ['type' => 'paragraph', 'children' => [['type' => 'text', 'value' => $value]]],
            ],
        ];
    }

    /**
     * Two abbreviation pairs that collide on a NUL-joined key, plus one use.
     *
     * @return array<string, mixed>
     */
    private function abbreviationDocument(string $separator): array
    {
        return [
            'type' => 'document',
            'srcByteLength' => 40,
            'children' => [
                ['type' => 'abbreviation_def', 'abbr' => 'A' . $separator . 'b', 'expansion' => 'c'],
                ['type' => 'abbreviation_def', 'abbr' => 'A', 'expansion' => 'b' . $separator . 'c'],
                [

                    'type' => 'paragraph',
                    'children' => [
                        ['type' => 'abbreviation', 'abbr' => 'A' . $separator . 'b', 'expansion' => 'c'],
                        ['type' => 'text', 'value' => ' only.'],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @param \MarkupCarve\Carve\Renderer\RendererInterface $renderer
     */
    private function render(array $payload, RendererInterface $renderer): string
    {
        return $renderer->render((new AstCodec())->decode($payload));
    }

    /**
     * @return array<string, \MarkupCarve\Carve\Renderer\RendererInterface>
     */
    private function targets(): array
    {
        return [
            'html' => new HtmlRenderer(),
            'markdown' => new MarkdownRenderer(),
            'plain' => new PlainTextRenderer(),
            'ansi' => new AnsiRenderer(),
            'carve' => new CarveRenderer(),
        ];
    }

    public function testKeepsTheDefinitionLineAnIngestedNulUsedToDelete(): void
    {
        // `ConsumedAbbreviationDefinitions` joins a term and an expansion on a
        // NUL, on the premise that the writers strip control characters from
        // both halves - true of the parse path, false of this one. Before the
        // replacement this rendered "A<NUL>b (c) only." and nothing else: the
        // second definition line gone, and the string "b<NUL>c" nowhere in the
        // output. That is the deletion PART 11 §10f's two-pass design exists to
        // prevent, arriving through the door the clause did not cover.
        $rendered = $this->render($this->abbreviationDocument(self::NUL), new PlainTextRenderer());

        $this->assertSame(
            '*[A]: b' . self::FFFD . "c\n\nA" . self::FFFD . "b (c) only.\n",
            $rendered,
        );
        $this->assertStringNotContainsString(self::NUL, $rendered);
    }

    public function testRendersTheCollisionDocumentAsAnySeparatorWould(): void
    {
        // The control that held on both sides: with a separator the writers do
        // not strip, the two pair keys already differed and the definition line
        // survived. The two outputs now differ only in the separator character.
        $this->assertSame(
            $this->render($this->abbreviationDocument(self::NUL), new PlainTextRenderer()),
            str_replace(
                'Z',
                self::FFFD,
                $this->render($this->abbreviationDocument('Z'), new PlainTextRenderer()),
            ),
        );
    }

    public function testReplacesTheCharacterOnEveryTargetThroughTheArrayDoor(): void
    {
        foreach ($this->targets() as $name => $renderer) {
            $rendered = $this->render($this->textDocument('a' . self::NUL . 'b'), $renderer);

            $this->assertStringNotContainsString(self::NUL, $rendered, $name);
            $this->assertStringContainsString('a' . self::FFFD . 'b', $rendered, $name);
        }
    }

    public function testReplacesTheCharacterBehindTheEscapeThroughTheJsonDoor(): void
    {
        // The only spelling JSON text can carry, and it decodes to the same
        // value the array door hands over directly.
        $json = json_encode($this->textDocument('a' . self::NUL . 'b'), JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('\\u0000', $json);

        $document = (new AstCodec())->decodeJson($json);

        $this->assertSame(
            '<p>a' . self::FFFD . "b</p>\n",
            (new HtmlRenderer())->render($document),
        );
    }

    public function testLeavesARawByteInJsonTextASyntaxError(): void
    {
        // §21 does not relax the JSON grammar: the byte never reaches a Carve
        // rule. Stated as a row so a later reading of "replaces on ingest"
        // cannot be taken for "accepts a raw control byte in a JSON document".
        $raw = '{"type":"document","srcByteLength":3,"children":[{"type":"paragraph",'
            . '"children":[{"type":"text","value":"a' . self::NUL . 'b"}]}]}';

        $this->expectException(JsonException::class);
        (new AstCodec())->decodeJson($raw);
    }

    public function testReplacesItInAStringThatIsNotATextValue(): void
    {
        // "every string value it ingests", so an identifier, a class, an
        // attribute value and a code block's content are all in scope.
        $payload = [
            'type' => 'document',
            'srcByteLength' => 3,
            'children' => [
                [
                    'type' => 'paragraph',
                    'attrs' => [
                        'id' => 'i' . self::NUL . 'd',
                        'classes' => ['c' . self::NUL . 'k'],
                        'keyValues' => ['title' => 'x' . self::NUL . 'y'],
                        'order' => ['title'],
                    ],
                    'children' => [['type' => 'text', 'value' => 'q']],
                ],
                ['type' => 'code_block', 'lang' => 'js', 'content' => 'a' . self::NUL . 'b'],
            ],
        ];

        $html = $this->render($payload, new HtmlRenderer());

        $this->assertStringNotContainsString(self::NUL, $html);
        $this->assertStringContainsString('id="i' . self::FFFD . 'd"', $html);
        $this->assertStringContainsString('class="c' . self::FFFD . 'k"', $html);
        $this->assertStringContainsString('title="x' . self::FFFD . 'y"', $html);
        $this->assertStringContainsString('a' . self::FFFD . 'b', $html);
    }

    public function testMakesTheIngestedDocumentAgreeWithTheSameDocumentWrittenAsSource(): void
    {
        // The whole of the rule: the two doors into the renderers take the same
        // doormat, so what an author writes and what a host hands over land in
        // the same place.
        $this->assertSame(
            CarveConverter::create()->convert('a' . self::NUL . "b\n"),
            $this->render($this->textDocument('a' . self::NUL . 'b'), new HtmlRenderer()),
        );
    }

    public function testWritesSourceTheParserReadsBackUnchanged(): void
    {
        // The canonical writer EMITTED the byte here, so `fmt` produced source
        // the parser replaces on read: the formatted document and its re-parse
        // said different things. What it writes now survives the round trip.
        $carve = $this->render($this->textDocument('a' . self::NUL . 'b'), new CarveRenderer());

        $this->assertSame('a' . self::FFFD . "b\n", $carve);
        $this->assertSame(
            'a' . self::FFFD . "b\n",
            CarveConverter::plainText()->convert($carve),
        );
    }

    public function testLeavesTheOtherC0ControlsWhereSection29PutsThem(): void
    {
        // THE CONTROL THAT MUST NOT MOVE. The carve-out is U+0000 alone, so
        // U+000B stays ordinary content on html, plain and the canonical
        // writer, and stays stripped on the terminal target where T4 strips the
        // class.
        $this->assertSame(
            '<p>a' . self::VT . "b</p>\n",
            $this->render($this->textDocument('a' . self::VT . 'b'), new HtmlRenderer()),
        );
        $this->assertSame(
            'a' . self::VT . "b\n",
            $this->render($this->textDocument('a' . self::VT . 'b'), new PlainTextRenderer()),
        );
        $this->assertSame(
            'a' . self::VT . "b\n",
            $this->render($this->textDocument('a' . self::VT . 'b'), new CarveRenderer()),
        );
        $this->assertStringNotContainsString(
            self::VT,
            $this->render($this->textDocument('a' . self::VT . 'b'), new AnsiRenderer()),
        );
    }

    public function testLeavesAnAuthoredReplacementCharacterAndAnOrdinaryDocumentAlone(): void
    {
        // The other two controls: the replacement character a payload already
        // carries is content, and a payload with no NUL comes back the same.
        $this->assertSame(
            'a' . self::FFFD . "b\n",
            $this->render($this->textDocument('a' . self::FFFD . 'b'), new PlainTextRenderer()),
        );
        $this->assertSame(
            "ab\n",
            $this->render($this->textDocument('ab'), new PlainTextRenderer()),
        );
    }

    public function testReplacesItInTheImportersWhichAreTheSameBoundary(): void
    {
        // §21 states the importer case as a SHOULD, since the format being read
        // may have its own rule. BBCode has none, so Carve's applies, and this
        // converter passed the raw byte into its Carve output. Markdown HAS one
        // - CommonMark 2.3 - and it says replace, where this converter deleted
        // the character instead and so disagreed with carve-js as well.
        $this->assertSame('a' . self::FFFD . "b\n", (new BbcodeToCarve())->convert('a' . self::NUL . 'b'));
        $this->assertSame('a' . self::FFFD . 'b', (new MarkdownToCarve())->convert('a' . self::NUL . 'b'));

        // The controls: the other C0 control is not this clause's business in
        // either converter, and an ordinary document is untouched.
        $this->assertSame('a' . self::VT . "b\n", (new BbcodeToCarve())->convert('a' . self::VT . 'b'));
        $this->assertSame("*a*\n", (new BbcodeToCarve())->convert('[b]a[/b]'));
        $this->assertSame('*a*', (new MarkdownToCarve())->convert('**a**'));
    }
}
