<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * markup-carve/carve#5466: a note definition is no block for list tightness (CARVE-P9-013 point 3).
 */
final class ANotePlusTakesItsLineAndKeepsTheListTightTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function cases(): iterable
    {
        yield 'shape A: the lead note takes the next line' => [
            "- [^a]: +\n  flush\n\n  see[^a]\n",
            "<ul>\n  <li>see<a id=\"fnref1\"",
        ];

        yield 'the lead note takes a two-line paragraph' => [
            "- [^a]: +\n  flush\n  more\n\n  see[^a]\n",
            "<ul>\n  <li>see<a id=\"fnref1\"",
        ];

        yield 'a later note takes the next line' => [
            "- [^a]: x\n  [^b]: +\n  flush\n\n  see[^a][^b]\n",
            "<ul>\n  <li>see<a id=\"fnref1\"",
        ];

        yield 'a later note body after a blank' => [
            "- [^a]: x\n\n  [^b]: +\n    flush\n\n  see[^a][^b]\n",
            "<ul>\n  <li>see<a id=\"fnref1\"",
        ];

        yield 'a description body' => [
            ":: t\n:  [^a]: +\n   flush\n\n   see[^a]\n",
            "<dl>\n  <dt>t</dt>\n  <dd>see<a id=\"fnref1\"",
        ];

        yield 'shape B: a lazy line after a blank' => [
            "- [^a]: x\n\n  [^b]: d2\n  flush\n\n  see[^a]\n",
            "<ul>\n  <li>see<a id=\"fnref1\"",
        ];

        yield 'a taken heading ends the note on a block, as an indented note body does' => [
            "- [^a]: +\n  # H\n\n  see[^a]\n",
            "<ul>\n  <li><p>see<a id=\"fnref1\"",
        ];

        yield 'shape E: a comment lead before a definition' => [
            "- %% c0\n\n  [^a]: x\n\n  see[^a]\n",
            "<ul>\n  <li>see<a id=\"fnref1\"",
        ];

        yield 'a visible lead stays loose' => [
            "- lead\n  [^a]: +\n  flush\n\n  see[^a]\n",
            "<ul>\n  <li><p>lead</p>\n    <p>see<a id=\"fnref1\"",
        ];
    }

    #[DataProvider('cases')]
    public function testListPart(string $source, string $expected): void
    {
        self::assertStringStartsWith($expected, trim((new CarveConverter())->convert($source)));
    }
}
