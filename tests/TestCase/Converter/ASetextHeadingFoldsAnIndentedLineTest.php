<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Indented code cannot interrupt a paragraph, so a line four columns in
 * continues it whatever the line is shaped like, and an underline under them
 * makes one setext heading. The fold used to refuse such a line, which left
 * the paragraph and the underline as a paragraph and a rule.
 */
class ASetextHeadingFoldsAnIndentedLineTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string}>
     */
    public static function shapes(): array
    {
        return [
            'a heading marker' => ["foo\n    # bar\n---\n", "## foo # bar\n", 'foo # bar'],
            'a bullet' => ["foo\n    - bar\n---\n", "## foo - bar\n", 'foo - bar'],
            'a quote marker' => ["foo\n    > bar\n---\n", "## foo > bar\n", 'foo &gt; bar'],
            'a thematic break' => ["foo\n    ***\n---\n", "## foo ***\n", 'foo ***'],
            'under an equals underline' => ["foo\n    # bar\n===\n", "# foo # bar\n", 'foo # bar'],
        ];
    }

    #[DataProvider('shapes')]
    public function testTheLineFoldsIntoTheHeading(string $markdown, string $carve, string $text): void
    {
        $imported = (new MarkdownToCarve())->convert($markdown);

        $this->assertSame($carve, $imported);
        $this->assertSame($imported, CarveConverter::toCarve($imported));
        $this->assertStringContainsString('>' . $text . '<', (new CarveConverter())->convert($imported));
    }

    public function testTheFoldReachesAQuote(): void
    {
        $imported = (new MarkdownToCarve())->convert("> foo\n>     # bar\n> ---\n");

        $this->assertSame("> ## foo # bar\n", $imported);
        $this->assertSame(
            "<blockquote>\n  <h2 id=\"foo-bar\">foo # bar</h2>\n</blockquote>\n",
            (new CarveConverter())->convert($imported),
        );
    }

    public function testWithNoUnderlineTheLineStaysWhereItIs(): void
    {
        $this->assertSame("para\n    \\# x\n", (new MarkdownToCarve())->convert("para\n    # x\n"));
    }
}
