<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * Two wire shapes the published JSON schema rejected.
 *
 * A LIST has three separate author-choice fields and this engine had two, so
 * one name carried two meanings: `bulletChar` held `.` for an ordered list
 * (a value its `["-", "*"]` enum forbids) while `delim` - whose enum is
 * `[".", ")"]` - carried the numbering DIALECT.
 *
 * A FIGURE is `target` plus `caption` in the reference, and this engine
 * published its internal `children` array instead, along with a `caption` node
 * type the reference has none of.
 *
 * Both are mapped on the way out (PART 12 section 1); the parser is untouched.
 */
class ListAndFigureShapeTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function encode(string $source): array
    {
        return (new AstCodec())->encode((new CarveConverter())->parse($source));
    }

    /**
     * @return array<string, mixed>
     */
    private function firstBlock(string $source): array
    {
        return $this->encode($source)['children'][0];
    }

    public function testAnOrderedListPublishesItsDelimiterAsDelim(): void
    {
        $dot = $this->firstBlock("1. a\n");
        $paren = $this->firstBlock("1) a\n");

        $this->assertSame('.', $dot['delim']);
        $this->assertSame(')', $paren['delim']);
        // `bulletChar` is for bullets, and its enum does not admit a `.`.
        $this->assertArrayNotHasKey('bulletChar', $dot);
        $this->assertArrayNotHasKey('bulletChar', $paren);
    }

    public function testABulletListStillPublishesBulletChar(): void
    {
        $this->assertSame('-', $this->firstBlock("- a\n")['bulletChar']);
        $this->assertSame('*', $this->firstBlock("* a\n")['bulletChar']);
        $this->assertArrayNotHasKey('delim', $this->firstBlock("- a\n"));
    }

    public function testTheNumberingDialectIsOlTypeNotDelim(): void
    {
        // `a. apple` is an alpha-dialect ordered list: the dialect is `a` and
        // the delimiter is `.`. Publishing the dialect as `delim` lost the
        // delimiter and put an illegal value in its place.
        $alpha = $this->firstBlock("a. apple\nb. banana\n");

        $this->assertSame('a', $alpha['olType']);
        $this->assertSame('.', $alpha['delim']);
    }

    public function testAFigureIsATargetAndACaption(): void
    {
        $figure = $this->firstBlock("![alt](/i.png)\n^ cap\n");

        $this->assertSame('figure', $figure['type']);
        $this->assertSame('image', $figure['target']['type']);
        $this->assertSame('cap', $figure['caption'][0]['value']);
        // The internal `children` array does not go on the wire, and neither
        // does the `caption` node type wrapping the inline content.
        $this->assertArrayNotHasKey('children', $figure);
    }

    public function testBothSurviveARoundTrip(): void
    {
        $codec = new AstCodec();
        foreach (["a. apple\nb. b\n", "1) a\n", "- a\n", "![alt](/i.png)\n^ cap\n"] as $source) {
            $encoded = $this->encode($source);
            $decoded = $codec->decode($encoded);

            $this->assertSame(
                $encoded,
                $codec->encode($decoded),
                sprintf('re-encoding %s must reproduce the payload', json_encode($source)),
            );
        }
    }

    public function testTheParserStillModelsAFigureAsChildren(): void
    {
        // The mapping is on the way out, not a change to the tree.
        $figure = (new CarveConverter())->parse("![alt](/i.png)\n^ cap\n")->getChildren()[0];

        $this->assertNotEmpty($figure->getChildren());
    }
}
