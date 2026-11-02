<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * markup-carve/carve#4715 (authored spans) and markup-carve/carve#4640 (overlapping delimited counts).
 */
final class TableGridOwnsItsSpansTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function tables(): array
    {
        return [
            'authored rowspan' => [
                "|{rowspan=2} a | b |\n| c | d |\n",
                "<table>\n  <tbody>\n    <tr><td>a</td><td>b</td></tr>\n    <tr><td>c</td><td>d</td></tr>\n  </tbody>\n</table>",
            ],
            'authored colspan keeps other keys' => [
                "|{colspan=2 .k} a | b |\n",
                "<table>\n  <tbody>\n    <tr><td class=\"k\">a</td><td>b</td></tr>\n  </tbody>\n</table>",
            ],
            'any case' => [
                "|{ROWSPAN=2 ColSpan=3} a |\n",
                "<table>\n  <tbody>\n    <tr><td>a</td></tr>\n  </tbody>\n</table>",
            ],
            'authored span on a header cell' => [
                "|={rowspan=2} a |\n| c |\n",
                "<table>\n  <thead>\n    <tr><th scope=\"col\">a</th></tr>\n  </thead>\n  <tbody>\n    <tr><td>c</td></tr>\n  </tbody>\n</table>",
            ],
            'authored span beside a computed one' => [
                "|{colspan=3} a | b |\n| ^ | d |\n",
                "<table>\n  <tbody>\n    <tr><td rowspan=\"2\">a</td><td>b</td></tr>\n    <tr><td>d</td></tr>\n  </tbody>\n</table>",
            ],
            'authored align stays beside column alignment' => [
                "|>{align=left} a |\n",
                "<table>\n  <tbody>\n    <tr><td align=\"left\" style=\"text-align: right;\">a</td></tr>\n  </tbody>\n</table>",
            ],
            'overlapping delimited counts' => [
                "{table header-rows=2 footer-rows=1}\n```csv\na\nb\n```\n",
                "<table>\n  <tbody>\n    <tr><td>a</td></tr>\n    <tr><td>b</td></tr>\n  </tbody>\n</table>",
            ],
            'overlapping tsv counts' => [
                "{table header-rows=1 footer-rows=1}\n```tsv\na\n```\n",
                "<table>\n  <tbody>\n    <tr><td>a</td></tr>\n  </tbody>\n</table>",
            ],
            'a lone oversized header marks every row' => [
                "{table header-rows=9}\n```csv\na\nb\n```\n",
                "<table>\n  <tbody>\n    <tr><th scope=\"row\">a</th></tr>\n    <tr><th scope=\"row\">b</th></tr>\n  </tbody>\n</table>",
            ],
        ];
    }

    #[DataProvider('tables')]
    public function testTheGridOwnsTheSpans(string $source, string $html): void
    {
        $converter = new CarveConverter();
        $this->assertSame($html, $converter->convert($source));

        $formatted = (new CarveRenderer())->render($converter->parse($source));
        $this->assertSame($html, (new CarveConverter())->convert($formatted));
    }
}
