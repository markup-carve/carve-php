<?php

declare(strict_types=1);

namespace MarkupCarve\Carve2\Test\Render;

use MarkupCarve\Carve2\Carve2;
use MarkupCarve\Carve2\Html\HtmlRenderer;
use MarkupCarve\Carve2\Json\TreeError;
use MarkupCarve\Carve2\Json\TreeReader;
use MarkupCarve\Carve2\Node;
use MarkupCarve\Carve2\Options;

/**
 * Output form, attributes and the block and inline elements of C2-H.
 */
final class HtmlOutputTest extends RenderTestCase
{
    public function testC2H168StaticCoreMathKeepsMathSpan(): void
    {
        self::assertSame(
            '<p><span class="math inline" role="math">\\(x\\)</span></p>',
            Carve2::toHtml('$`x`', ['mode' => 'static']),
        );
        self::assertSame(
            '<ul>' . "\n" . '  <li><span class="math display" role="math">\\[x\\]</span></li>' . "\n" . '</ul>',
            Carve2::toHtml('- $$`x`', ['mode' => 'static']),
        );
    }

    public function testC2H015AlignIsFoldedOnLineBlock(): void
    {
        self::assertSame(
            '<div class="line-block" style="text-align: center;">' . "\n" . '  <p>a</p>' . "\n" . '</div>',
            Carve2::toHtml("{align=' CENTER '}\n::: |\na\n:::"),
        );
    }

    public function testC2H058AuthoredCellSpansAreNotWritten(): void
    {
        self::assertStringNotContainsString('rowspan=', Carve2::toHtml('|{rowspan=2} a | b |'));
        self::assertStringNotContainsString('ColSpan=', Carve2::toHtml('|{ColSpan=3} a | b |'));
    }

    public function testC2H075SemanticSpanKeysUseExactLowercase(): void
    {
        self::assertSame('<p><span KBD="">x</span></p>', Carve2::toHtml('[x]{KBD}'));
        self::assertSame('<p><time>x</time></p>', Carve2::toHtml('[x]{time=""}'));
    }

    public function testC2H152ColumnWidthKeepsSignificantDecimalDigits(): void
    {
        self::assertStringContainsString(
            '<col style="width: 30.000000000001%;">',
            Carve2::toHtml("{widths=30.000000000001}\n| a |"),
        );
        $tree = self::doc([
            'type' => 'table',
            'columns' => [['type' => 'table_column', 'width' => 1.0e-14]],
            'children' => [
                [

                    'type' => 'table_body',
                    'children' => [
                        [
                            'type' => 'table_row', 'children' => [
                                [
                                    'type' => 'table_cell', 'children' => [
                                        [
                                            'type' => 'cell_paragraph',
                                            'children' => [self::t('a')],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
        self::assertStringContainsString('<col style="width: 0.000000000001%;">', self::html($tree));
    }

    public function testC2H020BlockImageTakesParagraphAttributes(): void
    {
        self::assertSame('<img src="y.png" alt="a" class="c wide">', Carve2::toHtml("{.c}\n![a](y.png){.wide}"));
        self::assertSame('<p class="c">![a][missing]</p>', Carve2::toHtml("{.c}\n![a][missing]"));
    }

    public function testC2H165DegradedLeafUsesPlainText(): void
    {
        self::assertSame('<p># H</p>' . "\n" . '<p>see H b</p>', Carve2::toHtml("# H\n\nsee </#H> b", ['profile' => 'minimal']));
        self::assertSame('<p>x</p>', Carve2::toHtml('`x`{=html}', ['profile' => 'minimal']));
    }

    public function testC2H019ParagraphMadeEmptyByProfileWritesNothing(): void
    {
        self::assertSame('', Carve2::toHtml('[](/u)', ['profile' => 'minimal']));
    }

    public function testC2H100LinkedMentionHasNoStrongElement(): void
    {
        self::assertSame(
            '<p><a class="mention" href="https://x.org/u/ann">@ann</a></p>',
            Carve2::toHtml('@ann', ['social' => ['mentionUrl' => 'https://x.org/u/{name}']]),
        );
    }

    public function testC2H043LineEndUsesOneBreakAfterHardBreak(): void
    {
        self::assertSame(
            '<div class="line-block">' . "\n" . '  <p>a<br>' . "\n" . 'b</p>' . "\n" . '</div>',
            Carve2::toHtml("::: |\na\\\nb\n:::"),
        );
    }

    public function testC2H013ConsumedRelKeyUsesAsciiCase(): void
    {
        self::assertSame('<p><a href="/u">x</a></p>', Carve2::toHtml('[x](/u){REL="javascript:x"}'));
    }

    public function testC2H167RelWordsSplitAtAsciiWhitespace(): void
    {
        self::assertSame('<p><a href="/u">x</a></p>', Carve2::toHtml("[x](/u){rel=\"\t\"}"));
        self::assertSame(
            '<p><a href="/u" rel="nofollow ugc">x</a></p>',
            Carve2::toHtml("[x](/u){rel=\"nofollow\tugc\"}", ['profile' => 'comment']),
        );
    }

    public function testC2H153TableOnlyKeysUseAsciiCase(): void
    {
        self::assertStringStartsWith('<table>', Carve2::toHtml("{WIDTHS=not-a-width BODY-ROWS=bad}\n| a |"));
    }

    public function testC2H126SocialResolverGetsContextAndIsFinal(): void
    {
        $seen = [];
        $html = Carve2::toHtml('@ann', [

            'social' => [
                'mentionUrl' => '/fallback/{name}',
                'context' => ['source' => 'test'],
                'resolveMention' => static function (array $request) use (&$seen): ?string {
                    $seen = $request;

                    return null;
                },
            ],
        ]);
        self::assertSame('<p><span class="mention"><strong>@ann</strong></span></p>', $html);
        self::assertSame(['kind' => 'mention', 'name' => 'ann', 'context' => ['source' => 'test']], $seen);
    }

    public function testC2H176InvalidSuppliedMentionNameIsReplacedInUrl(): void
    {
        $mention = new Node('mention');
        $mention->f = ['target' => "\xED\xA0\x80", 'label' => 'ok'];
        $paragraph = new Node('paragraph', [$mention]);
        $doc = new Node('document', [$paragraph]);
        self::assertSame(
            '<p><a class="mention" href="/u/%EF%BF%BD">ok</a></p>',
            HtmlRenderer::render($doc, Options::from(['social' => ['mentionUrl' => '/u/{name}']])),
        );
    }

    /**
     * @return void
     */
    public function testC2H001FragmentWithoutFinalLineEnding(): void
    {
        $this->assertSame("<p>a</p>\n<p>b</p>", self::html(self::doc(self::p(self::t('a')), self::p(self::t('b')))));
    }

    /**
     * @return void
     */
    public function testC2H002TextEscapes(): void
    {
        $this->assertSame('<p>&amp; &lt;b&gt; " \' a&nbsp;b</p>', self::html(self::doc(self::p(self::t("& <b> \" ' a\u{00A0}b")))));
    }

    /**
     * @return void
     */
    public function testC2H003AttributeEscapes(): void
    {
        $p = self::p(self::t('x'));
        $p['attrs'] = ['keyValues' => [['k', "&<>\"' \u{00A0}"]]];
        $this->assertSame("<p k=\"&amp;&lt;&gt;&quot;&apos; \u{00A0}\">x</p>", self::html(self::doc($p)));
    }

    /**
     * @return void
     */
    public function testC2H006QuoteWithOneParagraphOnOneLine(): void
    {
        $this->assertSame('<blockquote><p>q</p></blockquote>', self::html(self::doc([

            'type' => 'block_quote',
            'children'
            => [self::p(self::t('q'))],
        ])));
    }

    /**
     * @return void
     */
    public function testC2H008EmptyContainerKeepsOneEmptyLine(): void
    {
        $quote = ['type' => 'block_quote', 'children' => [['type' => 'comment', 'form' => 'line', 'value' => 'note']]];
        $this->assertSame("<div>\n\n</div>\n<blockquote>\n\n</blockquote>", self::html(self::doc([

            'type' => 'div',
            'children' => [],
        ], $quote)));
    }

    /**
     * @return void
     */
    public function testC2H009AttributeOrder(): void
    {
        $span = [
            'type' => 'span',
            'attrs' => ['id' => 'y', 'classes' => ['x'], 'keyValues' => [['z', '1']]],
            'lang' => 'de',
            'children' => [self::t('t')],
        ];
        $this->assertSame('<p><span id="y" class="x" lang="de" z="1">t</span></p>', self::html(self::doc(self::p($span))));
    }

    /**
     * @return void
     */
    public function testC2H013LanguagePartWinsOverLangKey(): void
    {
        $p = self::p(self::t('x'));
        $p['attrs'] = ['keyValues' => [['lang', 'fr']]];
        $p['lang'] = 'de';
        $this->assertSame('<p lang="de">x</p>', self::html(self::doc($p)));
    }

    /**
     * @return void
     */
    public function testC2H015AlignBecomesStyleAfterAuthoredStyle(): void
    {
        $p = self::p(self::t('x'));
        $p['attrs'] = ['keyValues' => [['align', 'center'], ['style', 'color:red'], ['k', 'v']]];
        $this->assertSame('<p style="color:red; text-align: center;" k="v">x</p>', self::html(self::doc($p)));
    }

    /**
     * @return void
     */
    public function testC2H016SourceLines(): void
    {
        $tree = self::doc(['type' => 'paragraph', 'children' => [self::t('a')], 'pos' => self::pos(0, 1)], [
            'type'
            => 'paragraph', 'children' => [self::t('b')],
            'pos' => self::pos(3, 4),
        ]);
        $opts = Options::from(['sourceLines' => true]);
        $html = HtmlRenderer::render(self::node($tree), $opts, "a\n\nb");
        $this->assertSame("<p data-source-line=\"1\">a</p>\n<p data-source-line=\"3\">b</p>", $html);
    }

    /**
     * @return void
     */
    public function testC2H019CommentOnlyParagraphRendersNothing(): void
    {
        $this->assertSame('', self::html(self::doc(self::p(['type' => 'comment', 'form' => 'braced', 'value' => 'x']))));
    }

    /**
     * @return void
     */
    public function testC2H021HeadingAboveLevelSix(): void
    {
        $this->assertSame('<div role="heading" aria-level="7" id="Deep">Deep</div>', self::html(self::doc([

            'type'
            => 'heading', 'level' => 7,
            'children' => [self::t('Deep')],
        ]), ['sections' => false]));
    }

    /**
     * @return void
     */
    public function testC2H026SectionsNestByLevel(): void
    {
        $h = static fn (int $l, string $t): array => ['type' => 'heading', 'level' => $l, 'children' => [self::t($t)]];
        $expected = "<section id=\"A\">\n  <h1>A</h1>\n  <section id=\"B\">\n    <h3>B</h3>\n  <"
            . "/section>\n</section>\n<section id=\"C\">\n  <h1>C</h1>\n</section>";
        $this->assertSame($expected, self::html(self::doc($h(1, 'A'), $h(3, 'B'), $h(1, 'C'))));
    }

    /**
     * @return void
     */
    public function testC2H030OrderedListTypeStartAndDelimiter(): void
    {
        $list = [

            'type' => 'list',
            'marker' => [
                'kind' => 'ordered',
                'style' => 'i',
                'delimiter' => ')',
                'start' => 4,
            ],
            'spacing' => 'tight',
            'children' => [['type' => 'list_item', 'children' => [self::p(self::t('a'))]]],
        ];
        $this->assertSame("<ol type=\"i\" start=\"4\" data-delim=\")\">\n  <li>a</li>\n</ol>", self::html(self::doc($list)));
    }

    /**
     * @return void
     */
    public function testC2H032TaskItem(): void
    {
        $list = [

            'type' => 'list',
            'marker' => [
                'kind' => 'bullet',
                'char' => '-',
            ],
            'spacing' => 'tight',
            'children' => [['type' => 'list_item', 'task' => ['mark' => '>'], 'children' => [self::p(self::t('moved'))]]],
        ];
        $expected = "<ul class=\"task-list\">\n  <li data-task-state=\"&gt;\" class=\"task\" sty"
            . 'le="list-style: none"><input type="checkbox" disabled aria-label="move'
            . "d\"> moved</li>\n</ul>";
        $this->assertSame($expected, self::html(self::doc($list)));
    }

    /**
     * @return void
     */
    public function testC2H034TightListWrapsLaterParagraphs(): void
    {
        $list = [

            'type' => 'list',
            'marker' => [
                'kind' => 'bullet',
                'char' => '-',
            ],
            'spacing' => 'tight',
            'children' => [['type' => 'list_item', 'children' => [self::p(self::t('a')), self::p(self::t('b'))]]],
        ];
        $this->assertSame("<ul>\n  <li>a\n    <p>b</p>\n  </li>\n</ul>", self::html(self::doc($list)));
    }

    /**
     * @return void
     */
    public function testC2H040DescriptionWithSeveralBlocks(): void
    {
        $dl = [

            'type' => 'definition_list',
            'children' => [
                ['type' => 'definition_term', 'children' => [self::t('T')]],
                ['type' => 'definition_description', 'children' => [self::p(self::t('one'))]],
                ['type' => 'definition_description', 'children' => [self::p(self::t('a')), self::p(self::t('b'))]],
            ],
        ];
        $this->assertSame(
            "<dl>\n  <dt>T</dt>\n  <dd>one</dd>\n  <dd>\n    <p>a</p>\n    <p>b</p>\n  </dd>\n</dl>",
            self::html(self::doc($dl)),
        );
    }

    /**
     * @return void
     */
    public function testC2H043LineBlock(): void
    {
        $line = static fn (array ...$c): array => ['type' => 'line', 'children' => $c];
        $lb = ['type' => 'line_block', 'children' => [$line(self::t('a')), $line(self::t('  b')), $line(), $line(self::t('c'))]];
        $this->assertSame("<div class=\"line-block\">\n  <p>a<br>\n&nbsp;&nbsp;b</p>\n  <p>c</p>\n</div>", self::html(self::doc($lb)));
    }

    /**
     * @return void
     */
    public function testC2H047CodeBlockWithTitleAndLanguage(): void
    {
        $code = ['type' => 'code_block', 'value' => "a<b\n", 'syntax' => 'js', 'title' => [self::t('t.js')]];
        $this->assertSame("<pre title=\"t.js\"><code class=\"language-js\">a&lt;b\n</code></pre>", self::html(self::doc($code)));
    }

    /**
     * @return void
     */
    public function testC2H050RawHtmlEscapedByDefault(): void
    {
        $raw = ['type' => 'raw_block', 'format' => 'html', 'value' => "<b>x</b>\n"];
        $this->assertSame("<pre><code class=\"language-html\">&lt;b&gt;x&lt;/b&gt;\n</code></pre>", self::html(self::doc($raw)));
    }

    /**
     * @return void
     */
    public function testC2H053RawHtmlVerbatimAtColumnZero(): void
    {
        $raw = ['type' => 'raw_block', 'format' => 'html', 'value' => "<b>x</b>\n"];
        $quote = ['type' => 'block_quote', 'children' => [$raw, self::p(self::t('q'))]];
        $this->assertSame("<blockquote>\n<b>x</b>\n  <p>q</p>\n</blockquote>", self::html(self::doc($quote), ['rawHtml' => true]));
    }

    /**
     * @return void
     */
    public function testC2H054OtherRawFormatsRenderNothing(): void
    {
        $this->assertSame('<p> y</p>', self::html(self::doc(self::p([

            'type' => 'raw_inline',
            'format' => 'latex',
            'value'
            => '\\x',
        ], self::t(' y')))));
    }

    /**
     * @return void
     */
    public function testC2H058SpanMarksGiveCounts(): void
    {
        $cell = static fn (?string $text, ?string $span = null): array => array_filter([

            'type' => 'table_cell',
            'header' => false,
            'span' => $span,
            'children' => $text === null ? [] : [['type' => 'cell_paragraph', 'children' => [self::t($text)]]],
        ], static fn ($v) => $v !== null);
        $row = static fn (array ...$c): array => ['type' => 'table_row', 'children' => $c];
        $table = [

            'type' => 'table',
            'columns' => [
                [
                    'type' => 'table_column',
                ], ['type' => 'table_column'],
            ],
            'children' => [
                [

                    'type' => 'table_body',
                    'children' => [
                        $row($cell('a'), $cell(null, 'colspan')),
                        $row($cell('b'), $cell('c')),
                        $row($cell(null, 'rowspan'), $cell('d')),
                    ],
                ],
            ],
        ];
        $expected = "<table>\n  <tbody>\n    <tr><td colspan=\"2\">a</td></tr>\n    <tr><td rows"
            . "pan=\"2\">b</td><td>c</td></tr>\n    <tr><td>d</td></tr>\n  </tbody>\n</table>";
        $this->assertSame($expected, self::html(self::doc($table)));
    }

    /**
     * @return void
     */
    public function testC2A093RowSpanAcrossGroupsIsRefused(): void
    {
        $cell = static fn (string $t, array $extra = []): array => [

            'type' => 'table_cell',
            'header' => false,
            'children'
            => [['type' => 'cell_paragraph', 'children' => [self::t($t)]]],
        ] + $extra;
        $table = [

            'type' => 'table',
            'columns' => [['type' => 'table_column']],
            'children' => [
                ['type' => 'table_body', 'children' => [['type' => 'table_row', 'children' => [$cell('a', ['rowspan' => 2])]]]],
                ['type' => 'table_foot', 'children' => [['type' => 'table_row', 'children' => []]]],
            ],
        ];
        $this->expectException(TreeError::class);
        TreeReader::read(json_encode(self::doc($table), JSON_THROW_ON_ERROR));
    }

    /**
     * @return void
     */
    public function testC2H075SemanticSpanKeysNest(): void
    {
        $span = [
            'type' => 'span', 'attrs' => [
                'classes' => ['c'], 'keyValues' => [
                    ['abbr', 'A'], ['time', 'T'], ['kbd', ''],
                    ['title', 'Q'],
                ],
            ], 'children' => [self::t('x')],
        ];
        $this->assertSame(
            '<p><kbd class="c" title="Q"><time datetime="T"><abbr title="A">x</abbr></time></kbd></p>',
            self::html(self::doc(self::p($span))),
        );
    }

    /**
     * @return void
     */
    public function testC2H082DeniedDestinationIsBlank(): void
    {
        $link = ['type' => 'link', 'href' => "java\u{0001}script:x", 'children' => [self::t('a')]];
        $this->assertSame('<p><a href="">a</a></p>', self::html(self::doc(self::p($link))));
    }

    /**
     * @return void
     */
    public function testC2H085UnresolvedReferenceAsText(): void
    {
        $link = [
            'type' => 'link',
            'key' => 'nope',
            'namespace' => 'link',
            'refForm' => 'full', 'attrs' => [
                'id' => 'i',
                'classes' => ['c'],
                'keyValues' => [['k', 'a b']],
            ], 'children' => [self::t('x')],
        ];
        $this->assertSame('<p>[x][nope]{#i .c k="a b"}</p>', self::html(self::doc(self::p($link))));
    }

    /**
     * @return void
     */
    public function testC2H088NoteMarksMoveBehindTheLink(): void
    {
        $tree = self::doc(
            self::p([

                'type' => 'link',
                'href' => '/u',
                'children' => [
                    self::t('see'), [

                        'type' => 'footnote_ref',
                        'key'
                => 'a', 'namespace' => 'footnote',
                    ],
                ],
            ]),
            ['type' => 'footnote', 'key' => 'a', 'namespace' => 'footnote', 'children' => [self::p(self::t('A'))]],
        );
        $this->assertStringStartsWith(
            '<p><a href="/u">see</a><a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a></p>',
            self::html($tree),
        );
    }

    /**
     * @return void
     */
    public function testC2H091SeveralBacklinks(): void
    {
        $ref = ['type' => 'footnote_ref', 'key' => 'n', 'namespace' => 'footnote'];
        $tree = self::doc(self::p(self::t('a'), $ref, self::t('b'), $ref), [

            'type' => 'footnote',
            'key' => 'n',
            'namespace'
            => 'footnote', 'children' => [self::p(self::t('N'))],
        ]);
        $html = self::html($tree);
        $this->assertStringContainsString('<a id="fnref1-2" href="#fn1" role="doc-noteref">', $html);
        $this->assertStringContainsString('<p>N<a href="#fnref1" role="doc-backlink" aria-label="Back to reference 1">'
            . '↩<sup>1</sup></a> <a href="#fnref1-2" role="doc-backlink" aria-label="Back '
            . 'to reference 2">↩<sup>2</sup></a></p>', $html);
    }

    /**
     * @return void
     */
    public function testC2H092UnusedNoteAndC2H094NoSection(): void
    {
        $this->assertSame('<p>a</p>', self::html(self::doc(self::p(self::t('a')), [

            'type' => 'footnote',
            'key' => 'n',
            'namespace' => 'footnote',
            'children' => [self::p(self::t('N'))],
        ])));
    }

    /**
     * @return void
     */
    public function testC2H100SocialTemplates(): void
    {
        $tree = self::doc(self::p(['type' => 'mention', 'kind' => 'user', 'target' => 'ann'], self::t(' '), [

            'type' => 'tag',
            'name' => 't',
        ]));
        $this->assertSame('<p><a class="mention" href="https://x/ann">@ann</a> <span '
            . 'class="tag"><strong>#t</strong></span></p>', self::html($tree, ['social' => ['mentionUrl' => 'https://x/{name}']]));
    }

    /**
     * @return void
     */
    public function testC2H101Symbols(): void
    {
        $tree = self::doc(self::p(['type' => 'symbol', 'name' => 'ok'], self::t(' '), [

            'type' => 'symbol',
            'name' => 'no',
            'attrs' => ['classes' => ['s']],
        ]));
        $this->assertSame('<p><b>✓</b> <span class="s">:no:</span></p>', self::html($tree, ['symbols' => ['ok' => '<b>✓</b>']]));
    }

    /**
     * @return void
     */
    public function testC2H116ReservedNameDroppedWithRoundtripOnOrOff(): void
    {
        $p = self::p(self::t('x'));
        $p['attrs'] = ['keyValues' => [['data-carve-ref', 'r'], ['data-carve-other', '1']]];
        $this->assertSame('<p data-carve-other="1">x</p>', self::html(self::doc($p), ['roundtrip' => true]));
        $this->assertSame('<p data-carve-other="1">x</p>', self::html(self::doc($p)));
    }

    /**
     * @return void
     */
    public function testC2H130StrictLossesGiveNoOutput(): void
    {
        $tree = self::doc(self::p(['type' => 'link', 'href' => 'javascript:x', 'children' => [self::t('a')]]));
        $this->assertSame('', self::html($tree, ['strictLosses' => true]));
    }

    /**
     * @return void
     */
    public function testC2H152Colgroup(): void
    {
        $table = [

            'type' => 'table',
            'columns' => [
                [
                    'type' => 'table_column',
                    'width' => 0.305,
                ], ['type' => 'table_column'],
            ],
            'children' => [
                ['type' => 'table_body', 'children' => [['type' => 'table_row', 'children' => []]]],
            ],
        ];
        $this->assertStringContainsString(
            "<colgroup>\n    <col style=\"width: 30.5%;\">\n    <col>\n  </colgroup>",
            self::html(self::doc($table)),
        );
    }

    /**
     * @return void
     */
    public function testC2H155AdmonitionNames(): void
    {
        $adm = static fn (string $kind, ?string $title, array $attrs = []): array => array_filter([
            'type' => 'admonition',
            'kind' => $kind,
            'title' => $title === null ? null : [self::t($title)],
            'attrs' => $attrs === [] ? null : $attrs,
            'children' => [],
        ], static fn ($v) => $v !== null);
        $tree = self::doc($adm('note', 'T'), $adm('tip', 'U', ['keyValues' => [['aria-label', 'own']]]), $adm('info', null));
        $expected = "<aside class=\"admonition note\" aria-labelledby=\"adm-1\">\n  <p class=\"a"
            . "dmonition-title\" id=\"adm-1\">T</p>\n</aside>\n"
            . "<aside class=\"admonition tip\" aria-label=\"own\">\n  <p class=\"admonition-title\">U</p>\n</aside>\n"
            . "<aside class=\"admonition info\" aria-label=\"Info\">\n\n</aside>";
        $this->assertSame($expected, self::html($tree));
    }

    /**
     * @return void
     */
    public function testC2H161DroppedAndEmptiedAttributes(): void
    {
        $p = self::p(self::t('x'));
        $p['attrs'] = [
            'classes' => ['ok', 'javascript:x'], 'keyValues' => [
                ['title', 'Shell: bash'], ['onclick', 'x()'],
                ['ping', 'a javascript:b'], ['data-x', 'data'],
            ],
        ];
        $this->assertSame('<p class="ok" title="" ping="" data-x="data">x</p>', self::html(self::doc($p)));
    }

    /**
     * @return void
     */
    public function testC2H162StyleWithUrlFunctionIsEmptied(): void
    {
        $p = self::p(self::t('x'));
        $p['attrs'] = ['keyValues' => [['style', 'color: u\\72l(a)']]];
        $this->assertSame('<p style="">x</p>', self::html(self::doc($p)));
    }

    /**
     * @return void
     */
    public function testC2H172BidiControlsNotWrittenInText(): void
    {
        $this->assertSame('<p>ab</p>', self::html(self::doc(self::p(self::t("a\u{202E}b")))));
    }

    /**
     * @return void
     */
    public function testC2H176RejectedCharactersBecomeReplacement(): void
    {
        $this->assertSame(
            "<p>a\u{FFFD}b\u{FFFD}c\u{FFFD}d\fe</p>",
            self::html(self::doc(self::p(self::t("a\u{0001}b\u{0085}c\u{FDD0}d\fe")))),
        );
    }

    /**
     * @return void
     */
    public function testC2H069CommentLineInsideAnInlineContainer(): void
    {
        $ext = [

            'type' => 'inline_extension',
            'name' => 'n',
            'children' => [
                [
                    'type' => 'soft_break',
                ], [

                    'type' => 'comment',
                    'form' => 'line',
                    'value' => '',
                ], ['type' => 'soft_break'],
            ],
        ];
        $this->assertSame("<p><span class=\"ext-n\">\n</span></p>", self::html(self::doc(self::p($ext))));
        $strong = ['type' => 'strong', 'children' => [['type' => 'comment', 'form' => 'line', 'value' => ''], ['type' => 'soft_break']]];
        $this->assertSame("<p><strong>\n</strong></p>", self::html(self::doc(self::p($strong))));
    }

    /**
     * @return void
     */
    public function testC2H171QuotedColsListStaysQuoted(): void
    {
        $inc = ['type' => 'include', 'path' => 't.crv', 'options' => ['cols' => ['Name', 'Value']]];
        $this->assertSame('<p>{{ t.crv @cols:"Name,Value" }}</p>', self::html(self::doc($inc)));
    }

    /**
     * @return void
     */
    public function testC2H158FootnotesDirectiveWithNoNotesIsADiv(): void
    {
        $dir = ['type' => 'directive', 'kind' => 'footnotes', 'children' => []];
        $this->assertSame("<p>a</p>\n<div class=\"footnotes\">\n\n</div>", self::html(self::doc(self::p(self::t('a')), $dir)));
    }

    /**
     * @return void
     */
    public function testC2H141ReferenceKeyTakesItsOwnPlace(): void
    {
        $link = [
            'type' => 'link',
            'key' => 'r',
            'namespace' => 'link',
            'refForm' => 'full',
            'children' => [self::t('b')],
            'attrs' => ['keyValues' => [['wn', ''], ['k', '2']]],
        ];
        $def = [

            'type' => 'link_reference_definition',
            'key' => 'r',
            'namespace' => 'link',
            'href' => '/u',
            'attrs'
            => ['keyValues' => [['k', '1'], ['z', '3']]],
        ];
        $this->assertSame('<p><a href="/u" z="3" wn="" k="2">b</a></p>', self::html(self::doc(self::p($link), $def)));
    }

    /**
     * @return void
     */
    public function testBlockImageStandsOnItsOwnLineInContainers(): void
    {
        $img = ['type' => 'paragraph', 'role' => 'image', 'children' => [['type' => 'image', 'src' => 'a.png', 'alt' => 'a']]];
        $list = [

            'type' => 'list',
            'marker' => [
                'kind' => 'bullet',
                'char' => '-',
            ],
            'spacing' => 'tight',
            'children'
            => [['type' => 'list_item', 'children' => [$img]]],
        ];
        $this->assertSame("<ul>\n  <li>\n    <img src=\"a.png\" alt=\"a\">\n  </li>\n</ul>", self::html(self::doc($list)));
        $quote = ['type' => 'block_quote', 'children' => [$img]];
        $this->assertSame("<blockquote>\n  <img src=\"a.png\" alt=\"a\">\n</blockquote>", self::html(self::doc($quote)));
    }

    /**
     * @return void
     */
    public function testEmptyTableAndTaskBeforeList(): void
    {
        $this->assertSame("<table>\n</table>", self::html(self::doc(['type' => 'table', 'columns' => [], 'children' => []])));
        $inner = [

            'type' => 'list',
            'marker' => [
                'kind' => 'bullet',
                'char' => '-',
            ],
            'spacing' => 'tight',
            'children'
            => [['type' => 'list_item', 'children' => [self::p(self::t('a'))]]],
        ];
        $item = ['type' => 'list_item', 'task' => ['mark' => 'x'], 'children' => [$inner]];
        $list = ['type' => 'list', 'marker' => ['kind' => 'bullet', 'char' => '-'], 'spacing' => 'tight', 'children' => [$item]];
        $this->assertStringContainsString("<input type=\"checkbox\" checked disabled> \n    <ul>", self::html(self::doc($list)));
    }

    /**
     * @param int $start
     * @param int $end
     *
     * @return array<string, int>
     */
    private static function pos(int $start, int $end): array
    {
        return ['startLine' => 1, 'endLine' => 1, 'startColumn' => 1, 'endColumn' => 1, 'startOffset' => $start, 'endOffset' => $end];
    }
}
