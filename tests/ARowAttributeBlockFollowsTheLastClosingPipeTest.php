<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A cell starting with `{` after a pipe is a cell; the row attribute block is
 * the one glued to the LAST closing pipe (carve#4322, corpus 836).
 */
class ARowAttributeBlockFollowsTheLastClosingPipeTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function rowProvider(): iterable
    {
        yield 'tilde cell' => ['| a |{~|{.c}', '<tr class="c"><td>a</td><td>{~</td></tr>'];
        yield 'equals cell' => ['|{=|{#r}', '<tr id="r"><td>{=</td></tr>'];
        yield 'brace-opening cell before a class' => ['|{.x|{.c}', '<tr class="c"><td>{.x</td></tr>'];
        yield 'quoted value holding a pipe' => ['|{-|{k="a|b"}', '<tr k="a|b"><td>{-</td></tr>'];
    }

    #[DataProvider('rowProvider')]
    public function testTheRowTakesTheBlockAfterTheLastPipe(string $source, string $row): void
    {
        $this->assertSame(
            "<table>\n  <tbody>\n    $row\n  </tbody>\n</table>",
            trim((new CarveConverter())->convert($source)),
        );
    }
}
