<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use InvalidArgumentException;
use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\Ast\AstPatch;
use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

class AstPatchTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function ast(string $source): array
    {
        return (new AstCodec())->encode(CarveConverter::create()->parse($source));
    }

    public function testPatchRoundTripsThroughJsonAndReplaysRevision(): void
    {
        $before = $this->ast("# Title\n\nSee [docs](/a).\n");
        $after = $this->ast("## Title\n\nSee [docs](/b).\n\nAdded.\n");
        $wire = json_encode(AstPatch::create($before, $after), JSON_THROW_ON_ERROR);
        $operations = json_decode($wire, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(AstPatch::apply($after, []), AstPatch::apply($before, $operations));
    }

    public function testScalarEditProducesNarrowPatch(): void
    {
        $patch = AstPatch::create($this->ast("See [docs](/a).\n"), $this->ast("See [docs](/b).\n"));

        $this->assertCount(1, $patch);
        $this->assertSame('replace', $patch[0]['op']);
        $this->assertStringEndsWith('/href', $patch[0]['path']);
        $this->assertSame('/b', $patch[0]['value']);
    }

    public function testInvalidPointerIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        AstPatch::apply($this->ast("one\n"), [['op' => 'remove', 'path' => '/missing/value']]);
    }
}
