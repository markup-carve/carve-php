<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

final class DelimitedTableInvalidCountFallbackTest extends TestCase
{
    public function testValuesThatStateNoCountKeepTheImplicitHeader(): void
    {
        $expected = "<table>\n  <thead>\n    <tr><th scope=\"col\">a</th></tr>\n  </thead>\n"
            . "  <tbody>\n    <tr><td>b</td></tr>\n  </tbody>\n</table>";
        $converter = CarveConverter::create();
        foreach (['x', '-1', '1.5', '9007199254740992'] as $value) {
            foreach (['csv', 'tsv'] as $format) {
                $source = "{table header-rows={$value}}\n```{$format}\na\nb\n```\n";
                self::assertSame($expected, $converter->convert($source), $source);

                $source = "{table footer-rows={$value}}\n```{$format}\na\nb\n```\n";
                self::assertSame($expected, $converter->convert($source), $source);
            }
        }
    }

    public function testValidCountPastTheRowsKeepsOneBodyGroup(): void
    {
        self::assertSame(
            "<table>\n  <tbody>\n    <tr><th scope=\"row\">a</th></tr>\n"
                . "    <tr><th scope=\"row\">b</th></tr>\n  </tbody>\n</table>",
            CarveConverter::create()->convert("{table header-rows=5}\n```csv\na\nb\n```\n"),
        );
    }
}
