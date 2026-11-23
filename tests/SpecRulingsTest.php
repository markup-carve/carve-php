<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Generated\Tables;
use MarkupCarve\Carve\Inline\Chars;
use MarkupCarve\Carve\Json\TreeDecoder;
use MarkupCarve\Carve\Options;
use MarkupCarve\Carve\Resolve\Resolver;
use PHPUnit\Framework\TestCase;

final class SpecRulingsTest extends TestCase
{
    public function testC2B202BareIncludePathKeepsTab(): void
    {
        $source = "{{ a\tb @x:2 }}";
        $tree = json_decode(Carve::toJson($source), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('include', $tree['children'][0]['type']);
        $this->assertSame("a\tb", $tree['children'][0]['path']);
        $this->assertSame(['@x:2'], $tree['children'][0]['invalid']);
        $this->assertSame("<p>{{ a\tb @x:2 }}</p>", Carve::toHtml($source));
    }

    public function testC2A144PendingItemPositionContainsItsChild(): void
    {
        $tree = json_decode(Carve::toJson("> - {.a}\n\n"), true, 512, JSON_THROW_ON_ERROR);
        $item = $tree['children'][0]['children'][0]['children'][0];
        $this->assertGreaterThanOrEqual($item['children'][0]['pos']['endOffset'], $item['pos']['endOffset']);
    }

    public function testC2H143InlineFootnoteIsSilentInDegradedBlock(): void
    {
        $this->assertSame('<p># a b</p>', Carve::toHtml('# a^[note] b', ['profile' => 'minimal']));
        $this->assertSame('<p># a x b</p>', Carve::toHtml('# a *x^[note]* b', ['profile' => 'minimal']));
    }

    public function testC2H034FirstDegradedParagraphIsBareInTightItem(): void
    {
        $source = "- | t |\n  ^ cap\n- b";
        $expected = "<ul>\n  <li>t\n    <p>cap</p>\n  </li>\n  <li>b</li>\n</ul>";
        $this->assertSame($expected, Carve::toHtml($source, ['profile' => 'minimal']));
    }

    public function testC2B093GluedCellPrefixMergesClasses(): void
    {
        $source = '|{.a}{.b} x |';
        $tree = json_decode(Carve::toJson($source), true, 512, JSON_THROW_ON_ERROR);
        $cell = $tree['children'][0]['children'][0]['children'][0]['children'][0];
        $this->assertSame(['a', 'b'], $cell['attrs']['classes']);
        $this->assertStringContainsString('<td class="a b">x</td>', Carve::toHtml($source));
    }

    public function testC2H174TaskBoxWithoutDirectTextHasNoLabel(): void
    {
        foreach (['. [x] . x', '- [ ] - nested', '1. [!] > quote'] as $source) {
            $this->assertStringNotContainsString(' aria-label=', Carve::toHtml($source), $source);
        }
    }

    public function testC2I013LinkDestinationEscapes(): void
    {
        foreach (
            [
                '[a]: \\\\' => '\\',
                '[a]: /x\\)' => '/x)',
                '[x](/u/\\\\)' => '/u/\\',
            ] as $source => $href
        ) {
            $tree = json_decode(Carve::toJson($source), true, 512, JSON_THROW_ON_ERROR);
            $node = $tree['children'][0];
            if ($node['type'] === 'paragraph') {
                $node = $node['children'][0];
            }
            $this->assertSame($href, $node['href'], $source);
        }
    }

    public function testC2A032KeyValuesAreOrderedPairs(): void
    {
        $tree = json_decode(Carve::toJson("{k=v q=z}\nText"), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame([['k', 'v'], ['q', 'z']], $tree['children'][0]['attrs']['keyValues']);
    }

    public function testC2H064TablePanelKeepsTableAttributes(): void
    {
        $html = Carve::toHtml("::: figure\n{#t}\n| a |\n^ Cap\n:::", ['sourceLines' => true]);
        $this->assertStringContainsString('<figure class="carve-figure-panel" data-source-line="2">', $html);
        $this->assertStringContainsString('<table id="t" data-source-line="2">', $html);
        $this->assertSame(1, substr_count($html, 'id="t"'));
    }

    public function testC2I127Unicode18WordBoundary(): void
    {
        $this->assertSame('<p>𽀀*𰨌*</p>', Carve::toHtml('𽀀*𰨌*'));
        $this->assertTrue(Chars::isWord(0x3D000));
        $this->assertTrue(Chars::inRanges(0x3D000, Tables::UNICODE_WORD_RANGES));
    }

    public function testC2A158PipeGroupSpansContinuationLine(): void
    {
        $tree = json_decode(Carve::toJson("| 1 | 2 |\n+ a | b | c |"), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(23, $tree['children'][0]['children'][0]['pos']['endOffset']);
        $this->assertArrayNotHasKey('pos', $tree['children'][0]['children'][0]['children'][0]);
    }

    public function testC2A151TableRowOwnsTrailingSpaces(): void
    {
        $tree = json_decode(Carve::toJson("| a |  \n| b |"), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(7, $tree['children'][0]['children'][0]['children'][0]['pos']['endOffset']);
    }

    public function testC2B093GluedTableRowAttributesMerge(): void
    {
        $tree = json_decode(Carve::toJson('| a |{.x}{#i}'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('table', $tree['children'][0]['type']);
        $this->assertSame('i', $tree['children'][0]['children'][0]['children'][0]['attrs']['id']);
        $this->assertSame(['x'], $tree['children'][0]['children'][0]['children'][0]['attrs']['classes']);
    }

    public function testC2B175ContinuationMustEndWithPipe(): void
    {
        $tree = json_decode(Carve::toJson("| a |\n+ |{x}"), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['table', 'paragraph'], array_column($tree['children'], 'type'));
    }

    public function testC2B166CommentPipeCannotFollowClosingPipe(): void
    {
        $tree = json_decode(Carve::toJson('|| %%`|`'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('paragraph', $tree['children'][0]['type']);
    }

    public function testC2B119TabDoesNotSeparateDivInfo(): void
    {
        $tree = json_decode(Carve::toJson(":::\tx"), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('paragraph', $tree['children'][0]['type']);
    }

    public function testC2B084AbbreviationSeparatorIsOneSpace(): void
    {
        $one = json_decode(Carve::toJson('*[B]: lead'), true, 512, JSON_THROW_ON_ERROR);
        $two = json_decode(Carve::toJson('*[B]:  lead'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('abbreviation_def', $one['children'][0]['type']);
        $this->assertSame('abbreviation_def', $two['children'][0]['type']);
        $this->assertSame(' lead', $two['children'][0]['expansion']);
    }

    public function testC2B092PendingAttributeAtLostItemBecomesParagraph(): void
    {
        $tree = json_decode(Carve::toJson("- {id='a\"b&c'}\n# H"), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['list', 'heading'], array_column($tree['children'], 'type'));
        $this->assertSame("{id='a\"b&c'}", $tree['children'][0]['children'][0]['children'][0]['children'][0]['value']);
    }

    public function testC2I002LineEndSpacesDropInsideProtectedSpans(): void
    {
        foreach (["`a  \n  b`", "!`a  \n  b`", "$`a  \n  b`", "`a  \n  b`{=html}"] as $src) {
            $tree = json_decode(Carve::toJson($src), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame("a\nb", $tree['children'][0]['children'][0]['value']);
        }
        $tree = json_decode(Carve::toJson("a{%x  \n  y%}b"), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame("x\ny", $tree['children'][0]['children'][1]['value']);
    }

    public function testC2I084HeadingLookupUsesPlainText(): void
    {
        $src = "# A bold title\n\n[A *bold* title][] and [x][A {*bold*} title]";
        $html = Carve::toHtml($src);
        $this->assertStringContainsString('<a href="#A-bold-title">A <strong>bold</strong> title</a>', $html);
        $this->assertStringContainsString('<a href="#A-bold-title">x</a>', $html);
    }

    public function testC2I084BracedHeadingAndShortcutReference(): void
    {
        $src = "# A {*bold*} title\n\n[A *bold* title][] and [A bold title][]";
        $html = Carve::toHtml($src);
        $this->assertStringContainsString('<a href="#A-bold-title">A <strong>bold</strong> title</a>', $html);
        $this->assertStringContainsString('<a href="#A-bold-title">A bold title</a>', $html);
        $this->assertStringContainsString('<p>[H]</p>', Carve::toHtml("# H\n\n[H]"));
    }

    public function testC2I084HeadingLookupNeedsNoSource(): void
    {
        $src = "# A bold title\n\n[A *bold* title][]";
        $doc = TreeDecoder::json(Carve::toJson($src));
        $this->assertNotNull($doc);
        Resolver::resolve($doc, null, Options::from([]));
        $this->assertSame('#A-bold-title', $doc->children[1]->children[0]->f['href']);
    }

    public function testC2A109HeadingIdsAreResolutionResults(): void
    {
        $src = '# Ab';
        $default = json_decode(Carve::toJson($src), true, 512, JSON_THROW_ON_ERROR);
        $off = json_decode(Carve::toJson($src, ['headingIds' => 'off']), true, 512, JSON_THROW_ON_ERROR);
        $lower = json_decode(Carve::toJson($src, ['headingIds' => 'lower']), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('Ab', $default['children'][0]['generatedId']);
        $this->assertArrayNotHasKey('generatedId', $off['children'][0]);
        $this->assertSame('ab', $lower['children'][0]['generatedId']);
    }

    public function testC2H020AttributedImagePromotesParagraph(): void
    {
        $src = "{.c #i}\n![a](x.png)\n\n{:de}\n![b](y.png)\n\n![c](z.png)";
        $this->assertSame("<img src=\"x.png\" alt=\"a\" id=\"i\" class=\"c\">\n"
            . "<img src=\"y.png\" alt=\"b\" lang=\"de\">\n<img src=\"z.png\" alt=\"c\">", Carve::toHtml($src));
    }

    public function testC2I117DelimiterIsNoPlusMinusNeighbor(): void
    {
        $this->assertSame('<p><ins>±</ins></p>', Carve::toHtml('{++-+}'));
        $this->assertSame('<p>a+-+ b</p>', Carve::toHtml('a+-{%x%}+ b'));
        $this->assertSame('<p>a++- b</p>', Carve::toHtml('a+{%x%}+- b'));
    }

    public function testC2H126SocialTemplateAndProfile(): void
    {
        $src = '@ann.b and #topic';
        $social = ['mentionUrl' => 'https://x.org/u/{name}'];
        $this->assertSame('<p><a class="mention" href="https://x.org/u/ann.b">@ann.b</a> and '
            . '<span class="tag"><strong>#topic</strong></span></p>', Carve::toHtml($src, ['social' => $social]));
        $this->assertSame(
            '<p><span class="mention"><strong>@ann.b</strong></span></p>',
            Carve::toHtml('@ann.b', ['social' => $social, 'profile' => 'minimal']),
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
            '<p><span class="mention"><strong>@ann</strong></span> and '
                . '<span class="tag"><strong>#topic</strong></span></p>',
            Carve::toHtml('@ann and #topic', ['social' => $social]),
        );
        $this->assertStringContainsString(
            '<span class="mention"><strong>@ann</strong></span>',
            Carve::toHtml('@ann', ['social' => $social, 'profile' => 'comment']),
        );
    }

    public function testC2B227InvalidTableKeysStayWithoutEffect(): void
    {
        $src = "{aligns=right,x widths=25,,25 footer-rows=two .t}\n| a | b |\n| c | d |";
        $html = Carve::toHtml($src);
        $this->assertStringStartsWith('<table class="t">', $html);
        $this->assertStringNotContainsString('<colgroup>', $html);
        $this->assertStringNotContainsString('text-align', $html);
        $this->assertStringNotContainsString('aligns=', $html);
        $this->assertStringNotContainsString('<tfoot>', $html);
        $tree = json_decode(Carve::toJson($src), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(
            [['aligns', 'right,x'], ['widths', '25,,25'], ['footer-rows', 'two']],
            $tree['children'][0]['attrs']['keyValues'],
        );
    }

    public function testC2B211ColumnListIsValidatedAsAWhole(): void
    {
        $src = "{aligns=right,x widths=25,x}\n| a | b |";
        $tree = json_decode(Carve::toJson($src), true, 512, JSON_THROW_ON_ERROR);
        $table = $tree['children'][0];
        $this->assertSame([['aligns', 'right,x'], ['widths', '25,x']], $table['attrs']['keyValues']);
        $this->assertSame([['type' => 'table_column'], ['type' => 'table_column']], $table['columns']);
        $this->assertStringNotContainsString('<colgroup>', Carve::toHtml($src));
    }

    public function testC2B210InvalidAndInapplicableCountsStayInAttrs(): void
    {
        $src = "{header-rows=2 footer-rows=x}\n| a | b |\n| c | d |";
        $tree = json_decode(Carve::toJson($src), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame([['header-rows', '2'], ['footer-rows', 'x']], $tree['children'][0]['attrs']['keyValues']);
        $html = Carve::toHtml($src);
        $this->assertStringNotContainsString('header-rows=', $html);
        $this->assertStringNotContainsString('footer-rows=', $html);
        $this->assertStringNotContainsString('<thead>', $html);
    }

    public function testC2B226DelimitedTableDropsOneFinalEmptyRow(): void
    {
        $src = "{table}\n```csv\na,b\n\n```";
        $this->assertSame(1, substr_count(Carve::toHtml($src), '<tr>'));
    }

    public function testC2B218InvalidUtf8UsesMaximalSubparts(): void
    {
        $doc = Carve::parse("a\xE2\x82" . 'b');
        $this->assertSame('a�b', $doc->children[0]->children[0]->f['value']);
        $tree = json_decode(Carve::toJson("a\xE2\x82" . 'b'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('a�b', $tree['children'][0]['children'][0]['value']);
        $this->assertSame(4, $tree['children'][0]['pos']['endOffset']);
        $this->assertSame('<p>a�b</p>', Carve::toHtml("a\xE2\x82" . 'b'));
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
            $this->assertSame('<p>' . $expected . '</p>', Carve::toHtml($bytes));
        }
    }

    public function testC2B225RawFormatStopsAtInvalidCharacter(): void
    {
        $src = "```=html~\n<b>x</b>\n```";
        $this->assertSame("<pre><code class=\"language-html\">&lt;b&gt;x&lt;/b&gt;\n</code></pre>", Carve::toHtml($src));
    }

    public function testC2H165DegradedCaptionsKeepNumbers(): void
    {
        $src = "![i](a.png)\n^ Figure #: cap\n\n| t |\n^ Table #: tc";
        $this->assertSame(
            "<p>[img: i]</p>\n<p>Figure 1: cap</p>\n<p>t</p>\n<p>Table 1: tc</p>",
            Carve::toHtml($src, ['profile' => 'comment']),
        );
    }

    public function testC2A151IncludeOwnsTrailingSpaces(): void
    {
        $tree = json_decode(Carve::toJson("{{ a.crv }}  \n"), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(13, $tree['children'][0]['pos']['endOffset']);
    }

    public function testC2A153LineCommentOwnsDroppedTrailingSpaces(): void
    {
        foreach (["a %% x  \nb" => 8, 'c %% z  ' => 8] as $src => $end) {
            $tree = json_decode(Carve::toJson($src), true, 512, JSON_THROW_ON_ERROR);
            $comment = $tree['children'][0]['children'][1];
            $this->assertSame('comment', $comment['type']);
            $this->assertSame($end, $comment['pos']['endOffset']);
        }
    }

    public function testC2A158SpanMarkCellSpansTheTextBetweenItsPipes(): void
    {
        $tree = json_decode(Carve::toJson("| a | |\n| b | < |\n| ^ | c |"), true, 512, JSON_THROW_ON_ERROR);
        $rows = $tree['children'][0]['children'][0]['children'];
        $this->assertSame(5, $rows[0]['children'][1]['pos']['startOffset']);
        $this->assertSame(6, $rows[0]['children'][1]['pos']['endOffset']);
        $this->assertArrayNotHasKey('pos', $rows[0]['children'][1]['children'][0]);
        $this->assertSame([13, 16], [$rows[1]['children'][1]['pos']['startOffset'], $rows[1]['children'][1]['pos']['endOffset']]);
        $this->assertSame([19, 22], [$rows[2]['children'][0]['pos']['startOffset'], $rows[2]['children'][0]['pos']['endOffset']]);
    }

    public function testC2A158EmptyLineOfALineBlockHasNoPosition(): void
    {
        $tree = json_decode(Carve::toJson("::: |\none\n\nthree\n:::"), true, 512, JSON_THROW_ON_ERROR);
        $lines = $tree['children'][0]['children'];
        $this->assertEquals(['type' => 'line', 'children' => []], $lines[1]);
        $this->assertArrayHasKey('pos', $lines[0]);
    }

    public function testC2A088TableBodyHasNoHeadRows(): void
    {
        foreach (["{table header-rows=2}\n```csv\na\nb\nc\n```", "{table header-rows=0}\n```csv\na\n```", "|= a |\n| b |"] as $src) {
            $json = Carve::toJson($src);
            $this->assertStringNotContainsString('headRows', $json, $src);
        }
        $tree = json_decode(Carve::toJson("{table header-rows=2}\n```csv\na\nb\nc\n```"), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['table_head', 'table_body'], array_column($tree['children'][0]['children'], 'type'));
        $this->assertCount(2, $tree['children'][0]['children'][0]['children']);
    }

    public function testC2A095TableColumnHasNoAuthoredField(): void
    {
        $tree = json_decode(Carve::toJson("{aligns=right widths=50}\n| a |"), true, 512, JSON_THROW_ON_ERROR);
        $this->assertEquals(
            ['type' => 'table_column', 'align' => 'right', 'width' => 0.5, 'resolvedAlign' => 'right'],
            $tree['children'][0]['columns'][0],
        );
    }

    public function testC2B133RowCommentMayStartALongerPercentRun(): void
    {
        $tree = json_decode(Carve::toJson("|= A |= B |\n| 1 | %%%\nhidden\n%%%\nafter"), true, 512, JSON_THROW_ON_ERROR);
        $table = $tree['children'][0];
        $this->assertSame(['table_head', 'table_body', 'comment'], array_column($table['children'], 'type'));
        $this->assertSame('%', $table['children'][2]['value']);
        $this->assertSame(['table', 'paragraph', 'comment'], array_column($tree['children'], 'type'));
        $tree = json_decode(Carve::toJson('| a |%%% x'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('paragraph', $tree['children'][0]['type']);
    }

    public function testC2H019HardBreakAndCommentLineRenderNothing(): void
    {
        $this->assertSame("<table>\n  <tbody>\n    <tr><td>b</td></tr>\n  </tbody>\n</table>", Carve::toHtml("\\\n{% c %}\n| b |"));
        $this->assertSame("<p>a<br>\nb</p>", Carve::toHtml("a\\\n{% c %}\nb"));
        $tree = json_decode(Carve::toJson("\\\n{% c %}\n| b |"), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['hard_break', 'comment'], array_column($tree['children'][0]['children'], 'type'));
    }

    public function testC2A158CaptionNumberKeepsPosition(): void
    {
        $tree = json_decode(Carve::toJson("![i](a.png)\n^ Figure #: cap"), true, 512, JSON_THROW_ON_ERROR);
        $number = $tree['children'][0]['caption']['children'][0]['children'][1];
        $this->assertSame(21, $number['pos']['startOffset']);
        $this->assertSame(22, $number['pos']['endOffset']);
    }

    public function testC2A086TrailingTableCommentIsANode(): void
    {
        $src = "| 1 | 2 | %% note\n| 3 | 4 |";
        $tree = json_decode(Carve::toJson($src), true, 512, JSON_THROW_ON_ERROR);
        $items = $tree['children'][0]['children'][0]['children'];
        $this->assertSame(['table_row', 'comment', 'table_row'], array_column($items, 'type'));
        $this->assertSame(9, $items[0]['pos']['endOffset']);
        $this->assertSame(9, $items[1]['pos']['startOffset']);
        $this->assertSame('note', $items[1]['value']);
    }

    public function testC2B167CommentNeedsWhitespaceAfterTablePipe(): void
    {
        $tree = json_decode(Carve::toJson('| a | b |%% c'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('paragraph', $tree['children'][0]['type']);
    }

    public function testC2I118OpenDelimiterCountsTowardBracketCap(): void
    {
        foreach ([198 => true, 199 => false] as $depth => $forms) {
            $src = str_repeat('{^', $depth) . '*[x](u) ![y](v)' . str_repeat('^}', $depth);
            $html = Carve::toHtml($src);
            $this->assertSame($forms, str_contains($html, '<a href="u">x</a>'));
            $this->assertSame($forms, str_contains($html, '<img src="v" alt="y">'));
        }
    }

    public function testC2A158JoinedCellKeepsSinglePieceInlinePosition(): void
    {
        $tree = json_decode(Carve::toJson("| *a* |\n+ b |"), true, 512, JSON_THROW_ON_ERROR);
        $cell = $tree['children'][0]['children'][0]['children'][0]['children'][0];
        $this->assertArrayNotHasKey('pos', $cell);
        $this->assertSame(2, $cell['children'][0]['children'][0]['pos']['startOffset']);
        $this->assertSame(5, $cell['children'][0]['children'][0]['pos']['endOffset']);
    }

    public function testC2I026CommentIsTransparentToApostropheWord(): void
    {
        $this->assertSame('<p>Qz ‘tisH</p>', Carve::toHtml("Qz 'tis{%x%}H"));
    }

    public function testC2A154UnclosedInnerDivEndsAtItsLastChild(): void
    {
        $tree = json_decode(Carve::toJson("::: a\n:::: b\nx\n:::\ny"), true, 512, JSON_THROW_ON_ERROR);
        $inner = $tree['children'][0]['children'][0];
        $this->assertSame(14, $inner['pos']['endOffset']);
    }

    public function testC2A151EmptyQuoteOwnsTrailingSpaces(): void
    {
        $src = '> >   ';
        $tree = json_decode(Carve::toJson($src), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(strlen($src), $tree['children'][0]['pos']['endOffset']);
    }

    public function testC2H177DegradedHeadingReferenceUsesUnresolvedForm(): void
    {
        $src = "## Sub *em* `code`\n\n[Sub em code][] and </#Sub-em-code>";
        $this->assertSame(
            "<p>## Sub em code</p>\n<p>[Sub em code][] and Sub em code</p>",
            Carve::toHtml($src, ['profile' => 'comment']),
        );
    }

    public function testC2H064FigureGroupTableIsAPanel(): void
    {
        $src = ":::: figure\n![a](a.png)\n^ First\n\n| t |\n^ Second\n::::";
        $html = Carve::toHtml($src);
        $this->assertSame(2, substr_count($html, '<figure class="carve-figure-panel">'));
        $this->assertStringContainsString("    <table>\n      <caption>Second</caption>", $html);
    }

    public function testC2H032TaskLabelIncludesMultipleParagraphs(): void
    {
        $src = "- [ ] first *a*\n\n  second\n\n  > quote";
        $this->assertStringContainsString('aria-label="first a second"', Carve::toHtml($src));
    }

    public function testC2B040UppercaseRomanMarkerNeedsTwoSpaces(): void
    {
        $src = "II. Background\n\nII.  Background";
        $this->assertSame(
            "<p>II. Background</p>\n<ol type=\"I\" start=\"2\">\n  <li>Background</li>\n</ol>",
            Carve::toHtml($src),
        );
    }

    public function testC2B122TwoSpacesBeforeFenceWordDropsLanguage(): void
    {
        $this->assertSame("<pre><code>text\n</code></pre>", Carve::toHtml("```  b\ntext\n```"));
    }

    public function testC2B029FenceInTaskItemStripsBodyTab(): void
    {
        $src = "- [ ]  ```\n\tcode\n  ``` a\n- b";
        $tree = json_decode(Carve::toJson($src), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame("code\n``` a\n", $tree['children'][0]['children'][0]['children'][0]['value']);
    }

    public function testC2B029FenceInsideQuoteInsideItemKeepsBodyIndent(): void
    {
        $this->assertStringContainsString(
            "<pre><code>  code\n</code></pre>",
            Carve::toHtml("  1. > ```\n   >   code\n   > ```"),
        );
        $this->assertStringContainsString(
            "<pre><code>  code\n</code></pre>",
            Carve::toHtml("- a\n\n  > ```\n >   code\n > ```"),
        );
        $this->assertStringContainsString(
            "<pre><code>  `x\n</code></pre>",
            Carve::toHtml("- [x] \t> ```\n      \t>   `x\n      \t> \t```"),
        );
    }

    public function testC2B130LineBlockKeepsSpacesInsideContainers(): void
    {
        foreach (
            [
                "- a\n\n   ::: |\n     x\n   :::" => 3,
                "1. a\n\n    ::: |\n      x\n    :::" => 3,
                "- a\n\n  ::: b\n    ::: |\n      x\n    :::\n  :::" => 4,
                "> - a\n>\n>    ::: |\n>      x\n>    :::" => 3,
            ] as $src => $spaces
        ) {
            $this->assertStringContainsString('<p>' . str_repeat('&nbsp;', $spaces) . 'x</p>', Carve::toHtml($src));
        }
    }

    public function testC2A181IncludeRowsAndColsKeepOutOfShapeValuesInvalid(): void
    {
        foreach (
            [
                '{{ a.crv @rows }}' => '@rows',
                '{{ a.crv @rows:x }}' => '@rows:x',
                '{{ a.crv @cols:"a,,b" }}' => '@cols:"a,,b"',
            ] as $src => $expected
        ) {
            $tree = json_decode(Carve::toJson($src), true, 512, JSON_THROW_ON_ERROR);
            $this->assertArrayNotHasKey('options', $tree['children'][0]);
            $this->assertSame([$expected], $tree['children'][0]['invalid']);
        }
    }

    public function testC2H165DegradedChildlessInlineUsesSourceText(): void
    {
        $src = "![i](a.png)\n^ a `x` b $`m`\n\n> q `c` $`n`\n\np $`k`";
        $this->assertSame(
            "<p>[img: i]</p>\n<p>a x b m</p>\n<p>&gt; q c n</p>\n<p>p k</p>",
            Carve::toHtml($src, ['profile' => 'minimal']),
        );
    }

    public function testC2H069CommentAtInlineBoundaryKeepsNestedBreak(): void
    {
        $this->assertSame("<p><span class=\"ext-n\">\n</span></p>", Carve::toHtml(":n[{%%}\n]"));
        $this->assertSame("<p><span class=\"ext-n\">\n</span></p>", Carve::toHtml(":n[\n{%%}]"));
        $this->assertSame('<p>x</p>', Carve::toHtml("{%%}\nx"));
        $this->assertSame('<p>x</p>', Carve::toHtml("x\n{%%}"));
    }

    public function testC2H166InlineBreakKeepsCommentLineRemoval(): void
    {
        $this->assertSame(
            "<p><strong>_<sup>a\nb</sup>_</strong></p>",
            Carve::toHtml("*_{^a\n{%%}\nb^}_*", ['profile' => 'minimal']),
        );
    }

    public function testC2H171EmptyIncludeOptionsUseNoValueSpelling(): void
    {
        $this->assertSame('<p>{{ a.crv @rows }}</p>', Carve::toHtml('{{ a.crv @rows }}'));
        $this->assertSame('<p>{{ a.crv @cols @rows }}</p>', Carve::toHtml('{{ a.crv @cols @rows }}'));
        $this->assertSame('<p>{{ a.crv @rows:"" }}</p>', Carve::toHtml('{{ a.crv @rows:"" }}'));
        $this->assertSame('<p>{{ a.crv @cols:"" }}</p>', Carve::toHtml('{{ a.crv @cols:"" }}'));
    }

    public function testC2I057BareMarkBeforeBraceBlocksFastPair(): void
    {
        $this->assertSame('<p><strong>}</strong>”*</p>', Carve::toHtml('*}*"*'));
        $this->assertSame('<p><code>a</code><strong>}</strong>’*</p>', Carve::toHtml("`a`*}*'*"));
    }

    public function testC2B122InvalidDivInfoStillOpensAnAnonymousDiv(): void
    {
        foreach (['*', '<ul>', '"unclosed'] as $info) {
            $this->assertSame("<div>\n  <p>x</p>\n</div>", Carve::toHtml("::: $info\nx\n:::"));
        }
        $this->assertSame("<div class=\"line-block\">\n  <p>x</p>\n</div>", Carve::toHtml("::: |x\nx\n:::"));
        $this->assertSame("<p>:::note\nx</p>\n<div>\n\n</div>", Carve::toHtml(":::note\nx\n:::"));
    }

    public function testC2B119TabDoesNotSeparateDivMarkerAndName(): void
    {
        $this->assertSame("<p>:::\tx\ny</p>", Carve::toHtml(":::\tx\ny"));
    }

    /**
     * carve#6448: behind the spaces a tab and what follows are further words of an anonymous div (C2-B-119, C2-B-122).
     *
     * @return void
     */
    public function testC2B119TabBehindSpacesOpensAnonymousDiv(): void
    {
        foreach (["::: \tnote", ":::  \tnote", "::: \t\"T\"", "::: \t*", "::::  \tx"] as $opener) {
            $run = substr($opener, 0, strspn($opener, ':'));
            $this->assertSame("<div>\n  <p>x</p>\n</div>", Carve::toHtml("$opener\nx\n$run"), $opener);
        }
        $this->assertSame("<ul>\n  <li>\n    <div>\n      <p>x</p>\n    </div>\n  </li>\n</ul>", Carve::toHtml("- ::: \tnote\n  x\n  :::"));
        $findings = Carve::parseWithDiagnostics("::: \tnote\nx\n:::\n")->diagnostics;
        $this->assertCount(1, $findings);
        $this->assertSame(['fence-title-syntax', 5, 9], [$findings[0]->code, $findings[0]->startOffset, $findings[0]->endOffset]);
        $this->assertStringContainsString('anonymous div opener', $findings[0]->message);
    }

    public function testC2A158LineOfOnlySpacesHasNoPosition(): void
    {
        $tree = json_decode(Carve::toJson("  ::: |\n  one\n    two\n  \n  three\n:::"), true, 512, JSON_THROW_ON_ERROR);
        $this->assertEquals(['type' => 'line', 'children' => []], $tree['children'][0]['children'][2]);
    }

    public function testC2I002TabBeforeDescriptionContentIsIndent(): void
    {
        $this->assertSame("<dl>\n  <dt>t</dt>\n  <dd>x</dd>\n</dl>", Carve::toHtml(":: t\n: \tx"));
        $this->assertSame("<dl>\n  <dt>t</dt>\n  <dd>x\ny</dd>\n</dl>", Carve::toHtml(":: t\n: \tx\n  y"));
    }

    public function testC2H007OnlyABareBlockImageStandsOnItsOwnLine(): void
    {
        $this->assertSame(
            "<blockquote>\n  <img src=\"x.png\" alt=\"a\" class=\"c\">\n</blockquote>",
            Carve::toHtml("> {.c}\n> ![a](x.png)\n> "),
        );
        $this->assertSame("<blockquote>\n  <img src=\"x.png\" alt=\"a\">\n</blockquote>", Carve::toHtml('> ![a](x.png)'));
        $this->assertSame(
            "<ul>\n  <li>\n    <img src=\"x.png\" alt=\"a\" class=\"c\">\n  </li>\n</ul>",
            Carve::toHtml("- {.c}\n  ![a](x.png)"),
        );
    }
}
