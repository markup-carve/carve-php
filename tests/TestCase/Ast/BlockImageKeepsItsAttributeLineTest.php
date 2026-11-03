<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\ProseMirror\ProseMirrorRenderer;
use MarkupCarve\Carve\ProseMirror\ProseMirrorToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * markup-carve/carve#5039, ruling A: promotion moves no attributes onto the wire image.
 */
final class BlockImageKeepsItsAttributeLineTest extends TestCase
{
    public function testReferenceImagePublishesTheAttributeLineOnItsParagraph(): void
    {
        $source = "{.c}\n![a][ex]\n\n[ex]: /i.png {.wide}\n";
        $wire = (new AstCodec())->encode((new CarveConverter())->parse($source));
        $paragraph = $wire['children'][0];

        self::assertTrue($paragraph['blockImage']);
        self::assertSame(['c'], $paragraph['attrs']['classes']);
        self::assertSame(['wide'], $paragraph['children'][0]['attrs']['classes']);
    }

    public function testImageWithoutOwnAttributesPublishesNone(): void
    {
        $wire = (new AstCodec())->encode((new CarveConverter())->parse("{#x k=v}\n![a](/i.png)\n"));
        $paragraph = $wire['children'][0];

        self::assertSame('x', $paragraph['attrs']['id']);
        self::assertSame(['k' => 'v'], $paragraph['attrs']['keyValues']);
        self::assertArrayNotHasKey('attrs', $paragraph['children'][0]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function sources(): array
    {
        return [
            'full reference' => ["{.c}\n![a][ex]\n\n[ex]: /i.png {.wide}\n"],
            'collapsed reference with use-site block' => ["{.c #x}\n![ex][]{.u}\n\n[ex]: /i.png {k=v #y}\n"],
            'shortcut reference' => ["{k=w}\n![ex]\n\n[ex]: /i.png {.wide}\n"],
            'inline image, conflicting id' => ["{#x .c}\n![a](/i.png){#y .u}\n"],
            'list item' => ["- {.c}\n  ![a][ex]\n\n[ex]: /i.png {.wide}\n"],
            'block quote' => ["> {.c}\n> ![a][ex]\n\n[ex]: /i.png {.wide}\n"],
            'with a comment' => ["{.c}\n![a][ex]\n%% note\n\n[ex]: /i.png {.wide}\n"],
        ];
    }

    #[DataProvider('sources')]
    public function testHtmlSurvivesTheAstRoundTrip(string $source): void
    {
        $converter = new CarveConverter();
        $codec = new AstCodec();
        $json = $codec->encodeJson($converter->parse($source));
        $expected = trim($converter->convert($source));

        self::assertSame($expected, trim($converter->render($codec->decodeJson($json))));
        $written = CarveConverter::carve()->render($codec->decodeJson($json));
        self::assertSame(CarveConverter::carve()->render($converter->parse($source)), $written);
    }

    public function testFmtKeepsTheAttributeLine(): void
    {
        $source = "{.outer k=one}\n![a](b){.inner k=two}\n";

        self::assertSame($source, CarveConverter::carve()->render((new CarveConverter())->parse($source)));
    }

    #[DataProvider('sources')]
    public function testTheProseMirrorBridgeKeepsTheSplit(string $source): void
    {
        $converter = new CarveConverter();
        $proseMirror = (new ProseMirrorRenderer())->render($converter->parse($source));
        $back = (new ProseMirrorToCarve())->convert($proseMirror);

        self::assertSame(CarveConverter::carve()->render($converter->parse($source)), CarveConverter::carve()->render($back));
    }
}
