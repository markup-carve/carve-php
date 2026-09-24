<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The Markdown importer writes what `carve fmt` writes and reads what
 * cmark-gfm reads (#2263). The converter corpus pins one case per ruling; these
 * are the neighboring shapes, each byte-equal to carve-js.
 */
class AMarkdownImportWritesWhatFmtWritesTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function shapes(): array
    {
        return [
            'a padded body row in a list item' => ["- item\n  | a | b |\n  |---|---|\n  | 1   | 2   |\n", "- item\n  |= a |= b |\n  | 1 | 2 |\n"],
            'a short and a long body row' => ["| a | b |\n|---|---|\n| 1 |\n| 2 | 3 | 4 |\n", "|= a |= b |\n| 1 | |\n| 2 | 3 |\n"],
            'a lone span marker cell' => ["| < | b |\n|---|---|\n| x | ^ |\n", "|= \\< |= b |\n| x | \\^ |\n"],
            'a fence holding a backtick fence' => ["~~~\n```\n~~~\n", "````\n```\n````\n"],
            'ordered items numbered on in a quote' => ["> 1. a\n> 1. b\n", "> 1. a\n> 2. b\n"],
            'a bullet change in a quote' => ["> - a\n> + b\n", "> - a\n>\n> * b\n"],
            'a wider number moves its lines' => ["9. a\n9. b\n   c\n", "9. a\n10. b\n    c\n"],
            'a sibling marker slack' => ["- a\n - b\n", "- a\n- b\n"],
            'a tab after a marker' => ["-\ta\n", "- a\n"],
            'a lazy line after an item' => ["- a\nb\n", "- a\n  b\n"],
            'a marker four columns in under an item paragraph' => ["- a\n    - b\n", "- a\n  - b\n"],
            'an opener four columns in under a paragraph' => ["para\n    # x\n", "para\n    \\# x\n"],
            'a lazy quote line' => ["> a\nb\n", "> a\n> b\n"],
            'a nested quote under a quote item' => ["> - a\n> > b\n", "> - a\n>\n> > b\n"],
            'a loose list of three' => ["1. a\n\n2. b\n3. c\n", "1. a\n\n2. b\n\n3. c\n"],
            'a one-item list parted by a fence' => ["- a\n\n  ```\n  x\n  ```\n", "{loose}\n- a\n\n  ```\n  x\n  ```\n"],
            'a setext heading in an item' => ["- a\n  ===\n", "- # a\n"],
            'a pipe table on an item line with rows' => ["- a | b\n  --- | ---\n  1 | 2\n", "- |= a |= b |\n  | 1 | 2 |\n"],
            'indented code on an ordered item line' => ["1.      x\n        y\n", "1. ```\n    x\n    y\n   ```\n"],
            'dash runs in a heading and a quote' => ["# a -- b\n\n> c --- d\n", "# a \\-\\- b\n\n> c \\-\\-\\- d\n"],
            'a moved item leaves code it does not hold' => ["   1. a\n\n    b\n", "1. a\n\n```\nb\n```\n"],
        ];
    }

    #[DataProvider('shapes')]
    public function testTheImportIsTheFormattersSpelling(string $markdown, string $carve): void
    {
        $this->assertSame($carve, (new MarkdownToCarve())->convert($markdown));
    }
}
