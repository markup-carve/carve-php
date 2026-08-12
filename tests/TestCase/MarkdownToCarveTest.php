<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
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
            'leaves ==highlight== literal (not CommonMark or GFM)' => [
                'a ==hot== word',
                'a ==hot== word',
            ],
            'leaves ^superscript^ unchanged' => [
                'x^2^ end',
                'x^2^ end',
            ],
            'leaves bare dollar variable pair literal by default' => [
                'a $x/$y b',
                'a $x/$y b',
            ],
            'leaves paired PHP variables literal by default' => [
                'setzt $sortBy/$sortDirection, Neu',
                'setzt $sortBy/$sortDirection, Neu',
            ],
            'leaves currency range literal by default' => [
                'cost $5 to $9',
                'cost $5 to $9',
            ],
            'leaves shell variable pair literal by default' => [
                '$a and $b',
                '$a and $b',
            ],
            'leaves display math delimiters literal by default' => [
                '$$x$$',
                '$$x$$',
            ],
            'does not treat currency $5 as math' => [
                'costs $5 today',
                'costs $5 today',
            ],
            'does not treat a currency range $5-$10 as math' => [
                'costs $5-$10 today',
                'costs $5-$10 today',
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
            'preserves a list-nested Markdown fence at its content column' => [
                "- item\n  ``` php\n  *a*\n    indented\n  ```",
                "- item\n\n  ```php\n  *a*\n    indented\n  ```",
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
            'converts <mark> to the forced highlight {=x=}' => [
                '<mark>hot</mark>',
                '{=hot=}',
            ],
            'converts <sub> to the forced subscript {,x,} (renders intraword)' => [
                'H<sub>2</sub>O',
                'H{,2,}O',
            ],
            'converts <sup> to the forced superscript {^x^} (renders intraword)' => [
                'x<sup>2</sup>',
                'x{^2^}',
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
            'normalizes tight Markdown blockquote markers to Carve spacing' => [
                ">quote\n>>nested",
                "> quote\n> > nested",
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
            // A fence is re-based to its container's content column. A
            // document-level fence has column 0, so its 1-3 space Markdown slack
            // is stripped; the sample text stays opaque either way.
            'dedents a document-level indented fence to column 0' => [
                "  ```\n  const x = *a* + _b_\n  ```",
                "```\nconst x = *a* + _b_\n```",
            ],
            // An over-indented list fence keeps the item's content column and
            // loses only the slack above it (item content column 2, fence 3).
            'strips only the slack above a list item content column' => [
                "- item\n   ```\n   code\n   ```",
                "- item\n\n  ```\n  code\n  ```",
            ],
            // The task checkbox is content, not marker: the column is 2 (the
            // `- `), so a col-2 fence stays in the item rather than dedenting.
            'measures a task item column by marker width, not the checkbox' => [
                "- [ ] task\n  ```\n  code\n  ```",
                "- [ ] task\n\n  ```\n  code\n  ```",
            ],
            // After a nested child list the fence re-bases to the OUTER item
            // column (2), not to document level.
            're-bases a fence to the parent column after a nested child list' => [
                "- outer\n  - inner\n  ```\n  code\n  ```",
                "- outer\n  - inner\n\n  ```\n  code\n  ```",
            ],
            // A column-0 line with NO blank before it is lazy continuation: the
            // item stays open and the col-2 fence keeps its indent.
            'keeps the item column across a lazy paragraph continuation' => [
                "- item\ncontinued\n  ```\n  code\n  ```",
                "- item\ncontinued\n\n  ```\n  code\n  ```",
            ],
            // A blank before a column-0 line ends the list; the fence is then
            // document-level and dedents to column 0.
            'dedents a fence to column 0 once a blank ends the list' => [
                "- item\n\ntext\n\n  ```\n  code\n  ```",
                "- item\n\ntext\n\n```\ncode\n```",
            ],
            // A block starter (heading) ends the item without a blank, so the
            // later fence is document-level and dedents to column 0.
            'dedents a fence after a block starter ends the list' => [
                "- item\n# heading\n\n  ```\n  code\n  ```",
                "- item\n\n# heading\n\n```\ncode\n```",
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
            'leaves an already well-spaced document structure unchanged' => [
                "# Title\n\nA /para/ here.\n\n- one\n- two\n",
                "# Title\n\nA \/para/ here.\n\n- one\n- two\n",
            ],
        ];
    }

    /**
     * @param string $markdown
     * @param string $expected
     */
    #[DataProvider('mathConversionProvider')]
    public function testConvertsMathWhenEnabled(string $markdown, string $expected): void
    {
        $converter = new MarkdownToCarve(convertMath: true);

        $this->assertSame($expected, $converter->convert($markdown));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function mathConversionProvider(): array
    {
        return [
            'converts inline math $x$ to $`x`' => [
                'a $x$ b',
                'a $`x` b',
            ],
            'converts display math $$x$$ to $$`x`' => [
                '$$x$$',
                '$$`x`',
            ],
            'numeric guard still leaves currency range literal' => [
                'cost $5 to $9',
                'cost $5 to $9',
            ],
            'converts digit-starting math like $2+2$' => [
                'so $2+2$ holds',
                'so $`2+2` holds',
            ],
            'preserves delimiter characters inside a math span' => [
                'eq $*x*$ end',
                'eq $`*x*` end',
            ],
        ];
    }

    /**
     * @param string $markdown
     * @param string $expectedCarve
     * @param string $expectedHtml
     */
    #[DataProvider('plainCarveInlineSyntaxProvider')]
    public function testPlainMarkdownCarveInlineSyntaxRendersLiteral(string $markdown, string $expectedCarve, string $expectedHtml): void
    {
        $carve = $this->converter->convert($markdown);

        $this->assertSame($expectedCarve, $carve);
        $this->assertSame($expectedHtml, trim((new CarveConverter())->convert($carve)));
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function plainCarveInlineSyntaxProvider(): array
    {
        return [
            'escapes slash emphasis literal' => [
                'a /it/ b',
                'a \/it/ b',
                '<p>a /it/ b</p>',
            ],
            'escapes highlight literal' => [
                'a =hl= b',
                'a \=hl= b',
                '<p>a =hl= b</p>',
            ],
            'a paired single tilde is GFM strikethrough, not literal' => [
                'a ~s~ b',
                'a ~s~ b',
                '<p>a <s>s</s> b</p>',
            ],
            'escapes superscript literal' => [
                'a {^y^} b',
                'a \{^y^} b',
                '<p>a {^y^} b</p>',
            ],
            'escapes subscript literal' => [
                'a {,y,} b',
                'a \{,y,} b',
                '<p>a {,y,} b</p>',
            ],
            'escapes inline comment literal' => [
                'a %%c%% b',
                'a \%%c%% b',
                '<p>a %%c%% b</p>',
            ],
            'escapes line comment literal' => [
                '%% line',
                '\%% line',
                '<p>%% line</p>',
            ],
            'escapes braced highlight literal' => [
                'a {=x=} b',
                'a \{\=x=} b',
                '<p>a {=x=} b</p>',
            ],
            'escapes braced insert literal' => [
                'a {+x+} b',
                'a \{+x+} b',
                '<p>a {+x+} b</p>',
            ],
            'escapes braced delete literal' => [
                'a {-x-} b',
                'a \{-x-} b',
                '<p>a {-x-} b</p>',
            ],
            'escapes the brace but keeps the strike inside it' => [
                // GFM reads the tilde pair through the braces, so the braces
                // are the only literal part here.
                'a {~x~} b',
                'a \{~x~} b',
                '<p>a {<s>x</s>} b</p>',
            ],
            'escapes braced emphasis literal' => [
                'a {/x/} b',
                'a \{\/x/} b',
                '<p>a {/x/} b</p>',
            ],
            // One pass escapes only the outer brace and the inner pair would
            // then render as a subscript inside otherwise literal text, so the
            // braced rule repeats until stable.
            'escapes both braces of a nested braced pair' => [
                'nested {^a{,b,}c^} d',
                'nested \{^a\{,b,}c^} d',
                '<p>nested {^a{,b,}c^} d</p>',
            ],
            'escapes each of two braced pairs on one line' => [
                'two {^a^} and {,b,} x',
                'two \{^a^} and \{,b,} x',
                '<p>two {^a^} and {,b,} x</p>',
            ],
            'escapes an emphasis run whose closer is followed by a slash' => [
                'a /it// b',
                'a \/it// b',
                '<p>a /it// b</p>',
            ],
            'escapes emphasis while leaving a neighbouring url alone' => [
                'a /it/ and ftp://h/f',
                'a \/it/ and ftp://h/f',
                '<p>a /it/ and ftp://h/f</p>',
            ],
        ];
    }

    /**
     * @param string $markdown
     */
    #[DataProvider('plainCarveNegativeProvider')]
    public function testDoesNotOverEscapePlainMarkdownText(string $markdown): void
    {
        $this->assertSame($markdown, $this->converter->convert($markdown));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function plainCarveNegativeProvider(): array
    {
        return [
            'path-like slashes' => ['a/b/c'],
            'and-or slashes' => ['and/or'],
            'fraction slashes' => ['1/2'],
            'spaced equals' => ['x = y = z'],
            'approximate tilde' => ['approx ~5'],
            'single percent' => ['a 50% of b'],
            'plain braces' => ['{x}'],
            'plain brackets' => ['[x]'],
            'plain angle brackets' => ['<x>'],
            'plain pipes' => ['|x|'],
            'emoji shortcode' => [':rocket:'],
            'plain dollar math' => ['$x$'],
            'windows-style path' => ['C:/path/to/file'],
            'aspect ratio' => ['ratio 16/9'],
            'slashed flags' => ['flags -x/-y'],
            // Only http/https URLs are protected upstream, so any other scheme
            // reaches the slash rule. Escaping the second slash of `//` would
            // free the first one to open emphasis - `ftp:/\/x/` renders as
            // `ftp:<em>/x</em>`, markup the input never had.
            'ftp url' => ['ftp://x/'],
            'protocol-relative url' => ['//host/path'],
            'git url' => ['git://h/r/'],
            'file url' => ['file:///etc/hosts'],
            'doubled slashes in prose' => ['a //b// c'],
            'attribute-style key value' => ['k {a=b} v'],
            'attribute-style class' => ['c {.cls} d'],
        ];
    }

    /**
     * @param string $markdown
     * @param string $expected
     */
    #[DataProvider('markdownRewriteRegressionProvider')]
    public function testMarkdownRewritesStillWork(string $markdown, string $expected): void
    {
        $this->assertSame($expected, $this->converter->convert($markdown));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function markdownRewriteRegressionProvider(): array
    {
        return [
            'strong asterisks' => ['**b**', '*b*'],
            'strong underscores' => ['__b__', '*b*'],
            'bold italic asterisks' => ['***bi***', '/*bi*/'],
            'underscore emphasis' => ['_em_', '/em/'],
            'asterisk emphasis' => ['*em*', '/em/'],
            'GFM strike' => ['~~s~~', '~s~'],
            'highlight stays literal by default' => ['==h==', '==h=='],
            'HTML sup' => ['<sup>x</sup>', '{^x^}'],
            'HTML sub' => ['<sub>x</sub>', '{,x,}'],
            'HTML mark' => ['<mark>x</mark>', '{=x=}'],
            'HTML ins' => ['<ins>x</ins>', '{+x+}'],
            'HTML del' => ['<del>x</del>', '~x~'],
            'fenced code' => ["```php\n*x*\n```", "```php\n*x*\n```"],
            'inline code' => ['`*x*`', '`*x*`'],
            'links' => ['[*x*](/u)', '[/x/](/u)'],
            'bare URLs' => ['see https://example.com/a_b/c', 'see https://example.com/a_b/c'],
            'reference definitions' => ['[id]: /a_b/c', '[id]: /a_b/c'],
            'GFM tables' => ["| a | b |\n|---|---|\n| c | d |", "|= a |= b |\n| c | d |"],
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

    /**
     * DoS/crash guard: the inline pass uses a NUL-delimited placeholder sentinel
     * (`\x00P<n>\x00`); a NUL byte in the input previously collided with it and
     * crashed the restore loop. NUL is stripped, so conversion must not throw.
     */
    public function testNulByteInputDoesNotCrash(): void
    {
        $result = $this->converter->convert("\x00P0\x00 **bold**");

        $this->assertStringNotContainsString("\x00", $result);
        $this->assertStringContainsString('*bold*', $result);
    }

    public function testForeignCodeFenceCannotMintRawHtmlBlock(): void
    {
        // An untrusted code fence whose info string is `=html` must NOT
        // become a raw-HTML block; it stays an inert, escaped code block.
        foreach (["```=html\n<script>alert(1)</script>\n```\n", "``` =html\n<script>x</script>\n```\n"] as $md) {
            $out = $this->converter->convert($md);
            $html = (new CarveConverter())->convert($out);
            $this->assertStringNotContainsString('<script>', $html);
        }
    }

    public function testTrailingSpacesBecomeACarveHardBreak(): void
    {
        // Trailing spaces mean NOTHING in Carve, so carrying them across
        // dropped the break; the backslash is Carve's spelling for it.
        $out = $this->converter->convert("a  \nb\n");
        $this->assertSame("a\\\nb\n", $out);
        $this->assertStringContainsString('<br>', (new CarveConverter())->convert($out));
    }

    public function testTrailingSpacesAtAParagraphEndAreNotABreak(): void
    {
        // CommonMark has no hard break at a paragraph's end, and a stray
        // backslash there would render as a literal one.
        $html = (new CarveConverter())->convert($this->converter->convert("a  \n\nb\n"));
        $this->assertStringNotContainsString('<br>', $html);
    }

    public function testTrailingSpacesBeforeAHeadingAreNotABreak(): void
    {
        $html = (new CarveConverter())->convert($this->converter->convert("a  \n# H\n"));
        $this->assertStringNotContainsString('<br>', $html);
    }

    public function testIndentedCodeBecomesAFenceSoItStaysCode(): void
    {
        $out = $this->converter->convert("    indented\n    code\n");
        $this->assertSame("```\nindented\ncode\n```\n", $out);
        $this->assertStringContainsString(
            '<pre><code>indented',
            (new CarveConverter())->convert($out),
        );
    }

    public function testIndentedCodeIsNotReadAsMarkup(): void
    {
        // The bug this fixes: as a paragraph, the code's OWN delimiters were
        // rewritten - `*not bold*` migrated to `/not bold/`.
        $out = $this->converter->convert("    let x = *not bold* and _not em_\n");
        $this->assertStringContainsString('*not bold*', $out);
        $this->assertStringContainsString('_not em_', $out);
        $this->assertStringNotContainsString('<strong>', (new CarveConverter())->convert($out));
    }

    public function testIndentedCodeKeepsABlankLineInsideIt(): void
    {
        // A blank line does not end an indented code block in CommonMark;
        // only a less-indented non-blank line does.
        $this->assertSame("```\na\n\nb\n```\n", $this->converter->convert("    a\n\n    b\n"));
    }

    public function testIndentedCodeRemovesExactlyOneIndentStep(): void
    {
        $this->assertSame("```\na\n    b\n```\n", $this->converter->convert("    a\n        b\n"));
    }

    public function testIndentedCodePicksAFenceLongerThanItsBacktickRuns(): void
    {
        $this->assertSame(
            "````\n```\nx\n```\n````\n",
            $this->converter->convert("    ```\n    x\n    ```\n"),
        );
    }

    public function testIndentedCodeEndsAtALessIndentedLine(): void
    {
        // The run stops at the first non-blank line below the indent, and the
        // blank Carve needs after a block is given back to the document.
        $this->assertSame("```\na\n```\n\ntext\n", $this->converter->convert("    a\ntext\n"));
    }

    public function testAnIndentedListContinuationIsNotCode(): void
    {
        // Four spaces under a list item is item content, not a code block: the
        // previous line is not blank, so it never reaches the code branch.
        $html = (new CarveConverter())->convert($this->converter->convert("- a\n    b\n"));
        $this->assertStringNotContainsString('<pre>', $html);
    }

    public function testAPairedSingleTildeIsStrikethrough(): void
    {
        // GFM strikethrough is "a matching pair of one or two tildes", so the
        // single form is struck; it was escaped into literal text before.
        $html = (new CarveConverter())->convert($this->converter->convert("a ~b~ c\n"));
        $this->assertStringContainsString('<s>b</s>', $html);
    }

    public function testAnUnpairedTildeStaysLiteral(): void
    {
        // Literal in GFM and in Carve alike - a lone tilde opens nothing.
        foreach (["a ~ b\n", "a ~b c\n"] as $markdown) {
            $html = (new CarveConverter())->convert($this->converter->convert($markdown));
            $this->assertStringNotContainsString('<s>', $html);
        }
    }

    public function testHighlightIsLiteralByDefaultAndConvertsWhenAsked(): void
    {
        // `==x==` is literal in CommonMark and GFM, so converting it by default
        // invented a highlight the source never had. The flag mirrors
        // `convertMath`, for the flavours that do define it.
        $this->assertSame("a ==b== c\n", $this->converter->convert("a ==b== c\n"));

        $optIn = new MarkdownToCarve(convertHighlight: true);
        $this->assertSame("a =b= c\n", $optIn->convert("a ==b== c\n"));
        $this->assertStringContainsString(
            '<mark>b</mark>',
            (new CarveConverter())->convert($optIn->convert("a ==b== c\n")),
        );
    }

    public function testTheTwoDialectFlagsAreIndependent(): void
    {
        $mathOnly = new MarkdownToCarve(convertMath: true);
        $this->assertSame("a ==b== c\n", $mathOnly->convert("a ==b== c\n"));
    }
}
