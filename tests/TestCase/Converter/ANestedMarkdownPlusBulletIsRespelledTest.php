<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Carve has no `+` bullet, so a nested Markdown item's `+` is respelled, and a
 * change of marker at one indent stays a change of list (#2125). Each HTML
 * expectation is what markdown-it's `commonmark` preset renders.
 */
class ANestedMarkdownPlusBulletIsRespelledTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string}>
     */
    public static function shapes(): array
    {
        return [
            'a nested plus list' => [
                "- a\n  + b\n  + c\n",
                "- a\n  - b\n  - c\n",
                '<ul><li>a<ul><li>b</li><li>c</li></ul></li></ul>',
            ],
            'under an ordered item' => ["1. a\n   + b\n", "1. a\n   - b\n", '<ol><li>a<ul><li>b</li></ul></li></ol>'],
            'a plus list after a hyphen list' => [
                "- a\n  - b\n  + c\n",
                "- a\n  - b\n  * c\n",
                '<ul><li>a<ul><li>b</li></ul><ul><li>c</li></ul></li></ul>',
            ],
            'a hyphen list after a plus list' => [
                "- a\n  + b\n  - c\n",
                "- a\n  - b\n  * c\n",
                '<ul><li>a<ul><li>b</li></ul><ul><li>c</li></ul></li></ul>',
            ],
            'a deeper plus list' => [
                "- a\n  + b\n    + c\n  + d\n",
                "- a\n  - b\n    - c\n  - d\n",
                '<ul><li>a<ul><li>b<ul><li>c</li></ul></li><li>d</li></ul></li></ul>',
            ],
            'one per outer item' => [
                "- a\n  + b\n- c\n  + d\n",
                "- a\n  - b\n- c\n  - d\n",
                '<ul><li>a<ul><li>b</li></ul></li><li>c<ul><li>d</li></ul></li></ul>',
            ],
        ];
    }

    #[DataProvider('shapes')]
    public function testTheBulletIsRespelled(string $markdown, string $carve, string $html): void
    {
        $this->assertSame($carve, (new MarkdownToCarve())->convert($markdown));
    }

    #[DataProvider('shapes')]
    public function testTheImportReadsBackAsCommonMarkReadsIt(string $markdown, string $carve, string $html): void
    {
        $rendered = (new CarveConverter())->convert((new MarkdownToCarve())->convert($markdown));

        $this->assertSame($html, str_replace(["\n", ' '], '', $rendered));
    }
}
