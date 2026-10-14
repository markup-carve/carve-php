<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A `+` continuation on a span marker joins the cell the marker resolves into;
 * a blocked marker is a cell of its own and keeps it (PART 9 T5, T6, carve#3151).
 */
class AContinuationOnASpanMarkerJoinsItsOriginTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function provideTables(): array
    {
        return [
            'caret after the origin row' => [
                "| a | b |\n| ^ | c |\n+ more | d |\n",
                "<table>\n  <tbody>\n    <tr><td rowspan=\"2\">a more</td><td>b</td></tr>\n    <tr><td>c d</td></tr>\n  </tbody>\n</table>",
            ],
            'continuation before the caret row' => [
                "| a | b |\n+ more | d |\n| ^ | c |\n",
                "<table>\n  <tbody>\n    <tr><td rowspan=\"2\">a more</td><td>b d</td></tr>\n    <tr><td>c</td></tr>\n  </tbody>\n</table>",
            ],
            'chain of carets' => [
                "| a | b |\n| ^ | c |\n| ^ | e |\n+ x | y |\n",
                "<table>\n  <tbody>\n    <tr><td rowspan=\"3\">a x</td><td>b</td></tr>\n    <tr><td>c</td></tr>\n    <tr><td>e y</td></tr>\n  </tbody>\n</table>",
            ],
            'colspan marker' => [
                "| a | < |\n+ x | y |\n",
                "<table>\n  <tbody>\n    <tr><td colspan=\"2\">a x y</td></tr>\n  </tbody>\n</table>",
            ],
            'rowspan and colspan block' => [
                "| a | < | c |\n| ^ | ^ | d |\n+ x | y | z |\n",
                "<table>\n  <tbody>\n    <tr><td rowspan=\"2\" colspan=\"2\">a x y</td><td>c</td></tr>\n    <tr><td>d z</td></tr>\n  </tbody>\n</table>",
            ],
            'gfm head rowspan' => [
                "| A |\n|---|\n| ^ |\n+ x |\n",
                "<table>\n  <tbody>\n    <tr><th scope=\"col\" rowspan=\"2\">A x</th></tr>\n    <tr></tr>\n  </tbody>\n</table>",
            ],
            'blocked colspan marker' => [
                "| a | b |\n| < | c |\n+ x | y |\n",
                "<table>\n  <tbody>\n    <tr><td>a</td><td>b</td></tr>\n    <tr><td>x</td><td>c y</td></tr>\n  </tbody>\n</table>",
            ],
            'blocked caret in the first row' => [
                "| ^ | b |\n+ x | y |\n",
                "<table>\n  <tbody>\n    <tr><td>x</td><td>b y</td></tr>\n  </tbody>\n</table>",
            ],
            'two origins in one row' => [
                "| a | b | c |\n| ^ | d | e |\n| f | ^ | g |\n+ x | y | z |\n",
                "<table>\n  <tbody>\n    <tr><td rowspan=\"2\">a</td><td>b</td><td>c</td></tr>\n    <tr><td rowspan=\"2\">d y</td><td>e</td></tr>\n    <tr><td>f x</td><td>g z</td></tr>\n  </tbody>\n</table>",
            ],
            'blocked caret under a wide cell' => [
                "| a | < |\n| ^ | b |\n+ x | y |\n",
                "<table>\n  <tbody>\n    <tr><td colspan=\"2\">a</td></tr>\n    <tr><td>x</td><td>b y</td></tr>\n  </tbody>\n</table>",
            ],
            'blocked caret then a colspan joining it' => [
                "| a | b |\n| ^ | c |\n| < | d |\n+ x | y |\n",
                "<table>\n  <tbody>\n    <tr><td rowspan=\"2\">a</td><td>b</td></tr>\n    <tr><td>c</td></tr>\n    <tr><td>x</td><td>d y</td></tr>\n  </tbody>\n</table>",
            ],
            'colspan widening a blocked caret' => [
                "| ^ | < |\n+ x | y |\n",
                "<table>\n  <tbody>\n    <tr><td colspan=\"2\">x y</td></tr>\n  </tbody>\n</table>",
            ],
            'colspan widening a blocked caret with no text of its own' => [
                "| ^ | < |\n+ | y |\n",
                "<table>\n  <tbody>\n    <tr><td colspan=\"2\">y</td></tr>\n  </tbody>\n</table>",
            ],
            'two continuation lines on one caret' => [
                "|= h |= i |\n| a | b |\n| ^ | c |\n+ more | |\n+ again | d |\n",
                "<table>\n  <thead>\n    <tr><th scope=\"col\">h</th><th scope=\"col\">i</th></tr>\n  </thead>\n  <tbody>\n    <tr><td rowspan=\"2\">a more again</td><td>b</td></tr>\n    <tr><td>c d</td></tr>\n  </tbody>\n</table>",
            ],
        ];
    }

    #[DataProvider('provideTables')]
    public function testJoinsTheCellTheMarkerResolvesInto(string $source, string $expected): void
    {
        $this->assertSame($expected, trim((new CarveConverter())->convert($source)));
    }
}
