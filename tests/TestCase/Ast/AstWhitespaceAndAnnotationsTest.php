<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use InvalidArgumentException;
use MarkupCarve\Carve\Ast\AnnotationRanges;
use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

final class AstWhitespaceAndAnnotationsTest extends TestCase
{
    public function testLiteralUnicodeIsDistinctFromGeneratedSpaces(): void
    {
        $source = "a\u{E000}\\ b\u{00A0}c\n";
        $converter = CarveConverter::create();
        $codec = new AstCodec();
        $ast = $codec->encode($converter->parse($source));
        self::assertSame(['text', 'non_breaking_space', 'text'], array_column($ast['children'][0]['children'], 'type'));
        self::assertSame("a\u{E000}", $ast['children'][0]['children'][0]['value']);
        self::assertSame($ast, $codec->encode($codec->decode($ast)));
        self::assertSame("<p>a\u{E000}&nbsp;b&nbsp;c</p>\n", $converter->convert($source));
        self::assertSame($source, CarveConverter::carve()->render($converter->parse($source)));
    }

    public function testAttributesOnAnIngestedSpaceSurviveSourceOutput(): void
    {
        $doc = (new AstCodec())->decode([

            'type' => 'document',
            'srcByteLength' => 0,
            'children' => [
                ['type' => 'paragraph', 'children' => [['type' => 'non_breaking_space', 'attrs' => ['classes' => ['gap']]]]],
            ],
        ]);
        $source = CarveConverter::carve()->render($doc);
        self::assertSame("<p><span class=\"gap\">&nbsp;</span></p>\n", CarveConverter::create()->convert($source));
    }

    public function testSharedAnnotationProjection(): void
    {
        $fixture = json_decode(file_get_contents(dirname(__DIR__, 2) . '/spec/tests/fixtures/annotation-projection.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach (['valid' => true, 'invalid' => false] as $key => $accepted) {
            foreach ($fixture[$key] as $range) {
                $sidecar = ['version' => 1, 'ranges' => [['id' => 'r', 'kind' => 'test'] + $range]];
                try {
                    AnnotationRanges::read($fixture['document'], $sidecar);
                    self::assertTrue($accepted);
                } catch (InvalidArgumentException $error) {
                    self::assertFalse($accepted, $error->getMessage());
                }
            }
        }
    }
}
