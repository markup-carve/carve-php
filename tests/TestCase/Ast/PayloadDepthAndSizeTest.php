<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Ast\PayloadDepth;
use MarkupCarve\Carve\Ast\PayloadSize;
use PHPUnit\Framework\TestCase;
use stdClass;

class PayloadDepthAndSizeTest extends TestCase
{
    public function testTheCombinedWalkKeepsTheExclusiveDepthBoundAndByteCount(): void
    {
        $object = new stdClass();
        $object->value = ['text' => "x\0y", 'enabled' => false];
        $payload = ['children' => [[], $object], 'number' => 123, 'nothing' => null];
        foreach (range(0, 8) as $limit) {
            $expected = PayloadDepth::within($payload, $limit)
                ? PayloadSize::bytes($payload, $limit)
                : null;
            $this->assertSame($expected, PayloadSize::bytesWithinDepth($payload, $limit));
        }
        $this->assertSame(0, PayloadSize::bytes($payload, 0));
        $this->assertNull(PayloadSize::bytesWithinDepth([], 1));
        $this->assertSame(2, PayloadSize::bytesWithinDepth([], 2));
    }

    public function testACyclicArrayStopsAtTheDepthBound(): void
    {
        $payload = [];
        $payload['self'] = &$payload;
        $this->assertNull(PayloadSize::bytesWithinDepth($payload, 32));
        $this->assertGreaterThan(0, PayloadSize::bytes($payload, 32));
    }
}
