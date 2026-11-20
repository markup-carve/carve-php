<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\TestCase;

final class TableBodyHeaderWriterTest extends TestCase
{
    public function testImportedBodyLedByHeaderRowsKeepsItsPartition(): void
    {
        $html = '<table><tr><th>A</th><th>B</th></tr><tr><td>m</td><td>n</td></tr></table>';
        $result = Carve::importHtml($html);

        self::assertSame("{body-rows=1 body-header-rows=1}\n| A | B |\n| m | n |\n", $result->source);
        self::assertSame([], $result->diagnostics);
        self::assertSame('table_body', $result->document['children'][0]['children'][0]['type']);
        self::assertTrue($result->document['children'][0]['children'][0]['children'][0]['children'][0]['header']);
    }

    public function testBodyHeaderCountsAreWrittenForEveryBody(): void
    {
        $html = '<table><tbody><tr><th>A</th></tr><tr><td>a</td></tr></tbody>'
            . '<tbody><tr><td>b</td></tr></tbody></table>';
        $result = Carve::importHtml($html);

        self::assertSame("{body-rows=1,1 body-header-rows=1,0}\n| A |\n| a |\n| b |\n", $result->source);
        self::assertSame([], $result->diagnostics);
        self::assertCount(2, $result->document['children'][0]['children']);
    }

    public function testRowHeadColumnsAreWrittenWithTheBodyPartition(): void
    {
        $source = "{body-rows=1 body-header-cols=1}\n|= H | x |\n";
        $tree = Carve::parse($source);

        self::assertSame($source, Carve::write($tree));
    }
}
