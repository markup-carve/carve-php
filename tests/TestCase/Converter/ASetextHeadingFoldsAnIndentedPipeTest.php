<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A line four columns into a paragraph is continuation text whatever it is
 * shaped like, and a pipe is no exception: no table can form there, because a
 * table needs a header row that interrupts the paragraph and nothing at that
 * column does. The fold used to keep such a line out, which turned the
 * heading into a paragraph and a rule.
 */
class ASetextHeadingFoldsAnIndentedPipeTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function shapes(): array
    {
        return [
            'a pipe' => ["foo\n    | bar\n---\n", '<h2>foo | bar</h2>'],
            'a pipe row' => ["foo\n    | bar |\n---\n", '<h2>foo | bar |</h2>'],
            'under an equals underline' => ["foo\n    | bar\n===\n", '<h1>foo | bar</h1>'],
            'a header row over a delimiter row' => [
                "foo\n    | a | b |\n    | - | - |\n---\n",
                '<h2>foo | a | b | | - | - |</h2>',
            ],
            // The control: a marker the fold already accepted, which reads the
            // same before and after the pipe carve-out goes.
            'a heading marker' => ["foo\n    # bar\n---\n", '<h2>foo # bar</h2>'],
        ];
    }

    #[DataProvider('shapes')]
    public function testThePipeFoldsIntoTheHeading(string $markdown, string $heading): void
    {
        $imported = (new MarkdownToCarve())->convert($markdown);

        $this->assertStringContainsString($heading, (new CarveConverter())->convert($imported));
        $this->assertSame($imported, CarveConverter::toCarve($imported));
    }

    public function testTheFoldReachesAQuote(): void
    {
        $imported = (new MarkdownToCarve())->convert("> foo\n>     | bar\n> ---\n");

        $this->assertSame(
            "<blockquote>\n  <h2 id=\"foo-bar\">foo | bar</h2>\n</blockquote>\n",
            (new CarveConverter())->convert($imported),
        );
    }

    /**
     * The carve-out that stays: within three columns of the paragraph a pipe
     * row does open a table, so it is a table and not heading text.
     */
    public function testAPipeRowAtTheParagraphColumnStillOpensATable(): void
    {
        $html = (new CarveConverter())->convert((new MarkdownToCarve())->convert("foo\n| a | b |\n| - | - |\n"));

        $this->assertStringContainsString('<th scope="col">a</th>', $html);
        $this->assertStringNotContainsString('<h2>', $html);
    }
}
