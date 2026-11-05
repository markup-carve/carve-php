<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\DjotToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve#5197: djot.js charges a blank read inside a nested list to that list,
 * so the outer item stays tight and the import must not loosen it.
 */
final class DjotBlankAfterASubListKeepsTheItemTightTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function imports(): array
    {
        return [
            'quote' => ["- one\n\n  - two\n\n  > q\n", "- one\n  - two\n  > q\n"],
            'heading' => ["- one\n\n  - two\n\n  # H\n", "- one\n  - two\n  # H\n"],
            'fence' => ["- one\n\n  - two\n\n  ```\n  code\n  ```\n", "- one\n  - two\n  ```\n  code\n  ```\n"],
            'list of another kind' => ["- one\n\n  - two\n\n  1. x\n", "- one\n  - two\n  1. x\n"],
            'attribute line' => ["- one\n\n  1. two\n\n  {.c}\n  para\n", "- one\n  1. two\n  {.c}\n  para\n"],
            'quoted quote' => ["> - one\n>\n>   - two\n>\n>   > q\n", "> - one\n>   - two\n>   > q\n"],
            'letter marker' => ["- one\n\n  a) two\n\n  > q\n", "- one\n  a) two\n  > q\n"],
            'paragraph' => ["- one\n\n  - two\n\n  para\n", "- one\n  - two\n+\npara\n"],
            'two-line paragraph' => ["- one\n\n  - two\n\n  para\n  more\n", "- one\n  - two\n+\npara\n  more\n"],
            'nested owner' => ["- top\n\n  - one\n\n    - two\n\n    para\n", "- top\n  - one\n    - two\n  +\n  para\n"],
            'quoted paragraph' => ["> - one\n>\n>   - two\n>\n>   para\n", "> - one\n>   - two\n> +\n> para\n"],
            'blank before a quote, no sub-list' => ["- one\n\n  > q\n", "- one\n\n  > q\n"],
            'unclosed parenthesized marker is text' => ["- parent\n\n  (1. child\n", "- parent\n\n  (1. child\n"],
        ];
    }

    #[DataProvider('imports')]
    public function testImport(string $djot, string $carve): void
    {
        $this->assertSame($carve, (new DjotToCarve())->convert($djot));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function paragraphLike(): array
    {
        return [
            'pipe' => ['| not a table'],
            'brace' => ['{not attributes'],
        ];
    }

    #[DataProvider('paragraphLike')]
    public function testAPipeOrBraceLedParagraphStaysAParagraph(string $line): void
    {
        $html = (new CarveConverter())->convert((new DjotToCarve())->convert("- one\n\n  - two\n\n  {$line}\n"));
        $this->assertMatchesRegularExpression('#</ul><p>|</ul>[^<]#', (string)preg_replace('/\s+/', '', $html));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function tight(): array
    {
        return [
            'quote' => ["- one\n\n  - two\n\n  > q\n"],
            'paragraph' => ["- one\n\n  - two\n\n  para\n"],
            'thematic break' => ["- one\n\n  - two\n\n  ***\n"],
            'deep nested list' => ["- one\n\n  - two\n    - deep\n\n  para\n"],
            'quoted fence' => ["> - one\n>\n>   - two\n>\n>   ```\n>   c\n>   ```\n"],
        ];
    }

    #[DataProvider('tight')]
    public function testTheOuterItemRendersTight(string $djot): void
    {
        $html = (new CarveConverter())->convert((new DjotToCarve())->convert($djot));
        $this->assertStringNotContainsString('<li><p>', (string)preg_replace('/\s+/', '', $html));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function loose(): array
    {
        return [
            'later blank in the item' => ["- one\n\n  - two\n\n  > q\n\n  para\n"],
            'blank after a block of the item' => ["- one\n\n  > a\n\n  - two\n\n  > q\n"],
            'div before a sibling' => ["- one\n\n  - two\n\n  ::: d\n  x\n  :::\n- next\n"],
            'loose sibling after an attribute line' => ["- one\n\n  1. two\n\n  {.c}\n  para\n\n- next\n"],
            'reference definition before a sibling' => ["- one\n\n  - two\n\n  [x]: /url\n\n- next\n"],
        ];
    }

    #[DataProvider('loose')]
    public function testStaysWhereDjotLoosensTheList(string $djot): void
    {
        $this->assertStringContainsString('<p>one</p>', (new CarveConverter())->convert((new DjotToCarve())->convert($djot)));
    }
}
