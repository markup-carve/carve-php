<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Test\RuleATodo;
use PHPUnit\Framework\TestCase;

class QuotedDescendantLiteralSpansTest extends TestCase
{
    use RuleATodo;

    public function testPairedSpansShieldPayloadClosersWithoutOpeningUnmatchedRuns(): void
    {
        /** @var list<array{id:string, source:string, html:string}> $rows */
        $rows = json_decode(
            (string)file_get_contents(__DIR__ . '/../../fixtures/quoted_descendant_literal_spans.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $this->assertCount(48, $rows);
        $converter = new CarveConverter();
        foreach ($rows as $row) {
            $actual = preg_replace('/>\s+</', '><', $converter->convert($row['source']));
            $this->assertSame($row['html'], trim((string)$actual), $row['id'] . "\n" . $row['source']);
        }
    }
}
