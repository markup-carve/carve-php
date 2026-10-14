<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\TestCase;

class ContinuationTextStaysInsideRowTest extends TestCase
{
    public function testJoinedTextDoesNotOutrunItsRow(): void
    {
        $source = "| a | b |\n| ^ | c |\n+ more | d |\n";
        $document = (new AstCodec())->encode((new BlockParser(false, false, false, true))->parse($source));
        $row = $document['children'][0]['rows'][0];
        $rowEnd = $row['pos']['endOffset'];
        self::assertGreaterThanOrEqual(26, $rowEnd);
        $walk = static function (array $node) use (&$walk, $rowEnd): void {
            if (($node['type'] ?? null) === 'text' && ($node['value'] ?? '') === 'more') {
                self::assertLessThanOrEqual($rowEnd, $node['pos']['endOffset']);
            }
            foreach ($node as $value) {
                if (is_array($value)) {
                    foreach ($value as $child) {
                        if (is_array($child) && isset($child['type'])) {
                            $walk($child);
                        }
                    }
                }
            }
        };
        $walk($row);
    }
}
