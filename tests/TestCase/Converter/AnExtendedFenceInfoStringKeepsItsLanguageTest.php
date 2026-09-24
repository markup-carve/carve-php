<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Carve reads a single language token after a fence, so an extended Markdown
 * info string is reduced to its first token and stays a code block (#2128).
 */
class AnExtendedFenceInfoStringKeepsItsLanguageTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string}>
     */
    public static function shapes(): array
    {
        return [
            'at the top level' => [
                "```js title=x\na\n```",
                "```js\na\n```",
                'js',
            ],
            'with a space before the language' => [
                "``` js title=x\na\n```",
                "```js\na\n```",
                'js',
            ],
            'with a quoted attribute' => [
                "```js title=\"x\"\na\n```",
                "```js\na\n```",
                'js',
            ],
            'with only whitespace after the language' => [
                "```js \t \na\n```",
                "```js\na\n```",
                'js',
            ],
            'keeping a language with symbols' => [
                "```c++ x\na\n```",
                "```c++\na\n```",
                'c++',
            ],
            'on a tilde fence' => [
                "~~~js title=x\na\n~~~",
                "```js\na\n```",
                'js',
            ],
            'in a list item after its text' => [
                "- b\n  ```js title=x\n  a\n  ```",
                "- b\n  ```js\n  a\n  ```",
                'js',
            ],
            'on a list item line' => [
                "- ```js title=x\n  a\n  ```",
                "- ```js\n  a\n  ```",
                'js',
            ],
            'on a nested list item line' => [
                "1. a\n   - ```js title=x\n     a\n     ```",
                "1. a\n   - ```js\n     a\n     ```",
                'js',
            ],
            'with only whitespace after the language on an item line' => [
                "- ~~~js  \n  a\n  ~~~",
                "- ```js\n  a\n  ```",
                'js',
            ],
            'in a quote' => [
                "> ```js title=x\n> a\n> ```",
                "> ```js\n> a\n> ```",
                'js',
            ],
            'on a tilde fence in a quote' => [
                "> ~~~js title=x\n> a\n> ~~~",
                "> ```js\n> a\n> ```",
                'js',
            ],
        ];
    }

    #[DataProvider('shapes')]
    public function testTheOpenerKeepsOnlyTheLanguage(string $markdown, string $carve, string $language): void
    {
        $this->assertSame($carve, rtrim((new MarkdownToCarve())->convert($markdown), "\n"));
    }

    #[DataProvider('shapes')]
    public function testTheImportRendersAsACodeBlock(string $markdown, string $carve, string $language): void
    {
        $html = (new CarveConverter())->convert((new MarkdownToCarve())->convert($markdown));

        $this->assertStringContainsString('<pre><code class="language-' . $language . '">a' . "\n</code></pre>", $html);
        $this->assertStringNotContainsString('title', $html);
    }
}
