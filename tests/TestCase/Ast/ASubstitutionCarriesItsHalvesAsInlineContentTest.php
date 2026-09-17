<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Exception\AstDecodeException;
use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A substitution publishes `old` and `new`, two arrays of inline nodes, in
 * place of the `oldText` and `newText` strings (ruled on
 * markup-carve/carve-js#1827). The rows are the bytes carve-js posts there.
 */
class ASubstitutionCarriesItsHalvesAsInlineContentTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function bytes(): array
    {
        return [
            'an emphasis in each half' => [
                '{~/old/~>/new/~}',
                '[{"type":"substitution","old":[{"type":"emphasis","children":[{"type":"text","value":"old"}]}],'
                    . '"new":[{"type":"emphasis","children":[{"type":"text","value":"new"}]}]}]',
            ],
            'plain halves' => [
                '{~a~>b~}',
                '[{"type":"substitution","old":[{"type":"text","value":"a"}],"new":[{"type":"text","value":"b"}]}]',
            ],
            'math holding the arrow' => [
                '{~$`a~>b`~>c~}',
                '[{"type":"substitution","old":[{"type":"math","content":"a~>b","display":false}],'
                    . '"new":[{"type":"text","value":"c"}]}]',
            ],
            'an editorial comment holding the arrow' => [
                '{~a{# ~> #}b~>c~}',
                '[{"type":"substitution","old":[{"type":"text","value":"a"},{"type":"critic_comment","text":" ~> "},'
                    . '{"type":"text","value":"b"}],"new":[{"type":"text","value":"c"}]}]',
            ],
            'an empty deleted half' => [
                '{~~>b~}',
                '[{"type":"substitution","old":[],"new":[{"type":"text","value":"b"}]}]',
            ],
            'an empty inserted half' => [
                '{~a~>~}',
                '[{"type":"substitution","old":[{"type":"text","value":"a"}],"new":[]}]',
            ],
            'a code span holding the arrow is a strike' => [
                '{~`a~>b~}',
                '[{"type":"strike","children":[{"type":"code","value":"a~>b"}]}]',
            ],
        ];
    }

    #[DataProvider('bytes')]
    public function testTheWireShapeIsWhatTheRulingPins(string $source, string $expected): void
    {
        $encoded = (new AstCodec())->encode(CarveConverter::create()->parse($source));
        $inlines = $encoded['children'][0]['children'] ?? [];

        $this->assertSame($expected, self::withoutPositions($inlines));
    }

    /**
     * Each half's nodes point at their own source.
     */
    public function testEveryNodeInBothHalvesCarriesItsOwnPosition(): void
    {
        $encoded = (new AstCodec())->encode(
            CarveConverter::create(parser: new BlockParser(trackPositions: true))->parse('{~a~>b~}'),
        );
        $substitution = $encoded['children'][0]['children'][0];

        $this->assertSame([2, 3], [$substitution['old'][0]['pos']['startOffset'], $substitution['old'][0]['pos']['endOffset']]);
        $this->assertSame([5, 6], [$substitution['new'][0]['pos']['startOffset'], $substitution['new'][0]['pos']['endOffset']]);
    }

    #[DataProvider('bytes')]
    public function testThePayloadDecodesBackToTheSameDocument(string $source, string $expected): void
    {
        $codec = new AstCodec();
        $once = $codec->encode(CarveConverter::create()->parse($source));

        $this->assertSame(
            json_encode($once),
            json_encode($codec->encode($codec->decode(json_decode((string)json_encode($once), true)))),
        );
    }

    /**
     * The old fields are not a shape this ingest knows: PART 12 section 11
     * refuses a property the schema does not name.
     */
    public function testTheOldStringFieldsAreRefused(): void
    {
        $this->expectException(AstDecodeException::class);
        (new AstCodec())->decode([
            'type' => 'document',
            'srcByteLength' => 0,
            'children' => [
                [

                    'type' => 'paragraph',
                    'children' => [
                        ['type' => 'substitution', 'oldText' => 'a', 'newText' => 'b'],
                    ],
                ],
            ],
        ]);
    }

    /**
     * Resolution reaches both halves.
     */
    public function testAReferenceInEitherHalfResolves(): void
    {
        $html = (new CarveConverter())->convert("{~[a][r]~>[b][r]~}\n\n[r]: /u\n");

        $this->assertSame(2, substr_count($html, 'href="/u"'));
    }

    /**
     * @param array<int, array<string, mixed>> $inlines
     */
    private static function withoutPositions(array $inlines): string
    {
        $strip = static function (array $node) use (&$strip): array {
            unset($node['pos']);
            foreach (['children', 'old', 'new'] as $key) {
                if (isset($node[$key])) {
                    $node[$key] = array_map($strip, $node[$key]);
                }
            }

            return $node;
        };

        return (string)json_encode(array_map($strip, $inlines));
    }
}
