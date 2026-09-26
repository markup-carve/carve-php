<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\Div;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Node\Inline\Text;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * An explicitly empty `""` title is a supplied title: grammar
 * `14-semantics-blocks.ebnf` says so outright, and `25-ast-extensions.ebnf`
 * gives `title` the authored inline content. Absent and empty are therefore two
 * states, and `title` is the only field that carries the difference - `header`
 * is internal and is recomputed from it on the way in.
 *
 * Omitting the key collapsed them, so a JSON round trip rendered a container
 * the author had named as one with no title at all. carve-js and carve-rs both
 * publish `"title": []`.
 *
 * Closes markup-carve/carve-php#2459.
 */
class AnExplicitlyEmptyTitleRidesTheWireTest extends TestCase
{
    /**
     * Both wire types the schema gives a `title`: an `admonition` and a
     * `directive`.
     *
     * @return array<string, array<string, string>>
     */
    public static function containersWithAnEmptyTitle(): array
    {
        return [
            'admonition' => ['source' => "::: note \"\"\nBody.\n:::\n", 'type' => 'admonition'],
            'named container that is not a callout' => ['source' => "::: sidebar \"\"\nBody.\n:::\n", 'type' => 'admonition'],
            'directive' => ['source' => "::: footnotes \"\"\n:::\n\nText[^a] more.\n\n[^a]: The note.\n", 'type' => 'directive'],
            'directive placing a table of contents' => ['source' => "::: toc \"\"\n:::\n\n# H\n", 'type' => 'directive'],
        ];
    }

    #[DataProvider('containersWithAnEmptyTitle')]
    public function testTheKeyIsPublishedHoldingAnEmptyArray(string $source, string $type): void
    {
        $node = (new AstCodec())->encode(self::parse($source))['children'][0];

        $this->assertSame($type, $node['type']);
        $this->assertArrayHasKey('title', $node);
        $this->assertSame([], $node['title']);
    }

    /**
     * THE DEFECT. A published key could still not be read back, so this goes the
     * whole way the CLI does: parse, write the JSON bytes, read them again,
     * render.
     */
    #[DataProvider('containersWithAnEmptyTitle')]
    public function testAJsonRoundTripRendersWhatADirectRenderDoes(string $source, string $type): void
    {
        unset($type);

        $this->assertSame(
            (new HtmlRenderer())->render(self::parse($source)),
            (new HtmlRenderer())->render(self::throughJson($source)),
        );
    }

    /**
     * The ticket's own bytes, so the fix is pinned to the reported symptom and
     * not only to the invariant above.
     */
    public function testTheReportedFootnotesContainerKeepsItsTitleParagraph(): void
    {
        $source = "::: footnotes \"\"\n:::\n\nText[^a] more.\n\n[^a]: The note.\n";
        $html = (new HtmlRenderer())->render(self::throughJson($source));

        $this->assertStringContainsString('<section role="doc-endnotes" aria-labelledby="adm-1">', $html);
        $this->assertStringContainsString('<p class="admonition-title" id="adm-1"></p>', $html);
        $this->assertStringNotContainsString('aria-label="Footnotes"', $html);
    }

    /**
     * The same trip through the Carve writer, against the DIRECT write rather
     * than the source: an empty container body is canonicalized with a blank
     * line either way, so comparing with the source would pin that instead of
     * the title.
     */
    #[DataProvider('containersWithAnEmptyTitle')]
    public function testTheAuthoredQuotesSurviveTheTripAsSource(string $source, string $type): void
    {
        unset($type);
        $direct = CarveConverter::carve()->render(self::parse($source));

        $this->assertStringContainsString('""', $direct);
        $this->assertSame($direct, CarveConverter::carve()->render(self::throughJson($source)));
    }

    /**
     * A tree arriving from another engine carries the same key, and this engine
     * read it as no title at all.
     */
    public function testATitleArrivingEmptyFromAnotherEngineIsKept(): void
    {
        $document = (new AstCodec())->decode([
            'type' => 'document',
            'srcByteLength' => 0,
            'children' => [
                [
                    'type' => 'admonition',
                    'kind' => 'note',
                    'title' => [],
                    'children' => [['type' => 'paragraph', 'children' => [['type' => 'text', 'value' => 'Body.']]]],
                ],
            ],
        ]);

        $html = (new HtmlRenderer())->render($document);

        $this->assertStringContainsString('aria-labelledby="adm-1"', $html);
        $this->assertStringContainsString('<p class="admonition-title" id="adm-1"></p>', $html);
        $this->assertSame("::: note \"\"\nBody.\n:::\n", CarveConverter::carve()->render($document));
    }

    /**
     * THE CONTROL, and the reason the fix cannot be "always publish `title`": a
     * container with no title at all stays distinguishable from one whose title
     * is empty, so its key stays absent.
     *
     * @return array<string, array<string>>
     */
    public static function containersWithNoTitle(): array
    {
        return [
            'admonition' => ["::: note\nBody.\n:::\n"],
            'directive' => ["::: toc\n:::\n\n# H\n"],
            'anonymous div' => [":::\nBody.\n:::\n"],
        ];
    }

    #[DataProvider('containersWithNoTitle')]
    public function testAnAbsentTitlePublishesNoKey(string $source): void
    {
        $node = (new AstCodec())->encode(self::parse($source))['children'][0];

        $this->assertArrayNotHasKey('title', $node);
        $this->assertSame(
            (new HtmlRenderer())->render(self::parse($source)),
            (new HtmlRenderer())->render(self::throughJson($source)),
        );
        $this->assertSame(
            CarveConverter::carve()->render(self::parse($source)),
            CarveConverter::carve()->render(self::throughJson($source)),
        );
    }

    /**
     * The third control, and the one that pins the type guard: the schema gives
     * `div` no `title` and forbids anything else, so publishing the key there
     * would be invalid. No `:::` opener reaches this state, but the public API
     * does, and without the guard this node carries `"title": []`.
     */
    public function testAPlainDivPublishesNoTitleTheSchemaForbids(): void
    {
        $document = new Document();
        $div = new Div();
        $div->setHeader('');
        $paragraph = new Paragraph();
        $paragraph->appendChild(new Text('Body.'));
        $div->appendChild($paragraph);
        $document->appendChild($div);

        $node = (new AstCodec())->encode($document)['children'][0];

        $this->assertSame('div', $node['type']);
        $this->assertArrayNotHasKey('title', $node);
    }

    /**
     * The second control: a title that has content is untouched by the change.
     */
    public function testANonEmptyTitleIsUnchanged(): void
    {
        $source = "::: note \"Careful\"\nBody.\n:::\n";
        $node = (new AstCodec())->encode(self::parse($source))['children'][0];

        $this->assertSame([['type' => 'text', 'value' => 'Careful']], $node['title']);
        $this->assertSame($source, CarveConverter::carve()->render(self::throughJson($source)));
    }

    private static function parse(string $source): Document
    {
        return (new CarveConverter())->parse($source);
    }

    /**
     * Parse, serialize, and read the bytes back, exactly as `--json` followed by
     * `--from-json` does.
     */
    private static function throughJson(string $source): Document
    {
        $codec = new AstCodec();
        $wire = json_encode($codec->encode(self::parse($source)), JSON_THROW_ON_ERROR);
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($wire, true, flags: JSON_THROW_ON_ERROR);

        return $codec->decode($decoded);
    }
}
