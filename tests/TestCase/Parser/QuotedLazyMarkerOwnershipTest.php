<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Parser\BlockParser;
use MarkupCarve\Carve\Test\RuleATodo;
use PHPUnit\Framework\TestCase;

class QuotedLazyMarkerOwnershipTest extends TestCase
{
    use RuleATodo;

    public function testLazyMarkersKeepTheQuoteParagraphOwner(): void
    {
        /** @var array<array{id:string, source:string, html:string}> $rows */
        $rows = json_decode(
            (string)file_get_contents(__DIR__ . '/../../fixtures/quoted_lazy_marker_ownership.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $this->assertCount(226, $rows);
        $converter = new CarveConverter();
        $codec = new AstCodec();
        foreach ($rows as $row) {
            $actual = preg_replace('/>\s+</', '><', $converter->convert($row['source']));
            $this->assertSame($row['html'], trim((string)$actual), $row['id'] . "\n" . $row['source']);
            $restored = $codec->decode($codec->encode($converter->parse($row['source'])));
            $actual = preg_replace('/>\s+</', '><', $converter->render($restored));
            $this->assertSame($row['html'], trim((string)$actual), $row['id'] . ' AST round trip');
        }
    }

    public function testLazyMarkerPositionsNameTheOriginalSource(): void
    {
        foreach (['   ', "\t"] as $indent) {
            $source = "> paragraph\n" . $indent . "- x\n";
            $tree = (new AstCodec())->encode((new BlockParser(false, false, false, true))->parse($source));
            $this->assertSame('block_quote', $tree['children'][0]['type']);
            $found = false;
            foreach ($tree['children'][0]['children'][0]['children'] as $node) {
                if ($node['type'] !== 'text' || $node['value'] !== '- x') {
                    continue;
                }
                $found = true;
                $pos = $node['pos'] ?? null;
                $this->assertNotNull($pos);
                $this->assertSame('- x', substr($source, $pos['startOffset'], $pos['endOffset'] - $pos['startOffset']));
                $this->assertSame(2, $pos['startLine']);
                $this->assertSame(strlen($indent) + 1, $pos['startColumn']);
            }
            $this->assertTrue($found);
        }
    }
}
