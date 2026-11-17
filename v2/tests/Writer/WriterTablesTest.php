<?php

declare(strict_types=1);

namespace MarkupCarve\Carve2\Test\Writer;

use MarkupCarve\Carve2\Generated\WriterTables;
use MarkupCarve\Carve2\Writer\Text;
use PHPUnit\Framework\TestCase;

final class WriterTablesTest extends TestCase
{
    /**
     * C2-W-089: a trigger the data file adds that the escaper does not decide fails here, not in a document.
     *
     * @return void
     */
    public function testEveryTriggerIsDecided(): void
    {
        $missing = array_diff(str_split(WriterTables::ESCAPE_TRIGGERS), str_split(Text::DECIDED));

        $this->assertSame([], array_values($missing));
    }
}
