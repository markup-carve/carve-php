<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * An attribute in a description (CARVE-P9-081), and reference and row attribute runs (PART 9R, `row_attributes`).
 */
final class AnAttributeInADescriptionKeepsItsParagraphTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function documents(): iterable
    {
        yield 'unresolved reference keeps both blocks' => ['[a][a]{.c}{#i}', '<p>[a][a]{.c}{#i}</p>'];
        yield 'row attribute value holds a pipe' => [
            '| a |{a="x|y"}',
            "<table>\n  <tbody>\n    <tr a=\"x|y\"><td>a</td></tr>\n  </tbody>\n</table>",
        ];

        yield 'attributed description paragraph' => [
            ":: T\n: {.c}\n  q",
            "<dl>\n  <dt>T</dt>\n  <dd><p class=\"c\">q</p></dd>\n</dl>",
        ];

        yield 'plain description paragraph stays tight' => [
            ":: T\n: q",
            "<dl>\n  <dt>T</dt>\n  <dd>q</dd>\n</dl>",
        ];
    }

    #[DataProvider('documents')]
    public function testDocument(string $source, string $html): void
    {
        $this->assertSame($html, (new CarveConverter())->convert($source . "\n"));
    }
}
