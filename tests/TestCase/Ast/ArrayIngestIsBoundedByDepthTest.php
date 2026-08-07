<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\Ast\AstSchema;
use MarkupCarve\Carve\Ast\PayloadDepth;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Exception\AstDecodeException;
use MarkupCarve\Carve\ProseMirror\ProseMirrorToCarve;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/**
 * Every array-taking ingest entry point applies the bound its string sibling
 * gets from `json_decode` (carve-php#1050).
 *
 * The string entry points were bounded for free: `decodeJson()` and
 * `convertJson()` hand a depth argument to `json_decode` and a payload past it
 * never reaches a walk. The array entry points beside them were handed a
 * structure somebody else had decoded, and every walk under them is plain
 * recursion, so a deep enough payload exhausted the C stack. That is a
 * segmentation fault: exit 139, no exception, nothing a caller can catch. The
 * bound existing on one path and not on the other path to the same value is the
 * whole defect, so the assertions here are about the two paths AGREEING.
 *
 * PHP's own recursion limit is not what any of this rests on. The measurement
 * in `PayloadDepth` does not recurse either, because a check that crashes on
 * the input it exists to refuse is not a check.
 */
class ArrayIngestIsBoundedByDepthTest extends TestCase
{
    /**
     * A pure structural ladder of `children` arrays.
     *
     * JSON container nesting is `$arrays` plus the root object: the ladder is
     * the cheapest way to sit on the bound exactly, and depth is the only thing
     * these tests are about, so the payload deliberately holds no nodes.
     */
    protected function ladderJson(int $arrays): string
    {
        return '{"type":"document","srcByteLength":0,"children":'
            . str_repeat('[', $arrays) . str_repeat(']', $arrays)
            . '}';
    }

    /**
     * The container nesting `$json` actually has, measured rather than assumed.
     *
     * `json_decode`'s `$depth` is an exclusive bound - `json_decode('{}', true, 1)`
     * fails and `json_decode('{}', true, 2)` succeeds - so the smallest argument
     * that parses is one more than the nesting. A generated payload can lose the
     * property it was generated for; this asks the parser instead of trusting
     * the generator.
     */
    protected function nestingOf(string $json): int
    {
        $low = 1;
        $high = 20000;
        while ($low < $high) {
            $middle = intdiv($low + $high, 2);
            if (json_decode($json, true, $middle) !== null) {
                $high = $middle;
            } else {
                $low = $middle + 1;
            }
        }

        return $low - 1;
    }

    public function testTheLadderHasTheNestingItClaims(): void
    {
        // The generator's own control. Everything below picks a number of
        // arrays because of the nesting it produces, so a ladder that stopped
        // nesting would make every other case here pass for the wrong reason.
        $this->assertSame(4, $this->nestingOf($this->ladderJson(3)));
        $this->assertSame(51, $this->nestingOf($this->ladderJson(50)));
    }

    public function testTheArrayPathRefusesWhatTheStringPathRefuses(): void
    {
        $codec = new AstCodec();
        // One past the bound: `MAX_JSON_DEPTH` is the `json_decode` argument,
        // and that argument is exclusive, so nesting equal to it is one level
        // too many.
        $json = $this->ladderJson(AstCodec::MAX_JSON_DEPTH - 1);
        $this->assertSame(AstCodec::MAX_JSON_DEPTH, $this->nestingOf($json));

        $stringPath = null;
        try {
            $codec->decodeJson($json);
        } catch (AstDecodeException $e) {
            $stringPath = $e->getMessage();
        }
        $this->assertNotNull($stringPath, 'decodeJson accepted a payload past its own bound');
        $this->assertMatchesRegularExpression('/nests deeper than \d+ levels/', $stringPath);

        /** @var array<string, mixed> $data */
        $data = json_decode($json, true, 20000);
        $this->expectException(AstDecodeException::class);
        $this->expectExceptionMessageMatches('/nests deeper than \d+ levels/');
        $codec->decode($data);
    }

    public function testTheArrayPathAcceptsWhatTheStringPathAccepts(): void
    {
        $codec = new AstCodec();
        // The deepest payload the string path takes. It is refused further down
        // for being a ladder of arrays where nodes belong, which is the point:
        // both paths get past the depth question and fail on the SAME later
        // one, so the bound is not quietly stricter on the array side.
        $json = $this->ladderJson(AstCodec::MAX_JSON_DEPTH - 2);
        $this->assertSame(AstCodec::MAX_JSON_DEPTH - 1, $this->nestingOf($json));

        /** @var array<string, mixed> $data */
        $data = json_decode($json, true, 20000);

        $fromString = null;
        try {
            $codec->decodeJson($json);
        } catch (AstDecodeException $e) {
            $fromString = $e->getMessage();
        }
        $fromArray = null;
        try {
            $codec->decode($data);
        } catch (AstDecodeException $e) {
            $fromArray = $e->getMessage();
        }

        $this->assertSame($fromString, $fromArray);
        $this->assertNotNull($fromArray);
        $this->assertStringNotContainsString('nests deeper', $fromArray);
    }

    public function testAnOrdinaryDocumentStillDecodesFromAnArray(): void
    {
        // The invariant the bound must not break: the decoder accepts anything
        // the encoder emits. A nesting cap is worth nothing if it also refuses
        // real documents.
        $codec = new AstCodec();
        $source = "# Title\n\n- one\n  - two\n    - three\n\n> quoted\n\nText with *bold*.\n";
        $document = (new CarveConverter())->parse($source);

        $decoded = $codec->decode($codec->encode($document));

        $this->assertSame(strlen($source), $decoded->getSourceLength());
    }

    public function testTheSchemaCheckReportsDepthRatherThanRecursingIntoIt(): void
    {
        /** @var array<string, mixed> $data */
        $data = json_decode($this->ladderJson(AstCodec::MAX_JSON_DEPTH - 1), true, 20000);

        $violation = AstSchema::firstViolation($data);

        $this->assertNotNull($violation);
        $this->assertStringContainsString('nests deeper', $violation);
    }

    public function testTheProseMirrorArrayPathIsBoundedToo(): void
    {
        $node = ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'x']]];
        for ($i = 0; $i < ProseMirrorToCarve::MAX_JSON_DEPTH; $i++) {
            $node = ['type' => 'blockquote', 'content' => [$node]];
        }
        $payload = ['type' => 'doc', 'content' => [$node]];

        // The generator, checked before it is trusted.
        $walked = 0;
        $probe = $payload;
        while (isset($probe['content'][0])) {
            $probe = $probe['content'][0];
            $walked++;
        }
        $this->assertGreaterThan(ProseMirrorToCarve::MAX_JSON_DEPTH, $walked);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/nests deeper than \d+ levels/');
        (new ProseMirrorToCarve())->convert($payload);
    }

    public function testTheTwoProseMirrorEntryPointsBoundTheSameSet(): void
    {
        // The drift this constant exists to prevent. Both numbers are derived
        // from `MAX_JSON_DEPTH` here, so putting a literal back into either
        // entry point separates them and one of the two halves below fails.
        $bridge = new ProseMirrorToCarve();

        $tooDeep = '{"type":"doc","content":'
            . str_repeat('[', ProseMirrorToCarve::MAX_JSON_DEPTH - 1)
            . str_repeat(']', ProseMirrorToCarve::MAX_JSON_DEPTH - 1) . '}';
        $this->assertSame(ProseMirrorToCarve::MAX_JSON_DEPTH, $this->nestingOf($tooDeep));

        $stringRefused = false;
        try {
            $bridge->convertJson($tooDeep);
        } catch (Throwable $e) {
            $stringRefused = true;
        }
        $this->assertTrue($stringRefused, 'convertJson accepted a payload past its own bound');

        /** @var array<string, mixed> $tooDeepArray */
        $tooDeepArray = json_decode($tooDeep, true, 20000);
        $arrayMessage = null;
        try {
            $bridge->convert($tooDeepArray);
        } catch (RuntimeException $e) {
            $arrayMessage = $e->getMessage();
        }
        $this->assertNotNull($arrayMessage);
        $this->assertStringContainsString('nests deeper', $arrayMessage);

        // And one level shallower, both get past depth and fail on the SAME
        // later question, so the array path is not quietly stricter either.
        $deepest = '{"type":"doc","content":'
            . str_repeat('[', ProseMirrorToCarve::MAX_JSON_DEPTH - 2)
            . str_repeat(']', ProseMirrorToCarve::MAX_JSON_DEPTH - 2) . '}';
        $this->assertSame(ProseMirrorToCarve::MAX_JSON_DEPTH - 1, $this->nestingOf($deepest));

        $fromString = null;
        try {
            $bridge->convertJson($deepest);
        } catch (Throwable $e) {
            $fromString = $e->getMessage();
        }
        /** @var array<string, mixed> $deepestArray */
        $deepestArray = json_decode($deepest, true, 20000);
        $fromArray = null;
        try {
            $bridge->convert($deepestArray);
        } catch (Throwable $e) {
            $fromArray = $e->getMessage();
        }

        $this->assertSame($fromString, $fromArray);
        $this->assertNotNull($fromArray);
        $this->assertStringNotContainsString('nests deeper', $fromArray);
    }

    public function testAnOrdinaryProseMirrorDocumentStillConverts(): void
    {
        $payload = [
            'type' => 'doc',
            'content' => [
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'hello']]],
            ],
        ];

        $document = (new ProseMirrorToCarve())->convert($payload);

        $this->assertCount(1, $document->getChildren());
    }

    public function testTheMeasurementItselfDoesNotRecurse(): void
    {
        // 20,000 levels crashed every array entry point before this. The
        // measurement runs over the same payload, so it has to survive it -
        // level by level rather than depth first.
        $payload = [];
        for ($i = 0; $i < 20000; $i++) {
            $payload = ['children' => [$payload]];
        }

        $this->assertFalse(PayloadDepth::within($payload, AstCodec::MAX_JSON_DEPTH));
    }

    public function testACyclicPayloadIsRefusedRatherThanWalkedForever(): void
    {
        // JSON cannot express a cycle, but a PHP array can, and the array entry
        // points take whatever a caller assembled rather than only what
        // `json_decode` produced. A cycle is infinitely deep, so the bound is
        // what stops the walk - by counting levels, which a cycle keeps
        // producing, rather than by looking for a repeat.
        $cyclic = ['type' => 'document', 'srcByteLength' => 0];
        $cyclic['children'] = [&$cyclic];

        $this->assertFalse(PayloadDepth::within($cyclic, AstCodec::MAX_JSON_DEPTH));

        $this->expectException(AstDecodeException::class);
        $this->expectExceptionMessageMatches('/nests deeper than \d+ levels/');
        (new AstCodec())->decode($cyclic);
    }

    public function testTheBoundIsExclusiveTheWayJsonDecodeReadsIt(): void
    {
        // `PayloadDepth::within()` takes the number a caller hands
        // `json_decode`, so the two cannot drift. `[]` is one container.
        $this->assertFalse(PayloadDepth::within([], 1));
        $this->assertTrue(PayloadDepth::within([], 2));
        $this->assertFalse(PayloadDepth::within([[]], 2));
        $this->assertTrue(PayloadDepth::within([[]], 3));

        // And that IS how json_decode reads it.
        $this->assertNull(json_decode('[]', true, 1));
        $this->assertNotNull(json_decode('[]', true, 2));
        $this->assertNull(json_decode('[[]]', true, 2));
        $this->assertNotNull(json_decode('[[]]', true, 3));
    }

    public function testScalarsAndEmptySlotsAreNotCountedAsNesting(): void
    {
        // A wide payload is not a deep one: the walk descends into arrays only,
        // so strings, numbers and nulls cost nothing.
        $wide = ['type' => 'document', 'srcByteLength' => 0, 'children' => []];
        for ($i = 0; $i < 5000; $i++) {
            $wide['children'][] = ['type' => 'text', 'value' => 'x'];
        }

        // Three containers: the root, its `children` array, and one child.
        $this->assertTrue(PayloadDepth::within($wide, 4));
        $this->assertFalse(PayloadDepth::within($wide, 3));
    }
}
