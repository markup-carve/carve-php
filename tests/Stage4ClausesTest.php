<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\TestCase;

final class Stage4ClausesTest extends TestCase
{
    public function testC2I118UnclosedBracketsCountForDelimiterDepth(): void
    {
        foreach (['*s*', '{*s*}', '/e/', '{=h=}', '{+i+}', '{-d-}', '{,s,}', '{_u_}'] as $mark) {
            $source = str_repeat('[', 200) . $mark;
            $this->assertSame('<p>' . $source . '</p>', Carve::toHtml($source), $mark);
        }
        $source = str_repeat('[', 100) . str_repeat('{^', 100) . '*s*' . str_repeat('^}', 100);
        $this->assertStringNotContainsString('<strong>', Carve::toHtml($source));
        $source = str_repeat('[', 199) . '*[w](u)*';
        $this->assertSame('<p>' . str_repeat('[', 198) . '<a href="u">*[w</a>*</p>', Carve::toHtml($source));
    }

    public function testC2I118BareFallbackCountsAndUpdatesTheOpenStacks(): void
    {
        foreach (['*', '/'] as $mark) {
            $html = Carve::toHtml(str_repeat('{^', 199) . '[' . $mark . '}a' . $mark);
            $this->assertStringNotContainsString($mark === '*' ? '<strong>' : '<em>', $html);
        }
        $html = Carve::toHtml(str_repeat('{^', 198) . '[*}a [x](u)');
        $this->assertStringContainsString('<a href="u">*}a [x</a>', $html);
        $html = Carve::toHtml(str_repeat('{^', 199) . '*a*} [x](u)');
        $this->assertStringContainsString('<strong>a</strong>} <a href="u">x</a>', $html);
        $html = Carve::toHtml(str_repeat('{^', 197) . '[*a*b {_c* /d [x](u)');
        $this->assertStringContainsString('<a href="u">*a*b {_c* /d [x</a>', $html);
    }

    public function testC2I118BarePairsStayTextAtCapWithoutBrackets(): void
    {
        foreach (['*a*' => '<strong>', '/a/' => '<em>'] as $mark => $tag) {
            $source = str_repeat('{^', 200) . $mark . str_repeat('^}', 200);
            $this->assertStringNotContainsString($tag, Carve::toHtml($source), $mark);
            $source = str_repeat('{_', 200) . $mark . str_repeat('_}', 200);
            $this->assertStringNotContainsString($tag, Carve::toHtml($source), $mark);
        }
    }

    public function testC2I007BracketContentKeepsItsDelimiterScopeAtTheCap(): void
    {
        $source = str_repeat('{^', 197) . '*a [.*. [x](u) d](v)';
        $html = Carve::toHtml($source);
        $this->assertStringContainsString('<a href="u">.*. [x</a>', $html);
        $this->assertStringNotContainsString('href="v"', $html);
    }

    public function testC2B205RangesCompareArbitraryLengthDecimalNumbers(): void
    {
        foreach (['lines', 'rows', 'cols'] as $key) {
            $bad = '@' . $key . ':99999999999999999999-9999999999999999999';
            $tree = json_decode(Carve::toJson('{{ a.crv ' . $bad . ' }}'), true);
            $this->assertSame([$bad], $tree['children'][0]['invalid']);
            $this->assertArrayNotHasKey('options', $tree['children'][0]);
            $good = '@' . $key . ':9999999999999999999-99999999999999999999';
            $tree = json_decode(Carve::toJson('{{ a.crv ' . $good . ' }}'), true);
            $this->assertSame(substr($good, strlen($key) + 2), $tree['children'][0]['options'][$key]);
        }
    }

    public function testC2A082CaptionLivesOnItsBlock(): void
    {
        foreach (
            [
                "![a](x.png)\n^ Cap" => ['paragraph', 'image'],
                "```\nx\n```\n^ Cap" => ['code_block', null],
                "> x\n^ Cap" => ['block_quote', null],
                "$$`x`\n^ Cap" => ['paragraph', 'math'],
            ] as $source => [$type, $role]
        ) {
            $tree = json_decode(Carve::toJson($source), true);
            $block = $tree['children'][0];
            $this->assertSame($type, $block['type'], $source);
            $this->assertSame($role, $block['role'] ?? null, $source);
            $this->assertSame('caption', $block['caption']['type'] ?? null, $source);
            $this->assertStringContainsString('<figcaption>Cap</figcaption>', Carve::toHtml($source), $source);
        }
    }

    public function testC2H063CaptionAttributesStayOnFigureElement(): void
    {
        foreach (["{#f}\n![a](x.png)\n^ Cap", "{#f}\n```\nx\n```\n^ Cap"] as $source) {
            $html = Carve::toHtml($source, ['sourceLines' => true]);
            $this->assertStringContainsString('<figure id="f" data-source-line="1">', $html);
            if (str_contains($source, '```')) {
                $this->assertStringContainsString('data-source-line="2"', $html);
            }
            $this->assertSame(1, substr_count($html, 'id="f"'));
        }
    }

    public function testC2B174SpanBelowWideHeaderCannotExtendHead(): void
    {
        $html = Carve::toHtml("|= H | < |\n| ^ |= X |");
        $this->assertStringContainsString('<thead>', $html);
        $this->assertStringContainsString('<tbody>', $html);
        $this->assertStringContainsString('<td>^</td>', $html);
    }

    public function testC2B174BlockedFirstColumnIsOneColumnForHeadTest(): void
    {
        $html = Carve::toHtml("|= < |= b |\n| ^ |= c |");
        $this->assertStringContainsString('<th rowspan="2" scope="col">&lt;</th>', $html);
        $this->assertSame(1, substr_count($html, '<thead>'));
        $this->assertStringNotContainsString('<tbody>', $html);
    }

    public function testC2B174HorizontalMarkCannotJoinRowspannedHeader(): void
    {
        $html = Carve::toHtml("|= H |= B |\n| ^ | < |");
        $this->assertStringContainsString('<thead>', $html);
        $this->assertStringContainsString('<tbody>', $html);
        $this->assertStringContainsString('<td colspan="2">^</td>', $html);
    }

    public function testC2B205EmptyQuotedRegionAndCodeAreInvalid(): void
    {
        $source = '{{ a.crv @region:"" @code:\'\' }}';
        $tree = json_decode(Carve::toJson($source), true);
        $include = $tree['children'][0];
        $this->assertSame('include', $include['type']);
        $this->assertArrayNotHasKey('options', $include);
        $this->assertSame(['@region:""', "@code:''"], $include['invalid']);
        $this->assertSame('<p>{{ a.crv @region:"" @code:\'\' }}</p>', Carve::toHtml($source));
    }

    public function testC2H062CaptionedParagraphInContainerKeepsFigure(): void
    {
        foreach (
            [
                "- ![i](a.png)\n  ^ cap",
                "- $$`x`\n  ^ cap",
                "> $$`x`\n> ^ cap",
                "- a\n\n  $$`x`\n  ^ cap",
                "A[^n]\n\n[^n]: $$`x`\n  ^ cap",
            ] as $source
        ) {
            $html = Carve::toHtml($source);
            $this->assertStringContainsString('<figure', $html, $source);
            $this->assertStringContainsString('<figcaption>cap</figcaption>', $html, $source);
        }
    }

    public function testC2B119SigilsWithGluedFurtherWords(): void
    {
        foreach (['|' => 'line_block', '>' => 'block_quote', '\\' => 'div'] as $sigil => $type) {
            foreach ([' ', '  ', '    '] as $spaces) {
                foreach (['a', '#', '{.c}', '[x]', '"T"', '%% c', "\tx", '😀'] as $tail) {
                    $tree = json_decode(Carve::toJson(":::$spaces$sigil$tail\nx\n:::"), true);
                    $this->assertSame($type, $tree['children'][0]['type'], $sigil . $tail);
                }
            }
        }
    }

    public function testC2B119AnonymousFallbackAndGluedRun(): void
    {
        foreach (['*', '{.x}', ')', '%% c'] as $tail) {
            $tree = json_decode(Carve::toJson("::: $tail\nx\n:::"), true);
            $this->assertSame('div', $tree['children'][0]['type'], $tail);
        }
        foreach ([':::note', ':::[x]', ':::*'] as $line) {
            $tree = json_decode(Carve::toJson($line), true);
            $this->assertSame('paragraph', $tree['children'][0]['type'], $line);
        }
    }

    public function testC2B130LineBlockKeepsLeadingWhitespace(): void
    {
        foreach (
            [

                " ::: |\n x\n :::" => ' x',
                " ::: |\n\tx\n :::" => "\tx",
                "- > a\n  > \t::: |\n  > \tx\n  > \t:::" => "\tx",
            ] as $source => $value
        ) {
            $tree = json_decode(Carve::toJson($source), true);
            $line = $this->findType($tree, 'line');
            $this->assertSame($value, $line['children'][0]['value'] ?? null);
        }
    }

    public function testC2B113UnclosedLineBlockDropsTrailingBlankLines(): void
    {
        $tree = json_decode(Carve::toJson("::: |\nRoses are red\n  Violets are blue\n\n"), true);
        $this->assertCount(2, $tree['children'][0]['children']);
    }

    public function testC2B069TabAfterTermMarkIsNotText(): void
    {
        foreach ([":: \tx", "- :: \tx\n- a", "> :: \tx", "::  \t x"] as $source) {
            $tree = json_decode(Carve::toJson($source), true);
            $term = $this->findType($tree, 'definition_term');
            $this->assertSame('x', $term['children'][0]['value'] ?? null, $source);
        }
    }

    public function testC2B137CommentOnlyLineKeepsLazyOwnership(): void
    {
        foreach (["> a\n{.c}\n%% x", "> a\n{.c}\n%% x\nq"] as $source) {
            $tree = json_decode(Carve::toJson($source), true);
            $this->assertCount(1, $tree['children']);
            $this->assertSame('block_quote', $tree['children'][0]['type']);
            $this->assertSame('paragraph', $tree['children'][0]['children'][0]['type']);
        }
    }

    public function testC2B228MarkerClosesBodyThatDoesNotOwnIt(): void
    {
        foreach (
            [
                "Text[^n].\n\n[^n]: note\n  - one\n- x" => ['paragraph', 'footnote', 'list'],
                "Text[^n].\n\n[^n]: note\n  1. one\n2. x" => ['paragraph', 'footnote', 'list'],
                "Text[^n].\n\n[^n]: note\n  - one\n* x" => ['paragraph', 'footnote', 'list'],
                "Text[^n].\n\n[^n]: note\n\n  > - one\n- x" => ['paragraph', 'footnote', 'list'],
                ":: t\n: d\n  - one\n- x" => ['definition_list', 'list'],
            ] as $source => $types
        ) {
            $tree = json_decode(Carve::toJson($source), true);
            $this->assertSame($types, array_column($tree['children'], 'type'), $source);
        }
        $tree = json_decode(Carve::toJson("Text[^n].\n\n[^n]: note\n  - one\nx"), true);
        $this->assertSame(['paragraph', 'footnote'], array_column($tree['children'], 'type'));
        $tree = json_decode(Carve::toJson("> - a\n- b"), true);
        $this->assertSame(['block_quote'], array_column($tree['children'], 'type'));
    }

    public function testC2B229TaskBoxFollowsBareDotMarkerTest(): void
    {
        foreach ([' ', '!', '-', '/', '>', '?', 'X', '_', 'x'] as $state) {
            $source = '. [' . $state . '] . x';
            $tree = json_decode(Carve::toJson($source), true);
            $this->assertSame('list', $tree['children'][0]['type'], $source);
            $this->assertSame('list', $tree['children'][0]['children'][0]['children'][0]['type'], $source);
        }
        $tree = json_decode(Carve::toJson('. . x'), true);
        $this->assertSame('paragraph', $tree['children'][0]['type']);
    }

    public function testC2A073EmptyQuotedFenceTitleHasOneTextNode(): void
    {
        foreach (["```\"\"\ncode\n```", "```python \"\"\ncode\n```"] as $source) {
            $tree = json_decode(Carve::toJson($source), true);
            $this->assertCount(1, $tree['children'][0]['title']);
            $this->assertSame('text', $tree['children'][0]['title'][0]['type']);
            $this->assertSame('', $tree['children'][0]['title'][0]['value']);
            $this->assertArrayHasKey('pos', $tree['children'][0]['title'][0]);
        }
    }

    public function testC2B197InvalidImageFormsDoNotPromoteParagraph(): void
    {
        foreach (
            [
                '![a][ ]', "![a][\t]", '![a][@x]', '![a][^x]', '![a][b %% c]',
                "![a][b\t%% c]", '![a %% b](x)', "![a\t%% b](x)", '![a][ [x] ]', '![ ][]',
            ] as $form
        ) {
            $tree = json_decode(Carve::toJson($form . "\n^ cap"), true);
            $this->assertSame('paragraph', $tree['children'][0]['type'], $form);
            $this->assertCount(1, $tree['children'], $form);
        }
        foreach (['![a][b\\]c]', '![a][ @x]', '![a][]'] as $form) {
            $tree = json_decode(Carve::toJson($form . "\n^ cap"), true);
            $this->assertSame('paragraph', $tree['children'][0]['type'], $form);
            $this->assertSame('image', $tree['children'][0]['role'] ?? null, $form);
            $this->assertSame('caption', $tree['children'][0]['caption']['type'] ?? null, $form);
        }
    }

    public function testC2B084ExpansionKeepsWhitespaceAfterRequiredSpace(): void
    {
        foreach ([' ' => ' Hyper Text', "\t" => "\tHyper Text"] as $space => $value) {
            $tree = json_decode(Carve::toJson("*[HTML]: $space" . "Hyper Text\n\nHTML"), true);
            $this->assertSame($value, $tree['children'][0]['expansion'] ?? null);
        }
    }

    public function testC2H165DegradedChildlessInlineAndComment(): void
    {
        $this->assertSame('<p>a  b $`m</p>', Carve::toHtml('a {#c#} b $`m', ['profile' => 'minimal']));
        $this->assertSame('<p>a  b</p>', Carve::toHtml('a {#c#} b', ['profile' => 'minimal']));
    }

    public function testC2H016KeptNestedItemHasSourceLine(): void
    {
        $html = Carve::toHtml('- - -C2-A-034', ['profile' => 'minimal', 'sourceLines' => true]);
        $this->assertSame(2, substr_count($html, '<li data-source-line="1">'));
    }

    public function testC2H165DegradedNestedParagraphUsesPlainText(): void
    {
        $html = Carve::toHtml('- - a `b` <c:d>', ['profile' => 'minimal']);
        $this->assertSame('a b c:d', trim(strip_tags($html)));
    }

    public function testC2H165DegradedBlockKeepsImageFallback(): void
    {
        $this->assertSame('<p># [img: a]</p>', Carve::toHtml('# ![a](x)', ['profile' => 'minimal']));
        $this->assertSame('<p># [img: a]</p>', Carve::toHtml('# *![a](x)*', ['profile' => 'minimal']));
        $this->assertSame('<p>[img: a]</p>', Carve::toHtml('![a](x)', ['profile' => 'minimal']));
    }

    public function testC2H166AllowedInlineKeepsCodeMarkup(): void
    {
        $this->assertSame(
            '<p><strong><strong><code>  a  </code></strong></strong></p>',
            Carve::toHtml('{*{*`  a  `*}*}', ['profile' => 'minimal']),
        );
        $this->assertSame('<p>m</p>', Carve::toHtml('$$`m`', ['profile' => 'minimal']));
        $this->assertSame('<p>  a  </p>', Carve::toHtml('`  a  `{=html}', ['profile' => 'minimal']));
    }

    public function testC2H166AllowedInlineKeepsAttachedAttributes(): void
    {
        $this->assertSame(
            '<p><strong><strong><code class="c">a</code></strong></strong></p>',
            Carve::toHtml('{*{*`a`{.c}*}*}', ['profile' => 'minimal']),
        );
    }

    public function testC2I118FailedOpenerAtCapIsText(): void
    {
        foreach (['^[^"]', '![^"]', ':n[^"]'] as $form) {
            $tree = json_decode(Carve::toJson(str_repeat('{^', 200) . $form . str_repeat('^}', 200)), true);
            $this->assertNull($this->findType($tree, 'footnote_ref'), $form);
            $this->assertStringContainsString(substr($form, 0, -2), Carve::toHtml(str_repeat('{^', 200) . $form), $form);
        }
    }

    public function testC2I118InlineDepthStartsAtZeroInsideDeepBlocks(): void
    {
        $source = str_repeat('> ', 198) . '- *[x](u)';
        $tree = json_decode(Carve::toJson($source), true);
        $this->assertNotNull($this->findType($tree, 'link'));
        $this->assertNotNull($this->findType($tree, 'list'));
        $tree = json_decode(Carve::toJson(str_repeat('{^', 199) . '^[x]' . str_repeat('^}', 199)), true);
        $this->assertNotNull($this->findType($tree, 'inline_footnote'));
    }

    public function testC2B190BlockDepthLimitCountsOnlyBlocks(): void
    {
        $tree = json_decode(Carve::toJson(str_repeat('> ', 201) . 'x'), true);
        $depth = 0;
        $node = $tree;
        while (($node['children'][0]['type'] ?? null) === 'block_quote') {
            $node = $node['children'][0];
            $depth++;
        }
        $this->assertSame(200, $depth);
        $this->assertSame('> x', $node['children'][0]['children'][0]['value'] ?? null);
        $source = str_repeat('> ', 197) . "- one\n" . str_repeat('> ', 197) . '- two';
        $tree = json_decode(Carve::toJson($source), true);
        $list = $this->findType($tree, 'list');
        $this->assertCount(2, $list['children'] ?? []);
        // carve#6610 (ruled B): the sibling item would be block 201, so the marker is text of a lazy line.
        $source = str_repeat('> ', 198) . "- one\n" . str_repeat('> ', 198) . '- two';
        $tree = json_decode(Carve::toJson($source), true);
        $list = $this->findType($tree, 'list');
        $this->assertCount(1, $list['children'] ?? []);
        $this->assertStringContainsString('- two', Carve::toHtml($source));
    }

    public function testC2B209DeclaredHeadRowsClampToAvailableRows(): void
    {
        foreach ([2, 5, 50] as $declared) {
            $source = "{table header-rows=$declared}\n```csv\na,b\nc,d\n```";
            $tree = json_decode(Carve::toJson($source), true);
            $this->assertSame(['table_head'], array_column($tree['children'][0]['children'], 'type'));
            $this->assertCount(2, $tree['children'][0]['children'][0]['children']);
        }
        $tree = json_decode(Carve::toJson("{table header-rows=1 footer-rows=5}\n```csv\na\nb\nc\n```"), true);
        $this->assertSame(['table_head', 'table_foot'], array_column($tree['children'][0]['children'], 'type'));
        $this->assertCount(2, $tree['children'][0]['children'][1]['children']);
        $tree = json_decode(Carve::toJson("{table header-rows=2 footer-rows=2}\n```csv\na\nb\nc\n```"), true);
        $this->assertSame(['table_head', 'table_foot'], array_column($tree['children'][0]['children'], 'type'));
        $this->assertCount(1, $tree['children'][0]['children'][1]['children']);
    }

    public function testC2I017UnclosedBacktickRunsRemainText(): void
    {
        foreach (['`', '!`', '$`', '$$`'] as $opener) {
            $source = 'see ' . $opener . 'unclosed *x* and [y](/u)';
            $tree = json_decode(Carve::toJson($source), true);
            $this->assertNull($this->findType($tree, 'code'), $opener);
            $this->assertNull($this->findType($tree, 'math'), $opener);
            $this->assertNull($this->findType($tree, 'literal_inline'), $opener);
            $this->assertNotNull($this->findType($tree, 'strong'), $opener);
            $this->assertNotNull($this->findType($tree, 'link'), $opener);
            $this->assertStringContainsString($opener . 'unclosed <strong>x</strong>', Carve::toHtml($source), $opener);
        }
        $this->assertSame('<p>`a <code>b</code></p>', Carve::toHtml('`a ``b``'));
    }

    public function testC2I104QuotesUseOnlyTheirNeighbors(): void
    {
        foreach (
            [
                "'tis" => '<p>‘tis</p>',
                "rock 'n' roll" => '<p>rock ‘n’ roll</p>',
                'said---"Go"' => '<p>said—”Go”</p>',
                'and/"or"' => '<p>and/”or”</p>',
                "*'a'*" => '<p><strong>‘a’</strong></p>',
                "x{%c%}'s" => '<p>x’s</p>',
                "'1" => '<p>’1</p>',
            ] as $source => $html
        ) {
            $this->assertSame($html, Carve::toHtml($source), $source);
        }
    }

    public function testC2B137CommentOnlyLineDoesNotCloseHeading(): void
    {
        foreach (['= Title', '# Title'] as $marker) {
            $source = $marker . "\n%% note\n  continues";
            $tree = json_decode(Carve::toJson($source), true);
            $this->assertSame('heading', $tree['children'][0]['type'], $marker);
            $this->assertCount(1, $tree['children'], $marker);
            $this->assertNotNull($this->findType($tree, 'comment'), $marker);
            $this->assertStringContainsString("Title\ncontinues</h1>", Carve::toHtml($source), $marker);
        }
    }

    public function testC2B206MalformedOptionsRemainInAnInclude(): void
    {
        foreach (
            [
                '{{ a.crv @shift:x @size:2 }}' => [['@shift:x', '@size:2'], '<p>{{ a.crv @shift:x @size:2 }}</p>'],
                '{{ a.crv @lines @rows:1-2 }}' => [['@lines'], '<p>{{ a.crv @rows:1-2 @lines }}</p>'],
                '{{ a.crv @shift:2 @shift:3 }}' => [['@shift:3'], '<p>{{ a.crv @shift:2 @shift:3 }}</p>'],
                '{{ a.crv @cols:"a,,b" }}' => [['@cols:"a,,b"'], '<p>{{ a.crv @cols:"a,,b" }}</p>'],
            ] as $source => [$invalid, $html]
        ) {
            $tree = json_decode(Carve::toJson($source), true);
            $this->assertSame('include', $tree['children'][0]['type'], $source);
            $this->assertSame($invalid, $tree['children'][0]['invalid'] ?? null, $source);
            $this->assertSame($html, Carve::toHtml($source), $source);
        }
    }

    public function testC2B184ContinuationFragmentsReadSeparately(): void
    {
        $source = "| `a | 1 |\n+ b` | 2 |";
        $this->assertSame("<table>\n  <tbody>\n    <tr><td>`a b`</td><td>1 2</td></tr>\n  </tbody>\n</table>", Carve::toHtml($source));
        $tree = json_decode(Carve::toJson($source), true);
        $this->assertNull($this->findType($tree, 'code'));
    }

    public function testC2B173BlockedSpanMarksRemainCellText(): void
    {
        foreach (['| < | x |' => '<', '| ^ | x |' => '^', '|= ^ |= A |' => '^'] as $row => $mark) {
            $tree = json_decode(Carve::toJson($row), true);
            $this->assertSame($mark, $this->findType($tree, 'text')['value'] ?? null, $row);
        }
        $source = "{footer-rows=1}\n|= ^ |= A |\n| 1 | 2 |\n| ^ | 3 |";
        $html = Carve::toHtml($source);
        $this->assertStringContainsString('<th scope="col">^</th>', $html);
        $this->assertStringContainsString('<tfoot>', $html);
        $this->assertStringContainsString('<td>^</td>', $html);
    }

    public function testC2I002EscapedTrailingSpaceAndTabInCode(): void
    {
        foreach (["`a\\ \nb`" => "a\\ \nb", "`a\\\t\nb`" => "a\\\nb"] as $source => $value) {
            $tree = json_decode(Carve::toJson($source), true);
            $this->assertSame($value, $this->findType($tree, 'code')['value'] ?? null);
        }
    }

    public function testC2I102EscapedSpaceBeforeCellPaddingIsAHardBreak(): void
    {
        $tree = json_decode(Carve::toJson('| a\\  |'), true);
        $this->assertNotNull($this->findType($tree, 'hard_break'));
        $this->assertNull($this->findType($tree, 'non_breaking_space'));
        $this->assertSame("<table>\n  <tbody>\n    <tr><td>a<br>\n</td></tr>\n  </tbody>\n</table>", Carve::toHtml('| a\\  |'));
    }

    public function testC2H069CommentOnlyLineInsideStrong(): void
    {
        $this->assertSame("<p>a\n<strong>x\n</strong> b</p>", Carve::toHtml("a\n{*x\n%% c\n*} b"));
    }

    public function testC2H112RoundtripRawSpanCarrier(): void
    {
        $source = '`<b>x</b>`{=html}';
        $this->assertSame(
            '<p><span data-carve-raw="html">&lt;b&gt;x&lt;/b&gt;</span></p>',
            Carve::toHtml($source, ['roundtrip' => true, 'rawHtml' => false]),
        );
        $this->assertSame('<p><b>x</b></p>', Carve::toHtml($source, ['roundtrip' => true, 'rawHtml' => true]));
        $this->assertSame('<p></p>', Carve::toHtml('`x`{=latex}', ['roundtrip' => true]));
    }

    public function testC2H178AbbreviationTemplateIgnoresProfile(): void
    {
        $source = "*[A]: Able\n\nA b";
        $this->assertSame(
            '<template data-carve-abbreviations>*[A]: Able</template>' . "\n<p>A b</p>",
            Carve::toHtml($source, ['roundtrip' => true, 'profile' => 'comment']),
        );
    }

    public function testC2H081UnresolvedNestedReferencesKeepTheirOrdinaryText(): void
    {
        $this->assertSame('<p><a href="/u">outer [x][a]</a></p>', Carve::toHtml('[outer [x][a]](/u)'));
        $this->assertSame('<p><a href="/u">outer [x][]</a></p>', Carve::toHtml('[outer [x][]](/u)'));
    }

    public function testC2A182IncludePositionExcludesTrailingWhitespace(): void
    {
        foreach (["{{ a.crv }}  \n" => [0, 13], "  {{ a.crv }}  \n" => [2, 15]] as $source => [$start, $end]) {
            $tree = json_decode(Carve::toJson($source), true);
            $pos = $tree['children'][0]['pos'];
            $this->assertSame($start, $pos['startOffset']);
            $this->assertSame($end, $pos['endOffset']);
        }
    }

    public function testC2A086TableCommentBelongsToBodyBesideRow(): void
    {
        foreach (["| a |\n%% x\n+ b |\n" => [6, 10], "| a | %% x\n+ b |\n" => [5, 10]] as $source => [$start, $end]) {
            $tree = json_decode(Carve::toJson($source), true);
            $body = $this->findType($tree, 'table_body');
            $this->assertSame(['table_row', 'comment'], array_column($body['children'], 'type'));
            $this->assertArrayNotHasKey('pos', $body['children'][0]);
            $this->assertSame($start, $body['children'][1]['pos']['startOffset']);
            $this->assertSame($end, $body['children'][1]['pos']['endOffset']);
        }
    }

    private function findType(mixed $node, string $type): ?array
    {
        if (!is_array($node)) {
            return null;
        }
        if (($node['type'] ?? null) === $type) {
            return $node;
        }
        foreach ($node['children'] ?? [] as $child) {
            $found = $this->findType($child, $type);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }
}
