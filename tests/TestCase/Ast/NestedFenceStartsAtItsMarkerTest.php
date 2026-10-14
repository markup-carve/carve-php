<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\TestCase;

class NestedFenceStartsAtItsMarkerTest extends TestCase
{
    public function testNestedFenceSpanStartsAtBackticks(): void
    {
        $source = "> - ::: box\n>   - ::: |\n>     x\n>     :::\n>    ```\n>    :::bad\n>    :::\n- tail\n";
        $document = (new AstCodec())->encode((new BlockParser(false, false, false, true))->parse($source));
        $codes = [];
        $walk = static function (array $node) use (&$walk, &$codes): void {
            if (($node['type'] ?? null) === 'code_block') {
                $codes[] = $node;
            }
            foreach ($node as $value) {
                if (is_array($value) && isset($value['type'])) {
                    $walk($value);
                } elseif (is_array($value)) {
                    foreach ($value as $child) {
                        if (is_array($child) && isset($child['type'])) {
                            $walk($child);
                        }
                    }
                }
            }
        };
        $walk($document);

        self::assertCount(1, $codes);
        self::assertSame(47, $codes[0]['pos']['startOffset']);
    }
}
