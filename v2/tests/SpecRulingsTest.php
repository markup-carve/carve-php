<?php

declare(strict_types=1);

namespace MarkupCarve\Carve2\Test;

use MarkupCarve\Carve2\Carve2;
use MarkupCarve\Carve2\Json\TreeDecoder;
use MarkupCarve\Carve2\Options;
use MarkupCarve\Carve2\Resolve\Resolver;
use PHPUnit\Framework\TestCase;

final class SpecRulingsTest extends TestCase
{
    public function testC2I127Unicode18WordBoundary(): void
    {
        $this->assertSame('<p>𽀀*𰨌*</p>', Carve2::toHtml('𽀀*𰨌*'));
    }

    public function testC2I002LineEndSpacesDropInsideProtectedSpans(): void
    {
        foreach (["`a  \n  b`", "!`a  \n  b`", "$`a  \n  b`", "`a  \n  b`{=html}"] as $src) {
            $tree = json_decode(Carve2::toJson($src), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame("a\nb", $tree['children'][0]['children'][0]['value']);
        }
        $tree = json_decode(Carve2::toJson("a{%x  \n  y%}b"), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame("x\ny", $tree['children'][0]['children'][1]['value']);
    }

    public function testC2I084HeadingLookupUsesPlainText(): void
    {
        $src = "# A bold title\n\n[A *bold* title][] and [x][A {*bold*} title]";
        $html = Carve2::toHtml($src);
        $this->assertStringContainsString('<a href="#A-bold-title">A <strong>bold</strong> title</a>', $html);
        $this->assertStringContainsString('<a href="#A-bold-title">x</a>', $html);
    }

    public function testC2I084BracedHeadingAndShortcutReference(): void
    {
        $src = "# A {*bold*} title\n\n[A *bold* title][] and [A bold title][]";
        $html = Carve2::toHtml($src);
        $this->assertStringContainsString('<a href="#A-bold-title">A <strong>bold</strong> title</a>', $html);
        $this->assertStringContainsString('<a href="#A-bold-title">A bold title</a>', $html);
        $this->assertStringContainsString('<p>[H]</p>', Carve2::toHtml("# H\n\n[H]"));
    }

    public function testC2I084HeadingLookupNeedsNoSource(): void
    {
        $src = "# A bold title\n\n[A *bold* title][]";
        $doc = TreeDecoder::json(Carve2::toJson($src));
        $this->assertNotNull($doc);
        Resolver::resolve($doc, null, Options::from([]));
        $this->assertSame('#A-bold-title', $doc->children[1]->children[0]->f['href']);
    }

    public function testC2A109HeadingIdsAreResolutionResults(): void
    {
        $src = '# Ab';
        $default = json_decode(Carve2::toJson($src), true, 512, JSON_THROW_ON_ERROR);
        $off = json_decode(Carve2::toJson($src, ['headingIds' => 'off']), true, 512, JSON_THROW_ON_ERROR);
        $lower = json_decode(Carve2::toJson($src, ['headingIds' => 'lower']), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('Ab', $default['children'][0]['generatedId']);
        $this->assertArrayNotHasKey('generatedId', $off['children'][0]);
        $this->assertSame('ab', $lower['children'][0]['generatedId']);
    }

    public function testC2H020AttributedImageKeepsParagraph(): void
    {
        $src = "{.c #i}\n![a](x.png)\n\n{:de}\n![b](y.png)\n\n![c](z.png)";
        $this->assertSame("<p id=\"i\" class=\"c\"><img src=\"x.png\" alt=\"a\"></p>\n"
            . "<p lang=\"de\"><img src=\"y.png\" alt=\"b\"></p>\n<img src=\"z.png\" alt=\"c\">", Carve2::toHtml($src));
    }

    public function testC2I117DelimiterIsNoPlusMinusNeighbor(): void
    {
        $this->assertSame('<p><ins>±</ins></p>', Carve2::toHtml('{++-+}'));
        $this->assertSame('<p>a+-+ b</p>', Carve2::toHtml('a+-{%x%}+ b'));
        $this->assertSame('<p>a++- b</p>', Carve2::toHtml('a+{%x%}+- b'));
    }

    public function testC2H126SocialTemplateAndProfile(): void
    {
        $src = '@ann.b and #topic';
        $social = ['mentionUrl' => 'https://x.org/u/{name}'];
        $this->assertSame('<p><a class="mention" href="https://x.org/u/ann.b"><strong>@ann.b</strong></a> and '
            . '<span class="tag"><strong>#topic</strong></span></p>', Carve2::toHtml($src, ['social' => $social]));
        $this->assertSame(
            '<p><span class="mention"><strong>@ann.b</strong></span></p>',
            Carve2::toHtml('@ann.b', ['social' => $social, 'profile' => 'minimal']),
        );
    }

    public function testC2H167EmptySocialResolverFallsBackAndMentionGetsRel(): void
    {
        $social = [
            'mentionUrl' => 'https://x/u/{name}',
            'tagUrl' => 'https://x/t/{name}',
            'resolveMention' => static fn (): string => '',
            'resolveTag' => static fn (): null => null,
        ];
        $this->assertSame(
            '<p><a class="mention" href="https://x/u/ann"><strong>@ann</strong></a> and '
                . '<a class="tag" href="https://x/t/topic"><strong>#topic</strong></a></p>',
            Carve2::toHtml('@ann and #topic', ['social' => $social]),
        );
        $this->assertStringContainsString(
            'href="https://x/u/ann" rel="nofollow ugc"',
            Carve2::toHtml('@ann', ['social' => $social, 'profile' => 'comment']),
        );
    }

    public function testC2B227InvalidTableKeysStayWithoutEffect(): void
    {
        $src = "{aligns=right,x widths=25,,25 footer-rows=two .t}\n| a | b |\n| c | d |";
        $html = Carve2::toHtml($src);
        $this->assertStringStartsWith('<table class="t">', $html);
        $this->assertStringNotContainsString('<colgroup>', $html);
        $this->assertStringNotContainsString('text-align', $html);
        $this->assertStringNotContainsString('aligns=', $html);
        $this->assertStringNotContainsString('<tfoot>', $html);
        $tree = json_decode(Carve2::toJson($src), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(
            [['aligns', 'right,x'], ['widths', '25,,25'], ['footer-rows', 'two']],
            $tree['children'][0]['attrs']['keyValues'],
        );
    }

    public function testC2B211ColumnListIsValidatedAsAWhole(): void
    {
        $src = "{aligns=right,x widths=25,x}\n| a | b |";
        $tree = json_decode(Carve2::toJson($src), true, 512, JSON_THROW_ON_ERROR);
        $table = $tree['children'][0];
        $this->assertSame([['aligns', 'right,x'], ['widths', '25,x']], $table['attrs']['keyValues']);
        $this->assertSame([['type' => 'table_column'], ['type' => 'table_column']], $table['columns']);
        $this->assertStringNotContainsString('<colgroup>', Carve2::toHtml($src));
    }

    public function testC2B210InvalidAndInapplicableCountsStayInAttrs(): void
    {
        $src = "{header-rows=2 footer-rows=x}\n| a | b |\n| c | d |";
        $tree = json_decode(Carve2::toJson($src), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame([['header-rows', '2'], ['footer-rows', 'x']], $tree['children'][0]['attrs']['keyValues']);
        $html = Carve2::toHtml($src);
        $this->assertStringNotContainsString('header-rows=', $html);
        $this->assertStringNotContainsString('footer-rows=', $html);
        $this->assertStringNotContainsString('<thead>', $html);
    }

    public function testC2B226DelimitedTableDropsOneFinalEmptyRow(): void
    {
        $src = "{table}\n```csv\na,b\n\n```";
        $this->assertSame(1, substr_count(Carve2::toHtml($src), '<tr>'));
    }

    public function testC2B218InvalidUtf8UsesMaximalSubparts(): void
    {
        $doc = Carve2::parse("a\xE2\x82" . 'b');
        $this->assertSame('a�b', $doc->children[0]->children[0]->f['value']);
        $tree = json_decode(Carve2::toJson("a\xE2\x82" . 'b'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('a�b', $tree['children'][0]['children'][0]['value']);
        $this->assertSame(4, $tree['children'][0]['pos']['endOffset']);
        $this->assertSame('<p>a�b</p>', Carve2::toHtml("a\xE2\x82" . 'b'));
        foreach (
            [
                "a\xFF" . 'b' => 'a�b',
                "\xED\xA0\x80" => '���',
                "\xC0\xAF" => '��',
                "\xE0\xA0" . 'b' => '�b',
                "\xF0\x90\x80" . 'b' => '�b',
                "a\0b" => 'a�b',
            ] as $bytes => $expected
        ) {
            $this->assertSame('<p>' . $expected . '</p>', Carve2::toHtml($bytes));
        }
    }

    public function testC2B225RawFormatStopsAtInvalidCharacter(): void
    {
        $src = "```=html~\n<b>x</b>\n```";
        $this->assertSame("<pre><code class=\"language-html\">&lt;b&gt;x&lt;/b&gt;\n</code></pre>", Carve2::toHtml($src));
    }

    public function testC2H165DegradedCaptionsKeepNumbers(): void
    {
        $src = "![i](a.png)\n^ Figure #: cap\n\n| t |\n^ Table #: tc";
        $this->assertSame(
            "<p>[img: i]</p>\n<p>Figure 1: cap</p>\n<p>t</p>\n<p>Table 1: tc</p>",
            Carve2::toHtml($src, ['profile' => 'comment']),
        );
    }

    public function testC2A151IncludeOwnsTrailingSpaces(): void
    {
        $tree = json_decode(Carve2::toJson("{{ a.crv }}  \n"), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(13, $tree['children'][0]['pos']['endOffset']);
    }

    public function testC2A153LineCommentOwnsDroppedTrailingSpaces(): void
    {
        foreach (["a %% x  \nb" => 8, 'c %% z  ' => 8] as $src => $end) {
            $tree = json_decode(Carve2::toJson($src), true, 512, JSON_THROW_ON_ERROR);
            $comment = $tree['children'][0]['children'][1];
            $this->assertSame('comment', $comment['type']);
            $this->assertSame($end, $comment['pos']['endOffset']);
        }
    }

    public function testC2A158EmptyAndSpanCellsKeepPositions(): void
    {
        $tree = json_decode(Carve2::toJson("| a | |\n| b | < |"), true, 512, JSON_THROW_ON_ERROR);
        $rows = $tree['children'][0]['children'][0]['children'];
        $this->assertSame(5, $rows[0]['children'][1]['pos']['startOffset']);
        $this->assertSame(6, $rows[0]['children'][1]['pos']['endOffset']);
        $this->assertArrayNotHasKey('pos', $rows[0]['children'][1]['children'][0]);
        $this->assertSame(13, $rows[1]['children'][1]['pos']['startOffset']);
    }

    public function testC2A158CaptionNumberKeepsPosition(): void
    {
        $tree = json_decode(Carve2::toJson("![i](a.png)\n^ Figure #: cap"), true, 512, JSON_THROW_ON_ERROR);
        $number = $tree['children'][0]['caption']['children'][0]['children'][1];
        $this->assertSame(21, $number['pos']['startOffset']);
        $this->assertSame(22, $number['pos']['endOffset']);
    }

    public function testC2A086TrailingTableCommentIsANode(): void
    {
        $src = "| 1 | 2 | %% note\n| 3 | 4 |";
        $tree = json_decode(Carve2::toJson($src), true, 512, JSON_THROW_ON_ERROR);
        $items = $tree['children'][0]['children'][0]['children'];
        $this->assertSame(['table_row', 'comment', 'table_row'], array_column($items, 'type'));
        $this->assertSame(9, $items[0]['pos']['endOffset']);
        $this->assertSame(9, $items[1]['pos']['startOffset']);
        $this->assertSame('note', $items[1]['value']);
    }

    public function testC2B167CommentNeedsWhitespaceAfterTablePipe(): void
    {
        $tree = json_decode(Carve2::toJson('| a | b |%% c'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('paragraph', $tree['children'][0]['type']);
    }

    public function testC2I118OpenDelimiterCountsTowardBracketCap(): void
    {
        foreach ([198 => true, 199 => false] as $depth => $forms) {
            $src = str_repeat('{^', $depth) . '*[x](u) ![y](v)' . str_repeat('^}', $depth);
            $html = Carve2::toHtml($src);
            $this->assertSame($forms, str_contains($html, '<a href="u">x</a>'));
            $this->assertSame($forms, str_contains($html, '<img src="v" alt="y">'));
        }
    }

    public function testC2A158JoinedCellKeepsSinglePieceInlinePosition(): void
    {
        $tree = json_decode(Carve2::toJson("| *a* |\n+ b |"), true, 512, JSON_THROW_ON_ERROR);
        $cell = $tree['children'][0]['children'][0]['children'][0]['children'][0];
        $this->assertArrayNotHasKey('pos', $cell);
        $this->assertSame(2, $cell['children'][0]['children'][0]['pos']['startOffset']);
        $this->assertSame(5, $cell['children'][0]['children'][0]['pos']['endOffset']);
    }

    public function testC2I026CommentIsTransparentToApostropheWord(): void
    {
        $this->assertSame('<p>Qz ‘tisH</p>', Carve2::toHtml("Qz 'tis{%x%}H"));
    }

    public function testC2A154UnclosedInnerDivEndsAtItsLastChild(): void
    {
        $tree = json_decode(Carve2::toJson("::: a\n:::: b\nx\n:::\ny"), true, 512, JSON_THROW_ON_ERROR);
        $inner = $tree['children'][0]['children'][0];
        $this->assertSame(14, $inner['pos']['endOffset']);
    }

    public function testC2A151EmptyQuoteOwnsTrailingSpaces(): void
    {
        $src = '> >   ';
        $tree = json_decode(Carve2::toJson($src), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(strlen($src), $tree['children'][0]['pos']['endOffset']);
    }

    public function testC2H177DegradedHeadingReferenceUsesUnresolvedForm(): void
    {
        $src = "## Sub *em* `code`\n\n[Sub em code][] and </#Sub-em-code>";
        $this->assertSame(
            "<p>## Sub em code</p>\n<p>[Sub em code][] and &lt;/#Sub-em-code&gt;</p>",
            Carve2::toHtml($src, ['profile' => 'comment']),
        );
    }

    public function testC2H064FigureGroupTableIsAPanel(): void
    {
        $src = ":::: figure\n![a](a.png)\n^ First\n\n| t |\n^ Second\n::::";
        $html = Carve2::toHtml($src);
        $this->assertSame(2, substr_count($html, '<figure class="carve-figure-panel">'));
        $this->assertStringContainsString("    <table>\n      <caption>Second</caption>", $html);
    }

    public function testC2H032TaskLabelIncludesMultipleParagraphs(): void
    {
        $src = "- [ ] first *a*\n\n  second\n\n  > quote";
        $this->assertStringContainsString('aria-label="first a second"', Carve2::toHtml($src));
    }

    public function testC2B040UppercaseRomanMarkerNeedsTwoSpaces(): void
    {
        $src = "II. Background\n\nII.  Background";
        $this->assertSame(
            "<p>II. Background</p>\n<ol type=\"I\" start=\"2\">\n  <li>Background</li>\n</ol>",
            Carve2::toHtml($src),
        );
    }

    public function testC2B122TwoSpacesBeforeFenceWordDropsLanguage(): void
    {
        $this->assertSame("<pre><code>text\n</code></pre>", Carve2::toHtml("```  b\ntext\n```"));
    }

    public function testC2B029FenceInTaskItemStripsBodyTab(): void
    {
        $src = "- [ ]  ```\n\tcode\n  ``` a\n- b";
        $tree = json_decode(Carve2::toJson($src), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame("code\n``` a\n", $tree['children'][0]['children'][0]['children'][0]['value']);
    }

    public function testC2B029FenceInsideQuoteInsideItemKeepsBodyIndent(): void
    {
        $this->assertStringContainsString(
            "<pre><code>  code\n</code></pre>",
            Carve2::toHtml("  1. > ```\n   >   code\n   > ```"),
        );
        $this->assertStringContainsString(
            "<pre><code>  code\n</code></pre>",
            Carve2::toHtml("- a\n\n  > ```\n >   code\n > ```"),
        );
        $this->assertStringContainsString(
            "<pre><code>  `x\n</code></pre>",
            Carve2::toHtml("- [x] \t> ```\n      \t>   `x\n      \t> \t```"),
        );
    }

    public function testC2B130LineBlockKeepsSpacesInsideContainers(): void
    {
        foreach (
            [
                "- a\n\n   ::: |\n     x\n   :::",
                "1. a\n\n    ::: |\n      x\n    :::",
                "- a\n\n  ::: b\n    ::: |\n      x\n    :::\n  :::",
                "> - a\n>\n>    ::: |\n>      x\n>    :::",
            ] as $src
        ) {
            $this->assertStringContainsString('<p>&nbsp;&nbsp;x</p>', Carve2::toHtml($src));
        }
    }

    public function testC2A181IncludeRowsAndColsAcceptOutOfShapeValues(): void
    {
        foreach (
            [
                '{{ a.crv @rows }}' => ['rows' => ''],
                '{{ a.crv @rows:x }}' => ['rows' => 'x'],
                '{{ a.crv @cols:"a,,b" }}' => ['cols' => 'a,,b'],
            ] as $src => $expected
        ) {
            $tree = json_decode(Carve2::toJson($src), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame($expected, $tree['children'][0]['options']);
        }
    }

    public function testC2H165DegradedChildlessInlineUsesSourceText(): void
    {
        $src = "![i](a.png)\n^ a `x` b $`m`\n\n> q `c` $`n`\n\np $`k`";
        $this->assertSame(
            "<p>[img: i]</p>\n<p>a x b m</p>\n<p>&gt; q c n</p>\n<p>p $`k`</p>",
            Carve2::toHtml($src, ['profile' => 'minimal']),
        );
    }

    public function testC2H069CommentAtInlineBoundaryKeepsNestedBreak(): void
    {
        $this->assertSame("<p><span class=\"ext-n\">\n</span></p>", Carve2::toHtml(":n[{%%}\n]"));
        $this->assertSame("<p><span class=\"ext-n\">\n</span></p>", Carve2::toHtml(":n[\n{%%}]"));
        $this->assertSame('<p>x</p>', Carve2::toHtml("{%%}\nx"));
        $this->assertSame('<p>x</p>', Carve2::toHtml("x\n{%%}"));
    }

    public function testC2H166DegradedBreaksPrecedeCommentLineRemoval(): void
    {
        $this->assertSame(
            '<p><strong>_<sup>a  b</sup>_</strong></p>',
            Carve2::toHtml("*_{^a\n{%%}\nb^}_*", ['profile' => 'minimal']),
        );
    }

    public function testC2I057BareMarkBeforeBraceBlocksFastPair(): void
    {
        $this->assertSame('<p><strong>}</strong>”*</p>', Carve2::toHtml('*}*"*'));
        $this->assertSame('<p><code>a</code><strong>}</strong>’*</p>', Carve2::toHtml("`a`*}*'*"));
    }

    public function testC2B122InvalidDivInfoStillOpensAnAnonymousDiv(): void
    {
        foreach (['*', '<ul>', '|x', '"unclosed'] as $info) {
            $this->assertSame("<div>\n  <p>x</p>\n</div>", Carve2::toHtml("::: $info\nx\n:::"));
        }
        $this->assertSame("<p>:::note\nx</p>\n<div>\n\n</div>", Carve2::toHtml(":::note\nx\n:::"));
    }

    public function testC2B006TabDoesNotSeparateDivMarkerAndName(): void
    {
        $this->assertSame("<p>:::\tx\ny</p>", Carve2::toHtml(":::\tx\ny"));
        $this->assertSame("<div>\n  <p>y</p>\n</div>", Carve2::toHtml("::: \tx\ny\n:::"));
    }
}
