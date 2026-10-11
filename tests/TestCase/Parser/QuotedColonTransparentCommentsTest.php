<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

class QuotedColonTransparentCommentsTest extends TestCase
{
    public function testCommentsPreserveTheHostUntilItsValidCloser(): void
    {
        /** @var list<array{source:string, html:string}> $rows */
        $rows = json_decode(
            (string)file_get_contents(__DIR__ . '/../../fixtures/quoted_colon_transparent_comments.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $this->assertCount(27, $rows);
        $converter = new CarveConverter();
        foreach ($rows as $row) {
            $actual = preg_replace('/>\s+</', '><', $converter->convert($row['source']));
            $this->assertSame($row['html'], trim((string)$actual), $row['source']);
        }
    }
}
