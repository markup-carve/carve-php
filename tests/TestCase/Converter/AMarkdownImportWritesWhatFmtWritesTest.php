<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
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
            'an opener four columns in under a paragraph' => ["para\n    # x\n", "para\n\\# x\n"],
            'a lazy quote line' => ["> a\nb\n", "> a\n> b\n"],
            'a definition-shaped lazy line in an item' => ["1. a\nb\n[x]: <>\n", "1. a\n   b\n   \\[x]: <>\n"],
            'a definition-shaped lazy line in an item quote' => ["- > a\n[x]: <>\n", "- > a\n  > \\[x]: <>\n"],
            'a nested quote under a quote item' => ["> - a\n> > b\n", "> - a\n>\n> > b\n"],
            'a loose list of three' => ["1. a\n\n2. b\n3. c\n", "1. a\n\n2. b\n\n3. c\n"],
            'a one-item list parted by a fence' => ["- a\n\n  ```\n  x\n  ```\n", "{loose}\n- a\n\n  ```\n  x\n  ```\n"],
            'a setext heading in an item' => ["- a\n  ===\n", "- # a\n"],
            'a pipe table on an item line with rows' => ["- a | b\n  --- | ---\n  1 | 2\n", "- |= a |= b |\n  | 1 | 2 |\n"],
            'indented code on an ordered item line' => ["1.      x\n        y\n", "1. ```\n    x\n    y\n   ```\n"],
            'dash runs in a heading and a quote' => ["# a -- b\n\n> c --- d\n", "# a \\-\\- b\n\n> c \\-\\-\\- d\n"],
            'a moved item leaves code it does not hold' => ["   1. a\n\n    b\n", "1. a\n\n```\nb\n```\n"],
            'a paragraph after a thematic break' => ["***\nfoo\n", "---\n\nfoo\n"],
            'a paragraph after a quoted thematic break' => ["> ***\n> foo\n", "> ---\n>\n> foo\n"],
            'a paragraph with incidental indentation' => ["  foo\n", "foo\n"],
            'a closed pipe row with incidental indentation' => ["  | a | b |\n", "\\| a | b |\n"],
            'a closed pipe row continuing a paragraph' => ["foo\n  | a | b |\n", "foo\n\\| a | b |\n"],
            'a quoted paragraph with incidental indentation' => ["> foo\n>\n>   | a | b |\n", "> foo\n>\n> \\| a | b |\n"],
            // The separator under a break is not per block kind. These were the
            // kinds a paragraph-only rule left unformatted (carve-php#2385).
            'a heading after a thematic break' => ["***\n# H\n", "---\n\n# H\n"],
            'a list after a thematic break' => ["***\n- a\n", "---\n\n- a\n"],
            'a fence after a thematic break' => ["***\n```\nx\n```\n", "---\n\n```\nx\n```\n"],
            'a quote after a thematic break' => ["***\n> q\n", "---\n\n> q\n"],
            'a pipe row after a thematic break' => ["***\n| a | b |\n", "---\n\n\\| a | b |\n"],
            'a table after a thematic break' => ["***\n| a | b |\n| - | - |\n", "---\n\n|= a |= b |\n"],
            'a list inside a quoted thematic break' => ["> ***\n> - a\n", "> ---\n>\n> - a\n"],
            'a shallower line under a nested quoted break' => ["> > ***\n> foo\n", "> > ---\n>\n> foo\n"],
            'a deeper quote under a quoted break' => ["> ***\n> > foo\n", "> ---\n>\n> > foo\n"],
            'a quote under a top-level break' => ["***\n> foo\n", "---\n\n> foo\n"],
            // Past four columns the dedent has no upper bound, because on a line
            // continuing a paragraph no indent is code (carve-php#2384).
            'a continuation eight columns in' => ["para\n        code\n", "para\ncode\n"],
            'an ordered marker deep in a continuation' => ["para\n    2. x\n", "para\n2. x\n"],
        ];
    }

    #[DataProvider('shapes')]
    public function testTheImportIsTheFormattersSpelling(string $markdown, string $carve): void
    {
        $this->assertSame($carve, (new MarkdownToCarve())->convert($markdown));
    }

    /**
     * The contract itself, rather than a snapshot of it: whatever the import
     * writes, a formatter pass over it changes nothing. A pinned pair that drifts
     * from `fmt` passes the assertion above and still breaks the contract, which
     * is how five thematic-break fixtures came to pin the unformatted bytes.
     */
    #[DataProvider('shapes')]
    public function testAFormatterPassOverTheImportChangesNothing(string $markdown, string $carve): void
    {
        $this->assertSame($carve, CarveConverter::toCarve($carve));
        $imported = (new MarkdownToCarve())->convert($markdown);
        $this->assertSame($imported, CarveConverter::toCarve($imported));
    }
}
