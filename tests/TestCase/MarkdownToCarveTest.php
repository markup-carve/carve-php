<?php

declare(strict_types=1);

namespace Carve\Test\TestCase;

use Carve\CarveConverter;
use Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MarkdownToCarveTest extends TestCase
{
    protected MarkdownToCarve $converter;

    protected function setUp(): void
    {
        $this->converter = new MarkdownToCarve();
    }

    /**
     * @param string $markdown
     * @param string $expected
     */
    #[DataProvider('conversionProvider')]
    public function testConvertsMarkdownToCarve(string $markdown, string $expected): void
    {
        $this->assertSame($expected, $this->converter->convert($markdown));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function conversionProvider(): array
    {
        return [
            'converts Markdown emphasis *italic* to Carve /italic/' => [
                'an *italic* word',
                'an /italic/ word',
            ],
            'converts Markdown emphasis _italic_ to Carve /italic/' => [
                'an _italic_ word',
                'an /italic/ word',
            ],
            'converts Markdown strong **bold** to Carve *bold*' => [
                'a **bold** word',
                'a *bold* word',
            ],
            'converts Markdown strong __bold__ to Carve *bold*' => [
                'a __bold__ word',
                'a *bold* word',
            ],
            'converts ***bold italic*** to Carve /*bold italic*/' => [
                'a ***strong em*** word',
                'a /*strong em*/ word',
            ],
            'leaves space-flanked asterisks literal' => [
                '2 * 3 * 4',
                '2 * 3 * 4',
            ],
            'leaves intraword asterisk emphasis literal' => [
                'foo*bar*baz',
                'foo*bar*baz',
            ],
            'converts ___bold italic___ to Carve /*bold italic*/' => [
                'a ___strong em___ word',
                'a /*strong em*/ word',
            ],
            'converts **bold with *italic* inside**' => [
                '**outer *inner* end**',
                '*outer /inner/ end*',
            ],
            'converts emphasis nested inside __strong__' => [
                '__outer _inner_ end__',
                '*outer /inner/ end*',
            ],
            'converts emphasis nested inside ***bold italic***' => [
                '***outer _inner_ end***',
                '/*outer /inner/ end*/',
            ],
            'converts Markdown ~~strike~~ to Carve ~strike~' => [
                'a ~~gone~~ word',
                'a ~gone~ word',
            ],
            'leaves ==highlight== unchanged' => [
                'a ==hot== word',
                'a ==hot== word',
            ],
            'leaves ^superscript^ unchanged' => [
                'x^2^ end',
                'x^2^ end',
            ],
            'converts inline math $x$ to $`x`' => [
                'value $a+b$ here',
                'value $`a+b` here',
            ],
            'converts display math $$x$$ to $$`x`' => [
                '$$a+b$$',
                '$$`a+b`',
            ],
            'does not treat currency $5 as math' => [
                'costs $5 today',
                'costs $5 today',
            ],
            'does not treat a currency range $5-$10 as math' => [
                'costs $5-$10 today',
                'costs $5-$10 today',
            ],
            'converts digit-starting math like $2+2$' => [
                'so $2+2$ holds',
                'so $`2+2` holds',
            ],
            'preserves delimiter characters inside a math span' => [
                'eq $*x*$ end',
                'eq $`*x*` end',
            ],
            'leaves intraword underscores literal' => [
                'foo__bar__baz',
                'foo__bar__baz',
            ],
            'does not rewrite delimiters inside a link destination' => [
                '[docs](/api/_v1_/index)',
                '[docs](/api/_v1_/index)',
            ],
            'percent-encodes parentheses in a link destination' => [
                '[wiki](https://host/Titan_(moon))',
                '[wiki](https://host/Titan_%28moon%29)',
            ],
            'does not rewrite delimiters inside a bare URL' => [
                'see https://example.com/api/_v1_/index here',
                'see https://example.com/api/_v1_/index here',
            ],
            'does not rewrite delimiters inside a reference-link definition' => [
                '[docs]: /api/_v1_/index',
                '[docs]: /api/_v1_/index',
            ],
            'protects a reference definition with no space after the colon' => [
                '[id]:/api/_v1_/index',
                '[id]:/api/_v1_/index',
            ],
            'protects a reference definition whose URL is an http(s) link' => [
                '[id]: https://example.com/_x_',
                '[id]: https://example.com/_x_',
            ],
            'still converts inline markup in a footnote definition body' => [
                '[^n]: an *em* note',
                '[^n]: an /em/ note',
            ],
            'protects the whole reference definition' => [
                '[_id_]: /u "*title*"',
                '[_id_]: /u "*title*"',
            ],
            'does not rewrite a reference label at the use site' => [
                '[link][_id_]',
                '[link][_id_]',
            ],
            'still converts emphasis in link text' => [
                '[*hi*](/u)',
                '[/hi/](/u)',
            ],
            'does not rewrite delimiters inside an autolink' => [
                '<https://example.com/_v1_/index>',
                '<https://example.com/_v1_/index>',
            ],
            'does not convert delimiters inside image alt text' => [
                '![*logo*](/x.png)',
                '![*logo*](/x.png)',
            ],
            'protects image alt text containing nested brackets' => [
                '![*logo* [small]](/x.png)',
                '![*logo* [small]](/x.png)',
            ],
            'keeps a full fence info string and the block as code' => [
                "```js title=\"demo\"\n*a*\n```",
                "```js title=\"demo\"\n*a*\n```",
            ],
            'normalizes a space between fence and language to no-space (canonical)' => [
                "``` php\n*a*\n```",
                "```php\n*a*\n```",
            ],
            'strips only the leading space, keeps the rest of the info' => [
                "``` js title=\"x\"\n*a*\n```",
                "```js title=\"x\"\n*a*\n```",
            ],
            'converts <em>/<i> to /x/' => [
                '<em>a</em> <i>b</i>',
                '/a/ /b/',
            ],
            'converts <strong>/<b> to *x*' => [
                '<strong>a</strong> <b>b</b>',
                '*a* *b*',
            ],
            'converts <mark> to ==x==' => [
                '<mark>hot</mark>',
                '==hot==',
            ],
            'converts <sub> to ,,x,,' => [
                'H<sub>2</sub>O',
                'H,,2,,O',
            ],
            'converts <sup> to ^x^' => [
                'x<sup>2</sup>',
                'x^2^',
            ],
            'converts <del>/<s> to ~x~' => [
                '<del>a</del> <s>b</s>',
                '~a~ ~b~',
            ],
            'converts <code> to `x`' => [
                '<code>f()</code>',
                '`f()`',
            ],
            'does not convert delimiters inside inline code' => [
                'use `a *b* _c_` here',
                'use `a *b* _c_` here',
            ],
            'does not convert inside fenced code blocks' => [
                "```js\nconst x = *a* + _b_\n```",
                "```js\nconst x = *a* + _b_\n```",
            ],
            'inserts a blank line before a heading following text' => [
                "text\n# Heading",
                "text\n\n# Heading",
            ],
            'strips an optional ATX closing marker' => [
                '## Title ##',
                '## Title',
            ],
            'keeps a trailing hash that is not a closing marker' => [
                '# C#',
                '# C#',
            ],
            'converts a setext === heading to an ATX h1' => [
                "Title\n===",
                '# Title',
            ],
            'converts a setext --- heading to an ATX h2' => [
                "Subtitle\n---",
                '## Subtitle',
            ],
            'inserts a blank line after a heading before text' => [
                "# Heading\ntext",
                "# Heading\n\ntext",
            ],
            'inserts a blank line before a top-level list following text' => [
                "text\n- item",
                "text\n\n- item",
            ],
            'inserts a blank line before a 1) ordered list following text' => [
                "text\n1) item",
                "text\n\n1) item",
            ],
            'separates a 1-3 space indented top-level list after text' => [
                "text\n  - item",
                "text\n\n  - item",
            ],
            'preserves indented sibling list items' => [
                "  - one\n  - two",
                "  - one\n  - two",
            ],
            'keeps an indented blockquote inside a list item' => [
                "- item\n  > quote",
                "- item\n  > quote",
            ],
            'does not turn a non-1 ordered continuation into a list' => [
                "Intro\n2024. was busy",
                "Intro\n2024. was busy",
            ],
            'treats a leading-zero 01. marker as start 1' => [
                "Intro\n01. item",
                "Intro\n\n01. item",
            ],
            'inserts a blank line before a blockquote following text' => [
                "text\n> quote",
                "text\n\n> quote",
            ],
            'collapses 3+ consecutive blank lines to 2' => [
                "a\n\n\n\nb",
                "a\n\nb",
            ],
            'preserves a tight nested list' => [
                "- parent\n  - child",
                "- parent\n  - child",
            ],
            'remaps a standalone + bullet list to -' => [
                "+ a\n+ b",
                "- a\n- b",
            ],
            'preserves a standalone * bullet list' => [
                "* a\n* b",
                "* a\n* b",
            ],
            'alternates an adjacent + list off the preceding - list' => [
                "- a\n\n+ b",
                "- a\n\n* b",
            ],
            'flips a colliding marker for + then - back-to-back' => [
                "+ a\n- b",
                "- a\n* b",
            ],
            'does not convert inside an indented fenced code block' => [
                "  ```\n  const x = *a* + _b_\n  ```",
                "  ```\n  const x = *a* + _b_\n  ```",
            ],
            'does not convert inside a multi-backtick code span' => [
                'use ``a `*b*` c`` here',
                'use ``a `*b*` c`` here',
            ],
            'does not close a long fence on a shorter inner run' => [
                "````\n```\n*a* _b_\n````",
                "````\n```\n*a* _b_\n````",
            ],
            'does not close a code span on the suffix of a longer inner run' => [
                '``a ``` *b*``',
                '``a ``` *b*``',
            ],
            'leaves literal placeholder-looking text intact' => [
                'keep P0 and S0 tokens',
                'keep P0 and S0 tokens',
            ],
            'leaves backslash-escaped delimiters literal' => [
                '\*literal\* and \_keep\_',
                '\*literal\* and \_keep\_',
            ],
            'does not convert delimiters inside <code>' => [
                '<code>*x* _y_</code>',
                '`*x* _y_`',
            ],
            'preserves a lazy blockquote continuation' => [
                "> quote\ntext",
                "> quote\ntext",
            ],
            'keeps a full fence info string (Carve PHP accepts c++ etc.)' => [
                "```c++\nx\n```",
                "```c++\nx\n```",
            ],
            'dedents a 1-3 space indented heading to column 1' => [
                '  # Title',
                '# Title',
            ],
            'dedents a 1-3 space indented blockquote to column 1' => [
                '  > quote',
                '> quote',
            ],
            'leaves an already well-spaced document unchanged' => [
                "# Title\n\nA /para/ here.\n\n- one\n- two\n",
                "# Title\n\nA /para/ here.\n\n- one\n- two\n",
            ],
        ];
    }

    public function testEmptyString(): void
    {
        $this->assertSame('', $this->converter->convert(''));
    }

    public function testWhitespaceOnly(): void
    {
        $markdown = "   \n\n   ";

        $this->assertSame($markdown, $this->converter->convert($markdown));
    }

    public function testGfmTableHeaderBecomesCarveHeader(): void
    {
        $carve = $this->converter->convert("| a | b |\n|---|---|\n| c | d |");
        $this->assertSame("|= a |= b |\n| c | d |", trim($carve));
    }

    public function testGfmTableAlignmentBecomesCarveMarkers(): void
    {
        $carve = $this->converter->convert("| Name | Age |\n|:-----|----:|\n| Alice | 28 |");
        $this->assertSame("|=< Name |=> Age |\n| Alice | 28 |", trim($carve));
    }

    public function testTableWithoutSeparatorIsUnchanged(): void
    {
        $md = "| a | b |\n| c | d |";
        $this->assertSame($md, trim($this->converter->convert($md)));
    }

    public function testGfmTableRoundTripsToSameHtml(): void
    {
        $md = "| Name | Age |\n|:-----|----:|\n| Alice | 28 |";
        $carve = $this->converter->convert($md);
        $c = new CarveConverter();
        $this->assertSame(trim($c->convert($md)), trim($c->convert($carve)));
    }
}
