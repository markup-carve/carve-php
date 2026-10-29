<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Test\RuleATodo;
use PHPUnit\Framework\TestCase;

class DescendantLiteralCommentBoundaryTest extends TestCase
{
    use RuleATodo;

    public function testTheAncestorKeepsItsInvisibleCommentBoundary(): void
    {
        /** @var array<array{id:string, source:string, html:string}> $rows */
        $rows = json_decode(
            (string)file_get_contents(__DIR__ . '/../../fixtures/descendant_literal_comment_boundaries.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $this->assertCount(99, $rows);
        $converter = new CarveConverter();
        $codec = new AstCodec();
        foreach ($rows as $row) {
            $restored = $codec->decode($codec->encode($converter->parse($row['source'])));
            foreach ([$converter->convert($row['source']), $converter->render($restored)] as $html) {
                $this->assertSame(
                    preg_replace('/>\s+</', '><', trim($row['html'])),
                    preg_replace('/>\s+</', '><', trim($html)),
                    $row['id'] . "\n" . $row['source'],
                );
            }
        }
    }
}
