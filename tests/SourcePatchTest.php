<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use InvalidArgumentException;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\SourcePatch;
use PHPUnit\Framework\TestCase;

final class SourcePatchTest extends TestCase
{
    public function testPreservesBytesOutsideUtf8Edit(): void
    {
        self::assertSame('fnv1a64:c6f20701944350f0', SourcePatch::fingerprint("lead ä\n"));
        $source = "lead ä\nbody   \ntail\n";
        $expected = "lead ä\nbody\ntail\n";
        $patch = SourcePatch::create($source, $expected, 'formatting', 'canonical-format');
        self::assertSame(12, $patch->edits[0]->start);
        self::assertSame($expected, $patch->apply($source));
    }

    public function testRejectsAStaleSource(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SourcePatch::create('a', 'b')->apply('x');
    }

    public function testSharedUtf8ContinuationByteIsNotAnUnchangedSuffix(): void
    {
        foreach ([['¤', 'ä'], ['see → here', 'see ⇒ here']] as [$source, $expected]) {
            self::assertSame($expected, SourcePatch::create($source, $expected)->apply($source));
        }
    }

    public function testFormatsThroughAPatch(): void
    {
        $source = '# Title   ';
        self::assertSame("# Title\n", CarveConverter::toCarvePatch($source)->apply($source));
    }

    public function testSerializesTheSharedWireShape(): void
    {
        $wire = json_decode((string)json_encode(SourcePatch::create('a', 'b')), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(1, $wire['version']);
        self::assertSame('refactor', $wire['edits'][0]['kind']);
        self::assertSame([], $wire['unresolved']);
    }
}
