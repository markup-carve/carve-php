<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\Ast\StoredPayloadUpgrade;
use MarkupCarve\Carve\Exception\AstDecodeException;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * The migration's array entry points apply the bound its string one applies
 * (carve-php#1051).
 *
 * `upgradeJson()` was bounded for free: `json_decode` takes a depth argument and
 * refuses a payload past it before `upgrade()` ever sees it. The two array entry
 * points beside it - `upgrade()` and `retiredShapesIn()` - were handed a
 * structure somebody else had decoded, and `upgradeNodes()` and `scan()` are
 * plain recursion, so a deep enough payload exhausted the C stack: exit 139, no
 * exception, nothing a caller can catch.
 *
 * This class exists to accept ARBITRARY stored payloads. That is its whole
 * purpose, and it makes it the least trusted input in the package: a sweep over
 * a payload store is exactly the setting where nobody wrote the records being
 * read.
 */
class StoredPayloadUpgradeIsBoundedByDepthTest extends TestCase
{
    /**
     * A ladder of `children` arrays. JSON container nesting is `$arrays` plus
     * the root object.
     */
    protected function ladderJson(int $arrays): string
    {
        return '{"type":"document","srcByteLength":0,"children":'
            . str_repeat('[', $arrays) . str_repeat(']', $arrays)
            . '}';
    }

    /**
     * The container nesting `$json` actually has, measured rather than assumed:
     * `json_decode`'s `$depth` is exclusive, so the smallest argument that
     * parses is one more than the nesting.
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
        // The generator's own control: every case below picks its number of
        // arrays for the nesting it produces.
        $this->assertSame(4, $this->nestingOf($this->ladderJson(3)));
        $this->assertSame(51, $this->nestingOf($this->ladderJson(50)));
    }

    public function testUpgradeRefusesAPayloadPastTheBound(): void
    {
        // 20,000 levels is what crashed the process. It is refused now, and the
        // refusal has to arrive as an exception rather than as a signal.
        $node = ['type' => 'paragraph', 'children' => [['type' => 'text', 'value' => 'x']]];
        for ($i = 0; $i < 20000; $i++) {
            $node = ['type' => 'div', 'children' => [$node]];
        }
        $payload = ['type' => 'document', 'srcByteLength' => 1, 'children' => [$node]];

        // The generator, checked before it is trusted.
        $walked = 0;
        $probe = $payload;
        while (isset($probe['children'][0])) {
            $probe = $probe['children'][0];
            $walked++;
        }
        $this->assertSame(20002, $walked);

        $this->expectException(AstDecodeException::class);
        $this->expectExceptionMessageMatches('/nests deeper than \d+ levels/');
        StoredPayloadUpgrade::upgrade($payload);
    }

    public function testRetiredShapesInRefusesAPayloadPastTheBound(): void
    {
        // The sibling entry point, reachable on its own as well as through the
        // decoder. It crashed at the same depth and for the same reason.
        /** @var array<mixed> $payload */
        $payload = json_decode($this->ladderJson(AstCodec::MAX_JSON_DEPTH - 1), true, 20000);

        $this->expectException(AstDecodeException::class);
        $this->expectExceptionMessageMatches('/nests deeper than \d+ levels/');
        StoredPayloadUpgrade::retiredShapesIn($payload);
    }

    public function testTheArrayPathRefusesExactlyWhatTheStringPathRefuses(): void
    {
        // One container past the bound.
        $json = $this->ladderJson(AstCodec::MAX_JSON_DEPTH - 1);
        $this->assertSame(AstCodec::MAX_JSON_DEPTH, $this->nestingOf($json));

        $stringPath = null;
        try {
            StoredPayloadUpgrade::upgradeJson($json);
        } catch (Throwable $e) {
            $stringPath = $e->getMessage();
        }
        $this->assertNotNull($stringPath, 'upgradeJson accepted a payload past its own bound');

        /** @var array<mixed> $data */
        $data = json_decode($json, true, 20000);
        $arrayPath = null;
        try {
            StoredPayloadUpgrade::upgrade($data);
        } catch (Throwable $e) {
            $arrayPath = $e->getMessage();
        }
        $this->assertNotNull($arrayPath);
        $this->assertStringContainsString('nests deeper', $arrayPath);
    }

    public function testTheArrayPathAcceptsExactlyWhatTheStringPathAccepts(): void
    {
        // The deepest payload the string path takes. Both sides have to get
        // past the depth question here, or the migration would refuse records
        // the reader beside it accepts and nobody could sweep them.
        $json = $this->ladderJson(AstCodec::MAX_JSON_DEPTH - 2);
        $this->assertSame(AstCodec::MAX_JSON_DEPTH - 1, $this->nestingOf($json));

        /** @var array<mixed> $data */
        $data = json_decode($json, true, 20000);

        $fromArray = StoredPayloadUpgrade::upgrade($data);
        $this->assertSame($data, $fromArray, 'a payload needing nothing comes back untouched');

        $this->assertSame($json, StoredPayloadUpgrade::upgradeJson($json));
    }

    public function testACyclicPayloadIsRefusedRatherThanWalkedForever(): void
    {
        // JSON cannot express a cycle; a caller assembling an array can, and
        // this entry point takes whatever a caller assembled. A cycle is
        // infinitely deep, so the bound is what ends the walk.
        $cyclic = ['type' => 'document', 'srcByteLength' => 0];
        $cyclic['children'] = [&$cyclic];

        $this->expectException(AstDecodeException::class);
        $this->expectExceptionMessageMatches('/nests deeper than \d+ levels/');
        StoredPayloadUpgrade::upgrade($cyclic);
    }

    public function testAnOrdinaryStoredPayloadStillUpgrades(): void
    {
        // The invariant a bound must not break. A pre-PART 12 §7 record still
        // converts, and a current one still comes back untouched.
        $stored = [
            'type' => 'document',
            'srcByteLength' => 5,
            'children' => [
                ['type' => 'paragraph', 'children' => [['type' => 'raw_text', 'content' => 'hello']]],
            ],
        ];

        $upgraded = StoredPayloadUpgrade::upgrade($stored);

        $this->assertNotSame($stored, $upgraded);
        $this->assertSame('text', $upgraded['children'][0]['children'][0]['type']);
        $this->assertSame('hello', $upgraded['children'][0]['children'][0]['value']);
    }

    public function testAnOrdinaryPayloadStillReportsItsRetiredShapes(): void
    {
        $stored = [
            'type' => 'document',
            'srcByteLength' => 5,
            'children' => [
                ['type' => 'paragraph', 'children' => [['type' => 'raw_text', 'content' => 'hello']]],
            ],
        ];

        $this->assertNotSame([], StoredPayloadUpgrade::retiredShapesIn($stored));
    }
}
