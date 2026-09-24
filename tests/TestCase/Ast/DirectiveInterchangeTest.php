<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\Div;
use MarkupCarve\Carve\Profile;
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
            self::assertSame($wire, $codec->encode($codec->decode($wire)), $kind);
            self::assertSame("::: {$kind}\n\n:::\n", CarveConverter::carve()->render($document), $kind);
        }
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
}
