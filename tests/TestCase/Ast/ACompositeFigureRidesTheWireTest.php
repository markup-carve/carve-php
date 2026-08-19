<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\TestCase;

/**
 * PART 12 §16 (markup-carve/carve#1122): `figure_group` on the wire is
 * `type`, `children` (ordered blocks; panels are ordinary `figure` and
 * `table` nodes among them), an optional `caption` holding INLINE content,
 * `attrs` and `pos` - and deliberately no `target`, no title, no label.
 */
class ACompositeFigureRidesTheWireTest extends TestCase
{
    protected AstCodec $codec;

    protected CarveConverter $converter;

    protected function setUp(): void
    {
        $this->codec = new AstCodec();
        $this->converter = new CarveConverter();
    }

    /**
     * @return array<string, mixed>
     */
    protected function encodeFirst(string $source): array
    {
        $encoded = $this->codec->encode($this->converter->parse($source));

        return $encoded['children'][0];
    }

    public function testTheWireShapeIsTypeChildrenCaptionAttrs(): void
    {
        $group = $this->encodeFirst("{#fig-x}\n::: figure\n![one](a.png)\n^ (a) One\n:::\n^ Figure #: G\n");

        $this->assertSame('figure_group', $group['type']);
        $this->assertSame('fig-x', $group['attrs']['id']);
        $this->assertIsArray($group['children']);
        $this->assertSame('figure', $group['children'][0]['type']);
        // The GROUP caption is inline content, not a node wrapping it - the
        // same flattening a table's caption already gets.
        $this->assertIsArray($group['caption']);
        $this->assertArrayNotHasKey('type', $group['caption']);
        $this->assertArrayNotHasKey('target', $group);
    }

    public function testAnUncaptionedGroupPublishesNoCaptionKey(): void
    {
        // Absent means uncaptioned - no empty-array placeholder (ast-schema).
        $group = $this->encodeFirst("::: figure\n![one](a.png)\n^ (a) One\n:::\n");

        $this->assertArrayNotHasKey('caption', $group);
        $this->assertArrayHasKey('children', $group);
    }

    public function testAnEmptyGroupStillPublishesChildren(): void
    {
        // The schema requires `children` on every figure_group.
        $group = $this->encodeFirst("::: figure\n:::\n");

        $this->assertSame([], $group['children']);
    }

    public function testTheGroupSurvivesAJsonRoundTrip(): void
    {
        $source = "{#fig-x .columns-2}\n::: figure\n{#a}\n![one](a.png)\n^ (a) One\n\n| k |\n|---|\n:::\n^ Figure #: G\n";
        $document = $this->converter->parse($source);
        $decoded = $this->codec->decodeJson($this->codec->encodeJson($document));

        $this->assertSame(
            $this->converter->render($document),
            $this->converter->render($decoded),
            'HTML must survive decode(encode(parse(x)))',
        );
        $this->assertSame(
            CarveConverter::toCarve($source),
            (new CarveRenderer())->render($decoded),
            'the authored form must survive the wire',
        );
    }

    public function testTheDecodedTreeEncodesIdentically(): void
    {
        $source = "::: figure\n![one](a.png)\n^ (a) One\n:::\n^ Figure #: G\n";
        $encoded = $this->codec->encode($this->converter->parse($source));

        $this->assertSame($encoded, $this->codec->encode($this->codec->decode($encoded)));
    }
}
