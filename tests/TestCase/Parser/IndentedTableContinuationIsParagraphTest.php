<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

class IndentedTableContinuationIsParagraphTest extends TestCase
{
    public function testIndentedPlusLineAfterTableIsProse(): void
    {
        $html = (new CarveConverter())->convert("| a |\n  + |\n");

        $this->assertSame(
            "<table>\n  <tbody>\n    <tr><td>a</td></tr>\n  </tbody>\n</table>\n<p>+ |</p>\n",
            $html,
        );
    }

    public function testFirstColumnPlusLineContinuesTable(): void
    {
        $html = (new CarveConverter())->convert("| a |\n+ |\n");

        $this->assertSame("<table>\n  <tbody>\n    <tr><td>a</td></tr>\n  </tbody>\n</table>\n", $html);
    }
}
