<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * CommonMark lets only spaces and tabs follow a setext underline (4.3), a
 * thematic break (4.1), a closing code fence (4.5) and a list marker (5.2). A
 * form feed, vertical tab or no-break space there makes the line paragraph
 * text, and the front matter closer follows the same rule.
 */
class ADashLineAcceptsOnlySpaceOrTabAfterItTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function contentClosers(): array
    {
        return [
            'form feed' => ["---\f", "---\f"],
            'vertical tab' => ["---\x0B", "---\x0B"],
            'space then form feed' => ["--- \f", "--- \f"],
            'no-break space' => ["---\u{A0}", '---&nbsp;'],
        ];
    }

    #[DataProvider('contentClosers')]
    public function testACloserWithAnotherCharacterAfterItLeavesNoFrontMatter(string $closer, string $rendered): void
    {
        $carve = (new MarkdownToCarve())->convert("---yaml\na: 1\n" . $closer . "\n...\n***\nu\n");

        // `fmt` writes a blank line above a break (carve-php#2989), and the
        // rejected closer leaves the whole block one paragraph, so the break
        // below it takes that separator.
        $this->assertSame(
            '\\-\\-\\-yaml' . "\na: 1\n" . '\\-\\-\\-' . substr($closer, 3) . "\n...\n\n---\n\nu\n",
            $carve,
        );
        $this->assertSame(
            "<p>---yaml\na: 1\n" . $rendered . "\n\u{2026}</p>\n<hr>\n<p>u</p>\n",
            CarveConverter::create()->convert($carve),
        );
    }

    public function testACloserFollowedBySpacesAndTabsStillCloses(): void
    {
        $carve = (new MarkdownToCarve())->convert("---yaml\na: 1\n--- \t\n...\n***\nu\n");

        $this->assertSame("---yaml\na: 1\n--- \t\n\n...\n\n---\n\nu\n", $carve);
        $this->assertSame("<p>\u{2026}</p>\n<hr>\n<p>u</p>\n", CarveConverter::create()->convert($carve));
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function contentLines(): array
    {
        return [
            'setext dashes, vertical tab' => ["a\n---\x0B\n", "a\n\\-\\-\\-\x0B\n", "<p>a\n---\x0B</p>\n"],
            'setext dashes, form feed' => ["a\n---\f\n", "a\n\\-\\-\\-\f\n", "<p>a\n---\f</p>\n"],
            'setext equals, vertical tab' => ["a\n===\x0B\n", "a\n===\x0B\n", "<p>a\n===\x0B</p>\n"],
            'setext in a list item' => [
                "- a\n  ---\x0B\n",
                "- a\n  \\-\\-\\-\x0B\n",
                "<ul>\n  <li>a\n---\x0B</li>\n</ul>\n",
            ],
            'setext in a quote' => ["> a\n> ===\x0B\n", "> a\n> ===\x0B\n", "<blockquote><p>a\n===\x0B</p></blockquote>\n"],
            'setext in an item in a quote' => [
                "> - a\n>   ---\x0B\n",
                "> - a\n>   \\-\\-\\-\x0B\n",
                "<blockquote>\n  <ul>\n    <li>a\n---\x0B</li>\n  </ul>\n</blockquote>\n",
            ],
            'thematic break, vertical tab' => ["p\n\n***\x0B\n\nq\n", "p\n\n***\x0B\n\nq\n", "<p>p</p>\n<p>***\x0B</p>\n<p>q</p>\n"],
            'thematic break, form feed' => ["p\n\n***\f\n\nq\n", "p\n\n***\f\n\nq\n", "<p>p</p>\n<p>***\f</p>\n<p>q</p>\n"],
            'backtick fence closer' => [
                "```\nx\n```\x0B\ny\n```\n",
                "````\nx\n```\x0B\ny\n````\n",
                "<pre><code>x\n```\x0B\ny\n</code></pre>\n",
            ],
            'tilde fence closer' => ["~~~\nx\n~~~\f\ny\n~~~\n", "```\nx\n~~~\f\ny\n```\n", "<pre><code>x\n~~~\f\ny\n</code></pre>\n"],
            'fence closer in a list item' => [
                "- a\n\n  ```\n  x\n  ```\x0B\n  y\n  ```\n",
                "{loose}\n- a\n\n  ````\n  x\n  ```\x0B\n  y\n  ````\n",
                "<ul>\n  <li><p>a</p>\n    <pre><code>x\n```\x0B\ny\n</code></pre>\n  </li>\n</ul>\n",
            ],
            'bullet marker under a paragraph' => ["p\n-\x0Ba\n", "p\n-\x0Ba\n", "<p>p\n-\x0Ba</p>\n"],
            'plus marker under a paragraph' => ["p\n+\fa\n", "p\n+\fa\n", "<p>p\n+\fa</p>\n"],
            'ordered marker under a paragraph' => ["p\n1.\x0Ba\n", "p\n1.\x0Ba\n", "<p>p\n1.\x0Ba</p>\n"],
            'ATX opener in a quote' => ["> #\x0Ba\nb\n", "> #\x0Ba\n> b\n", "<blockquote><p>#\x0Ba\nb</p></blockquote>\n"],
            'ATX closing sequence' => ["# a #\x0B\n", "# a #\x0B\n", "<section id=\"a\">\n  <h1>a #\x0B</h1>\n</section>\n"],
        ];
    }

    #[DataProvider('contentLines')]
    public function testAMarkerWithAnotherCharacterAfterItIsText(string $markdown, string $written, string $rendered): void
    {
        $carve = (new MarkdownToCarve())->convert($markdown);

        $this->assertSame($written, $carve);
        $this->assertSame($rendered, CarveConverter::create()->convert($carve));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function markersWithSpaceOrTab(): array
    {
        return [
            'setext underline' => ["a\n--- \t\n", "## a\n"],
            'fence closer' => ["```\nx\n``` \t\ny\n", "```\nx\n```\n\ny\n"],
            'bullet marker under a paragraph' => ["p\n-\ta\n", "p\n\n- a\n"],
        ];
    }

    #[DataProvider('markersWithSpaceOrTab')]
    public function testAMarkerFollowedBySpacesOrTabsStillCounts(string $markdown, string $written): void
    {
        $this->assertSame($written, (new MarkdownToCarve())->convert($markdown));
    }
}
