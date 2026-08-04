<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\Parser\BlockParser;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\TestCase;

/**
 * A bare-dot ordered marker survives the wire format.
 *
 * PART 11 §6 keeps the authored form - bullet characters, delimiters, the bare
 * marker - so a document that goes through `AstCodec` has to come back spelled
 * as it was written. `bareMarker` was on the list node and hidden from the
 * wire on the stated grounds that "`ordered` carries this", which it does not:
 * `ordered` says the list is ordered, not that the author wrote `.` with no
 * number. carve-js and carve-rs both publish the field (carve-php#711).
 */
class BareDotSurvivesTheCodecTest extends TestCase
{
    private function roundTrip(string $source): string
    {
        $codec = new AstCodec();
        $document = (new BlockParser())->parse($source);

        return (new CarveRenderer())->render($codec->decode($codec->encode($document)));
    }

    public function testABareDotListComesBackBare(): void
    {
        $source = ". first\n. second\n. third\n";

        $this->assertSame($source, $this->roundTrip($source));
    }

    public function testTheFieldIsOnTheWire(): void
    {
        $encoded = (new AstCodec())->encode((new BlockParser())->parse(". a\n"));

        $this->assertTrue($encoded['children'][0]['bareMarker']);
    }

    public function testANumberedListIsUnchanged(): void
    {
        $source = "1. first\n2. second\n";

        $this->assertSame($source, $this->roundTrip($source));
    }

    public function testANumberedListPublishesNoBareMarkerField(): void
    {
        $encoded = (new AstCodec())->encode((new BlockParser())->parse("1. a\n"));

        $this->assertArrayNotHasKey('bareMarker', $encoded['children'][0]);
    }

    public function testABulletListIsUnchanged(): void
    {
        $source = "- first\n- second\n";

        $this->assertSame($source, $this->roundTrip($source));
    }
}
