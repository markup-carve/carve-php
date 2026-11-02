<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * markup-carve/carve#4905: CommonMark 0.30/0.31 readings, checked against the
 * HTML commonmark.js 0.31.2 renders for the same input.
 */
final class MarkdownImportFollowsCommonMark031Test extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function writtenCases(): iterable
    {
        yield 'backtick fence' => ["``` language-js\nx\n```", "```js\nx\n```"];
        yield 'tilde fence' => ["~~~language-c++\nx\n~~~", "```c++\nx\n```"];
        yield 'only one prefix is stripped' => ["```language-language-js\nx\n```", "```language-js\nx\n```"];
        yield 'other prefixes stay' => ["```xlanguage-js\nx\n```", "```xlanguage-js\nx\n```"];
        yield 'the prefix is case-sensitive' => ["```LANGUAGE-js\nx\n```", "```LANGUAGE-js\nx\n```"];
        yield 'inside a list item' => ["- ```language-js\n  x\n  ```", "- ```js\n  x\n  ```"];
        yield 'inside a list item in a quote' => ["> - ```language-js\n>   x\n>   ```", "> - ```js\n>   x\n>   ```"];
        yield 'a bare prefix has no language' => ["```language-\nx\n```", "```\nx\n```"];
        yield 'lowercase doctype' => ['<!doctype html>', "```=html\n<!doctype html>\n```"];
        yield 'backslash newline label' => ["[a\\\nb]\n\n[a\\\nb]: /u", "[a\\\nb](/u)"];
        yield 'an escaped backslash is an ordinary label' => ["[a\\\\ b]\n\n[a\\\\ b]: /u", "[a\\\\ b][a\\\\ b]\n\n[a\\\\ b]: /u"];
    }

    #[DataProvider('writtenCases')]
    public function testWritten(string $markdown, string $carve): void
    {
        $this->assertSame($carve, rtrim((new MarkdownToCarve())->convert($markdown), "\n"));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function renderedCases(): iterable
    {
        yield 'fence class' => ["``` language-js\nx\n```", '<pre><code class="language-js">x' . "\n" . '</code></pre>'];
        yield 'doctype interrupts a paragraph' => ["x\n<!doctype html>", "<p>x</p>\n<!doctype html>"];
        yield 'backslash newline keeps the hard break' => ["[a\\\nb]\n\n[a\\\nb]: /u", "<p><a href=\"/u\">a<br>\nb</a></p>"];
        yield 'shortcut over two lines' => ["[a\nb]\n\n[a\nb]: /u", "<p><a href=\"/u\">a\nb</a></p>"];
        yield 'collapsed over two lines' => ["[a\nb][]\n\n[a\nb]: /u", "<p><a href=\"/u\">a\nb</a></p>"];
        yield 'one-line definition, case folded' => ["[a\nb]\n\n[A B]: /u", "<p><a href=\"/u\">a\nb</a></p>"];
        yield 'backslash newline against backslash space' => ["see [a\\\nb] here\n\n[A\\ B]: /u", "<p>see <a href=\"/u\">a<br>\nb</a> here</p>"];
        yield 'literal backslash, collapsed' => ["[a\\ b][]\n\n[a\\ b]: /u", '<p><a href="/u">a\\ b</a></p>'];
        yield 'literal backslash, full' => ["[t][a\\ b]\n\n[a\\ b]: /u", '<p><a href="/u">t</a></p>'];
    }

    #[DataProvider('renderedCases')]
    public function testRendered(string $markdown, string $html): void
    {
        $carve = (new MarkdownToCarve())->convert($markdown);
        $this->assertSame($html, rtrim((new CarveConverter())->convert($carve), "\n"));
    }

    public function testQuoteInsideAListItemIsNotLabelText(): void
    {
        foreach (["- > [a\n  > b]\n\n[a\nb]: /u", "- > [a\n  > b][]\n\n[A B]: /u"] as $markdown) {
            $html = (new CarveConverter())->convert((new MarkdownToCarve())->convert($markdown));
            $this->assertStringContainsString("<a href=\"/u\">a\nb</a>", $html);
        }
    }
}
