<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use MarkupCarve\Carve\Node\Block\Div;
use MarkupCarve\Carve\Profile;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use PHPUnit\Framework\TestCase;

final class DirectiveInterchangeTest extends TestCase
{
    public function testGeneratedContentOpenersPublishDirectiveNodes(): void
    {
        $codec = new AstCodec();
        foreach (Div::GENERATED_CONTENT_KINDS as $kind) {
            $document = CarveConverter::carve()->parse("::: {$kind}\n:::\n");
            $wire = $codec->encode($document);

            self::assertSame('directive', $wire['children'][0]['type'], $kind);
            self::assertSame($kind, $wire['children'][0]['kind'], $kind);
            self::assertSame([], $wire['children'][0]['children'], $kind);
            self::assertSame($wire, $codec->encode($codec->decode($wire)), $kind);
            self::assertSame("::: {$kind}\n\n:::\n", CarveConverter::carve()->render($document), $kind);
        }
    }

    public function testAnEmptyDirectiveFromHtmlImportPublishesChildren(): void
    {
        $wire = (new HtmlToCarve())->convertToAst('<div class="toc"></div>');

        self::assertSame([], $wire['children'][0]['children']);
    }

    public function testAuthoredClassDoesNotChangeTheOpenerKind(): void
    {
        $document = CarveConverter::carve()->parse("{.warning}\n::: footnotes\n:::\n");
        $wire = (new AstCodec())->encode($document)['children'][0];

        self::assertSame('directive', $wire['type']);
        self::assertSame('footnotes', $wire['kind']);
        self::assertSame(['warning'], $wire['attrs']['classes']);
        self::assertSame('directive', Profile::canonicalTypeOf($document->getChildren()[0]));
    }

    public function testAnUnknownKindAndAnAttributeOnlyContainerKeepTheirOwnTypes(): void
    {
        $codec = new AstCodec();
        $unknown = $codec->encode(CarveConverter::carve()->parse("::: sidebar\n:::\n"));
        $attributeOnly = $codec->encode(CarveConverter::carve()->parse("{.toc}\n:::\n"));

        self::assertSame('admonition', $unknown['children'][0]['type']);
        self::assertSame('div', $attributeOnly['children'][0]['type']);
    }

    public function testAnIngestedDirectiveKeepsItsChildrenAndLabel(): void
    {
        $wire = [
            'type' => 'document',
            'srcByteLength' => 0,
            'children' => [
                [
                    'type' => 'directive',
                    'kind' => 'toc',
                    'label' => 'contents',
                    'children' => [['type' => 'paragraph', 'children' => [['type' => 'text', 'value' => 'Intro']]]],
                ],
            ],
        ];
        $codec = new AstCodec();

        self::assertEquals($wire, $codec->encode($codec->decode($wire)));
    }

    /**
     * Every kind, and the writer. `AGeneratedContentKindIsADirectiveTest` pins
     * the quoted opener on `toc` through the encoder and the HTML renderer;
     * the other five kinds and the canonical writer had nothing on them, and
     * the writer reads the RAW header the decoder recomputes from `title`
     * rather than the title nodes a renderer reads.
     */
    public function testATitledDirectiveSurvivesTheWireOnEveryKind(): void
    {
        $codec = new AstCodec();
        foreach (Div::GENERATED_CONTENT_KINDS as $kind) {
            $source = "::: {$kind} \"Notes\" [End]\n\n:::\n";
            $wire = $codec->encode(CarveConverter::carve()->parse($source));
            $directive = $wire['children'][0];

            self::assertSame('Notes', $directive['title'][0]['value'] ?? null, $kind);
            self::assertSame('End', $directive['label'] ?? null, $kind);
            self::assertSame($wire, $codec->encode($codec->decode($wire)), $kind);
            self::assertSame($source, CarveConverter::carve()->render($codec->decode($wire)), $kind);
        }
    }

    /**
     * The ingest half on its own: a payload the parser never saw. A `title`
     * that publishes but does not decode loses the opener here and nowhere
     * else, and the writer then destroys the author's text.
     */
    public function testAnIngestedTitleReachesTheWriterAndTheRenderer(): void
    {
        $wire = [
            'type' => 'document',
            'srcByteLength' => 0,
            'children' => [
                [
                    'type' => 'directive',
                    'kind' => 'footnotes',
                    'title' => [['type' => 'text', 'value' => 'Notes']],
                    'label' => 'End',
                    'children' => [],
                ],
            ],
        ];
        $codec = new AstCodec();

        self::assertEquals($wire, $codec->encode($codec->decode($wire)));
        self::assertSame(
            "::: footnotes \"Notes\" [End]\n\n:::\n",
            CarveConverter::carve()->render($codec->decode($wire)),
        );
        self::assertStringContainsString(
            '<p class="admonition-title">Notes</p>',
            (new HtmlRenderer())->render($codec->decode($wire)),
        );
    }
}
