<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Extension\CitationsExtension;
use PHPUnit\Framework\TestCase;

/**
 * The `[+@...]` group marker is `mode: "integral"` on the wire, absent when
 * parenthetical - the shape `$defs.citation_group` pins with
 * `additionalProperties: false`.
 *
 * This engine keeps a boolean `integral`, which reached the wire under its
 * internal name - a field the schema does not allow - so the codec's own
 * output failed its own decode for every document holding an integral group
 * (carve-php#1285).
 */
class CitationModeShapeTest extends TestCase
{
    protected function converter(): CarveConverter
    {
        $converter = new CarveConverter();
        $converter->addExtension(new CitationsExtension());

        return $converter;
    }

    public function testAnIntegralGroupPublishesModeAndDecodes(): void
    {
        $document = $this->converter()->parse("Only [+@knuth84] integral.\n");
        $codec = new AstCodec();

        $wire = json_decode($codec->encodeJson($document), true);
        $group = $wire['children'][0]['children'][1];

        $this->assertSame('citation_group', $group['type']);
        $this->assertSame('integral', $group['mode']);
        $this->assertArrayNotHasKey('integral', $group);

        $decoded = $codec->decode($wire);
        $this->assertSame(
            CarveConverter::carve()->render($document),
            CarveConverter::carve()->render($decoded),
        );
        $this->assertSame(
            $this->converter()->render($this->converter()->parse("Only [+@knuth84] integral.\n")),
            $this->converter()->render($decoded),
        );
    }

    public function testAParentheticalGroupPublishesNoModeAndDecodes(): void
    {
        $document = $this->converter()->parse("See [see @doe99, pp. 33-35].\n");
        $codec = new AstCodec();

        $wire = json_decode($codec->encodeJson($document), true);
        $group = $wire['children'][0]['children'][1];

        $this->assertSame('citation_group', $group['type']);
        $this->assertArrayNotHasKey('mode', $group);
        $this->assertArrayNotHasKey('integral', $group);

        $decoded = $codec->decode($wire);
        $this->assertSame(
            CarveConverter::carve()->render($document),
            CarveConverter::carve()->render($decoded),
        );
    }
}
