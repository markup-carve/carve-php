<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `::` followed only by whitespace is the empty term marker `::` (PART 2,
 * CARVE-P2-025): it opens no term, so inside a description body it folds as
 * text the way the bare `::` does (markup-carve/carve-php#2218).
 */
class AnEmptyTermMarkerIsTextTest extends TestCase
{
    protected function html(string $source): string
    {
        return CarveConverter::create()->convert($source);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function emptyMarkers(): array
    {
        return [
            'one trailing space' => [
                ":: t\n: a\n:: \nc\n",
                "<dl>\n  <dt>t</dt>\n  <dd>a\n::\nc</dd>\n</dl>\n",
            ],
            'two trailing spaces' => [
                ":: t\n: a\n::  \nc\n",
                "<dl>\n  <dt>t</dt>\n  <dd>a\n::\nc</dd>\n</dl>\n",
            ],
            'a trailing tab' => [
                ":: t\n: a\n::\t\nc\n",
                "<dl>\n  <dt>t</dt>\n  <dd>a\n::\nc</dd>\n</dl>\n",
            ],
            'at the end of the document' => [
                ":: t\n: a\n:: \n",
                "<dl>\n  <dt>t</dt>\n  <dd>a\n::</dd>\n</dl>\n",
            ],
            'after a continuation line' => [
                ":: t\n: a\n  b\n:: \nc\n",
                "<dl>\n  <dt>t</dt>\n  <dd>a\nb\n::\nc</dd>\n</dl>\n",
            ],
            'before a description marker' => [
                ":: t\n: a\n:: \n: c\n",
                "<dl>\n  <dt>t</dt>\n  <dd>a\n::</dd>\n  <dd>c</dd>\n</dl>\n",
            ],
            'after a term, before its description' => [
                ":: t\n:: \n: d\n",
                "<dl>\n  <dt>t\n::</dt>\n  <dd>d</dd>\n</dl>\n",
            ],
        ];
    }

    #[DataProvider('emptyMarkers')]
    public function testAnEmptyMarkerWithTrailingWhitespaceIsText(string $source, string $expected): void
    {
        $this->assertSame($expected, $this->html($source));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function controls(): array
    {
        return [
            'the bare marker folds the same way' => [
                ":: t\n: a\n::\nc\n",
                "<dl>\n  <dt>t</dt>\n  <dd>a\n::\nc</dd>\n</dl>\n",
            ],
            'a term with text still ends the body' => [
                ":: t\n: a\n:: u\n: v\n",
                "<dl>\n  <dt>t</dt>\n  <dd>a</dd>\n  <dt>u</dt>\n  <dd>v</dd>\n</dl>\n",
            ],
            'at document level the line is paragraph text' => [
                "p\n:: \nq\n",
                "<p>p\n::\nq</p>\n",
            ],
        ];
    }

    #[DataProvider('controls')]
    public function testTheControlsDoNotMove(string $source, string $expected): void
    {
        $this->assertSame($expected, $this->html($source));
    }
}
