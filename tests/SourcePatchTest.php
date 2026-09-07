<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use InvalidArgumentException;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\SourceEdit;
use MarkupCarve\Carve\SourcePatch;
use MarkupCarve\Carve\SourceSuggestion;
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

    public function testAppliesSeveralOrderedEditsWithoutTouchingOtherBytes(): void
    {
        $source = 'one two three';
        $patch = new SourcePatch(SourcePatch::fingerprint($source), strlen($source), [
            new SourceEdit(0, 3, 'ONE', 'quick-fix', 'uppercase-one'),
            new SourceEdit(8, 13, 'THREE', 'refactor', 'uppercase-three'),
        ]);

        self::assertSame('ONE two THREE', $patch->apply($source));
    }

    public function testRejectsInvalidPatchMetadataAndRanges(): void
    {
        $source = 'ä';
        $cases = [
            new SourcePatch(SourcePatch::fingerprint($source), strlen($source), [], version: 2),
            new SourcePatch(SourcePatch::fingerprint($source), strlen($source), [new SourceEdit(1, 2, 'x')]),
            new SourcePatch(SourcePatch::fingerprint($source), strlen($source), [new SourceEdit(0, 2, 'x', 'unknown')]),
            new SourcePatch(SourcePatch::fingerprint($source), strlen($source), [new SourceEdit(0, 2, 'x', code: '')]),
        ];

        foreach ($cases as $patch) {
            try {
                $patch->apply($source);
                self::fail('The malformed patch should be rejected.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testRejectsInvalidUtf8AndSerializesSuggestions(): void
    {
        try {
            SourcePatch::create("\xff", 'valid');
            self::fail('Invalid UTF-8 should be rejected.');
        } catch (InvalidArgumentException) {
            self::addToAssertionCount(1);
        }

        $suggestion = new SourceSuggestion(0, 1, 'b', 'quick-fix', 'review', 'Review this change.');
        $patch = new SourcePatch(SourcePatch::fingerprint('a'), 1, [], [$suggestion]);
        $wire = json_decode((string)json_encode($patch), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('Review this change.', $wire['unresolved'][0]['message']);
    }
}
