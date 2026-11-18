<?php

declare(strict_types=1);

namespace MarkupCarve\Carve2\Test\Inline;

/**
 * One test per clause of the inline chapter; every expectation equals the reference engine's tree.
 */
final class InlineClausesTest extends InlineTestCase
{
    /**
     * @return void
     */
    public function testC2I004AtomsBeforeDelimiters(): void
    {
        $this->assertSame(
            'text@0-2(value="a ") strong@2-14{text@3-5(value="b ") code@5-11(value="c* d") text@11-13(value=" e")}',
            self::inline('a *b `c* d` e*'),
        );
    }

    /**
     * @return void
     */
    public function testC2I006PlainPairIsText(): void
    {
        $this->assertSame(
            'text@0-7(value="a [b] c")',
            self::inline('a [b] c'),
        );
    }

    /**
     * @return void
     */
    public function testC2I007LinkSettlesBeforeDelimiters(): void
    {
        $this->assertSame(
            'text@0-5(value="a *b ") link@5-14(href="u"){text@6-10(value="c* d")} text@14-16(value=" e")',
            self::inline('a *b [c* d](u) e'),
        );
    }

    /**
     * @return void
     */
    public function testC2I007PlainPairLetsDelimitersPair(): void
    {
        $this->assertSame(
            'text@0-2(value="a ") strong@2-8{text@3-7(value="b [c")} text@8-13(value=" d] e")',
            self::inline('a *b [c* d] e'),
        );
    }

    /**
     * @return void
     */
    public function testC2I010EscapedPunctuationIsLiteral(): void
    {
        $this->assertSame(
            'text@0-11(value="a *b* [c]")',
            self::inline('a \\*b* \\[c]'),
        );
    }

    /**
     * @return void
     */
    public function testC2I011EscapedSpaceIsNoBreakSpace(): void
    {
        $this->assertSame(
            'text@0-1(value="a") non_breaking_space@1-3 text@3-6(value="b *") non_breaking_space@6-8 text@8-10(value="c*")',
            self::inline('a\\ b *\\ c*'),
        );
    }

    /**
     * @return void
     */
    public function testC2I012OtherBackslashIsLiteral(): void
    {
        $this->assertSame(
            'text@0-7(value="a \\\\b \\\\")',
            self::inline('a \\b \\\\'),
        );
    }

    /**
     * @return void
     */
    public function testC2I014CodeSpanRun(): void
    {
        $this->assertSame(
            'text@0-2(value="a ") code@2-9(value="b`c") text@9-11(value=" d")',
            self::inline('a ``b`c`` d'),
        );
    }

    /**
     * @return void
     */
    public function testC2I016CodeSpanTrimsOneSpace(): void
    {
        $this->assertSame(
            'text@0-2(value="a ") code@2-11(value="`b`") text@11-12(value=" ") code@12-1'
            . '5(value=" ") text@15-16(value=" ") code@16-23(value=" c ")',
            self::inline('a `` `b` `` ` ` `  c  `'),
        );
    }

    /**
     * @return void
     */
    public function testC2I017UnclosedCodeSpanIsText(): void
    {
        $this->assertSame(
            'text@0-5(value="a `b ") strong@5-8{text@6-7(value="c")}',
            self::inline('a `b *c*'),
        );
    }

    /**
     * @return void
     */
    public function testC2I018LiteralTextSpan(): void
    {
        $this->assertSame(
            'text@0-2(value="a ") literal_inline@2-8(value="*b*")',
            self::inline('a !`*b*`'),
        );
    }

    /**
     * @return void
     */
    public function testC2I019MathSpans(): void
    {
        $this->assertSame(
            'text@0-2(value="a ") math@2-8(display=false value="x^2") text@8-9(value=" "'
            . ') math@9-14(display=true value="y") text@14-18(value=" $ b")',
            self::inline('a $`x^2` $$`y` $ b'),
        );
    }

    /**
     * @return void
     */
    public function testC2I021RawInline(): void
    {
        $this->assertSame(
            'text@0-2(value="a ") raw_inline@2-14(format="html" value="<b>") text@14-16(value=" c")',
            self::inline('a `<b>`{=html} c'),
        );
    }

    /**
     * @return void
     */
    public function testC2I021RawMarkerOnlyAfterPlainCodeSpan(): void
    {
        $this->assertSame(
            'text@0-2(value="a ") literal_inline@2-6(value="b") text@6-13(value="{=html}")',
            self::inline('a !`b`{=html}'),
        );
    }

    /**
     * @return void
     */
    public function testC2I023LineComment(): void
    {
        $this->assertSame(
            'text@0-1(value="a") comment@1-9(form="line" value="note") soft_break@9-10 text@10-15(value="b%% c")',
            self::inline("a %% note\nb%% c"),
        );
    }

    /**
     * @return void
     */
    public function testC2I025BracedComment(): void
    {
        $this->assertSame(
            'text@0-2(value="a ") comment@2-11(form="braced" value="b\\nc") text@11-13(value=" d")',
            self::inline("a {% b\nc %} d"),
        );
    }

    /**
     * @return void
     */
    public function testC2I026BracedCommentIsTransparent(): void
    {
        $this->assertSame(
            'text@0-2(value="a ") strong@2-12{text@3-4(value="b") comment@4-11(form="braced" value="c")} text@12-14(value=" d")',
            self::inline('a *b{% c %}* d'),
        );
    }

    /**
     * @return void
     */
    public function testC2I028AttributeBlockOnSpan(): void
    {
        $this->assertSame(
            'text@0-2(value="a ") span@2-20(lang="en" attrs={"id":"i","classes":["c"],"keyValues":[["k","v"]]}){text@3-4(value="b")}',
            self::inline('a [b]{#i .c k=v :en}'),
        );
    }

    /**
     * @return void
     */
    public function testC2I031BareKeyDoesNotStartWithUnderscore(): void
    {
        $this->assertSame(
            'text@0-10(value="a [b]{_k} ") span@10-19(attrs={"id":null,"classes":[],"keyValues":[["_k","v"]]}){text@11-12(value="c")}',
            self::inline('a [b]{_k} [c]{_k=v}'),
        );
    }

    /**
     * @return void
     */
    public function testC2I033QuotedValueReadsEscapes(): void
    {
        $this->assertSame(
            'text@0-2(value="a ") span@2-17(attrs={"id":null,"classes":[],"keyValues":[["k","x \\" }"]]}){text@3-4(value="b")}',
            self::inline('a [b]{k="x \\" }"}'),
        );
    }

    /**
     * @return void
     */
    public function testC2I035UnattachedBlockIsText(): void
    {
        $this->assertSame(
            'text@0-8(value="a {.c} b")',
            self::inline('a {.c} b'),
        );
    }

    /**
     * @return void
     */
    public function testC2I036HostsTakeAttributes(): void
    {
        $this->assertSame(
            'text@0-2(value="a ") strong@2-9(attrs={"id":null,"classes":["c"],"keyValues'
            . '":[]}){text@3-4(value="b")} text@9-10(value=" ") code@10-17(value="d" attrs'
            . '={"id":null,"classes":["e"],"keyValues":[]}) text@17-18(value=" ") autolink'
            . '@18-34(href="http://x.y" attrs={"id":null,"classes":["f"],"keyValues":[]})',
            self::inline('a *b*{.c} `d`{.e} <http://x.y>{.f}'),
        );
    }

    /**
     * @return void
     */
    public function testC2I036MentionIsNoHost(): void
    {
        $this->assertSame(
            'text@0-2(value="a ") mention@2-4(target="u" kind="user") text@4-9(value="{.c} ") tag@9-11(name="t") text@11-15(value="{.d}")',
            self::inline('a @u{.c} #t{.d}'),
        );
    }

    /**
     * @return void
     */
    public function testC2I039GluedBlocksMerge(): void
    {
        $this->assertSame(
            'text@0-2(value="a ") span@2-16(attrs={"id":"i","classes":["c","d"],"keyValues":[]}){text@3-4(value="b")}',
            self::inline('a [b]{.c}{.d #i}'),
        );
    }

    /**
     * @return void
     */
    public function testC2I040EmptyBlockOnlyAfterSpan(): void
    {
        $this->assertSame(
            'text@0-2(value="a ") span@2-7{text@3-4(value="b")} text@7-8(value=" ") link'
            . '@8-14(href="u"){text@9-10(value="c")} text@14-20(value="{} d{}")',
            self::inline('a [b]{} [c](u){} d{}'),
        );
    }

    /**
     * @return void
     */
    public function testC2I043BraceOrder(): void
    {
        $this->assertSame(
            'text@0-2(value="a ") strong@2-7{text@4-5(value="b")} text@7-8(value=" ") co'
            . 'mment@8-13(form="braced" value="c") text@13-14(value=" ") critic_comment@14'
            . '-19(value="d") text@19-22(value=" {x")',
            self::inline('a {*b*} {%c%} {#d#} {x'),
        );
    }

    /**
     * @return void
     */
    public function testC2I046OnlySlashAndStarAreBare(): void
    {
        $this->assertSame(
            'text@0-10(value="a _b_ ~c~ ") emphasis@10-13{text@11-12(value="d")} text@13-14(value=" ") strong@14-17{text@15-16(value="e")}',
            self::inline('a _b_ ~c~ /d/ *e*'),
        );
    }

    /**
     * @return void
     */
    public function testC2I047CanOpenAndClose(): void
    {
        $this->assertSame(
            'text@0-5(value="a* b ") strong@5-12{text@6-11(value="c * d")}',
            self::inline('a* b *c * d*'),
        );
    }

    /**
     * @return void
     */
    public function testC2I049DoubleStarIsText(): void
    {
        $this->assertSame(
            'text@0-9(value="a **b** c")',
            self::inline('a **b** c'),
        );
    }

    /**
     * @return void
     */
    public function testC2I050CombiningMarkMakesText(): void
    {
        $this->assertSame(
            "text@0-7(value=\"a *\u{301}b*\")",
            self::inline("a *\u{301}b*"),
        );
    }

    /**
     * @return void
     */
    public function testC2I051NearestEntryCloses(): void
    {
        $this->assertSame(
            'text@0-2(value="a ") strong@2-11{text@3-5(value="b ") strong@5-8{text@6-7(value="c")} text@8-10(value=" d")}',
            self::inline('a *b *c* d*'),
        );
    }

    /**
     * @return void
     */
    public function testC2I053OwnMarkBetweenWordCharacters(): void
    {
        $this->assertSame(
            'text@0-12(value="a /usr/bin/ ") emphasis@12-15{text@13-14(value="x")}',
            self::inline('a /usr/bin/ /x/'),
        );
    }

    /**
     * @return void
     */
    public function testC2I055BracedFormsInsideWords(): void
    {
        $this->assertSame(
            'text@0-1(value="a") highlight@1-6{text@3-4(value="b")} text@6-9(value="c x") superscript@9-14{text@11-12(value="2")}',
            self::inline('a{=b=}c x{^2^}'),
        );
    }

    /**
     * @return void
     */
    public function testC2I057MarkBeforeBraceWithoutOpener(): void
    {
        $this->assertSame(
            'text@0-9(value="a b*} c/}")',
            self::inline('a b*} c/}'),
        );
    }

    /**
     * @return void
     */
    public function testC2I059FirstPairClosedWins(): void
    {
        $this->assertSame(
            'text@0-2(value="a ") strong@2-9{text@3-8(value="b {+c")} text@9-13(value=" d+}")',
            self::inline('a *b {+c* d+}'),
        );
    }

    /**
     * @return void
     */
    public function testC2I061GluedBracedPairIsText(): void
    {
        $this->assertSame(
            'text@0-8(value="a {++} b")',
            self::inline('a {++} b'),
        );
    }

    /**
     * @return void
     */
    public function testC2I062ReplacementIsTwoNodes(): void
    {
        $this->assertSame(
            'text@0-2(value="a ") delete@2-7{text@4-5(value="b")} insert@7-12{text@9-10(value="c")}',
            self::inline('a {-b-}{+c+}'),
        );
    }

    /**
     * @return void
     */
    public function testC2I063EditorialComment(): void
    {
        $this->assertSame(
            'text@0-2(value="a ") critic_comment@2-7(value="b") text@7-12(value=" {##}")',
            self::inline('a {#b#} {##}'),
        );
    }

    /**
     * @return void
     */
    public function testC2I064Span(): void
    {
        $this->assertSame(
            'text@0-2(value="a ") span@2-13(attrs={"id":null,"classes":["d"],"keyValues"'
            . ':[]}){text@3-5(value="b ") strong@5-8{text@6-7(value="c")}}',
            self::inline('a [b *c*]{.d}'),
        );
    }

    /**
     * @return void
     */
    public function testC2I066InlineLinkWithTitle(): void
    {
        $this->assertSame(
            'text@0-2(value="a ") link@2-13(href="/u" tooltip="t"){text@3-4(value="b")} '
            . 'text@13-14(value=" ") link@14-25(href="/v" tooltip="s"){text@15-16(value="c")}',
            self::inline('a [b](/u "t") [c](/v \'s\')'),
        );
    }

    /**
     * @return void
     */
    public function testC2I067BalancedDestination(): void
    {
        $this->assertSame(
            'text@0-2(value="a ") link@2-11(href="u(v)"){text@3-4(value="b")} text@11-12'
            . '(value=" ") link@12-21(href="u)v"){text@13-14(value="c")}',
            self::inline('a [b](u(v)) [c](u\\)v)'),
        );
    }

    /**
     * @return void
     */
    public function testC2I069BrokenTailIsText(): void
    {
        $this->assertSame(
            'text@0-12(value="a [b](u v) c")',
            self::inline('a [b](u v) c'),
        );
    }

    /**
     * @return void
     */
    public function testC2I071InnerLinkStays(): void
    {
        $this->assertSame(
            'text@0-2(value="a ") link@2-17(href="v"){text@3-5(value="b ") link@5-11(hre'
            . 'f="u"){text@6-7(value="c")} text@11-13(value=" d")}',
            self::inline('a [b [c](u) d](v)'),
        );
    }

    /**
     * @return void
     */
    public function testC2I072References(): void
    {
        $this->assertSame(
            'text@0-2(value="a ") link@2-8(key="k" namespace="link" refForm="full"){text'
            . '@3-4(value="b")} text@8-9(value=" ") link@9-14(key="k" namespace="link" ref'
            . 'Form="collapsed"){text@10-11(value="k")} text@14-15(value=" ") link@15-18(k'
            . 'ey="k" namespace="link" refForm="shortcut"){text@16-17(value="k")} text@18-'
            . '22(value=" [x]")',
            self::inline('a [b][k] [k][] [k] [x]', ['k']),
        );
    }

    /**
     * @return void
     */
    public function testC2I073Autolinks(): void
    {
        $this->assertSame(
            'text@0-2(value="a ") autolink@2-18(href="http://x.y/z?q") text@18-19(value='
            . '" ") autolink@19-27(href="mailto:a@b.cd" text="a@b.cd") text@27-40(value=" '
            . '<a@b> <1a:x>")',
            self::inline('a <http://x.y/z?q> <a@b.cd> <a@b> <1a:x>'),
        );
    }

    /**
     * @return void
     */
    public function testC2I074Image(): void
    {
        $this->assertSame(
            'text@0-2(value="a ") image@2-17(src="u.png" alt="b *c*") text@17-18(value="'
            . ' ") image@18-22(key="k" namespace="link" refForm="shortcut" alt="k")',
            self::inline('a ![b *c*](u.png) ![k]', ['k']),
        );
    }

    /**
     * @return void
     */
    public function testC2I078CaretOrAtLabelIsNoReference(): void
    {
        $this->assertSame(
            'text@0-5(value="a [x]") footnote_ref@5-9(key="n" namespace="footnote") text'
            . '@9-14(value=" [y][") mention@14-16(target="k" kind="user") text@16-17(value="]")',
            self::inline('a [x][^n] [y][@k]', ['k']),
        );
    }

    /**
     * @return void
     */
    public function testC2I079EmptyLabelIsNoLabel(): void
    {
        $this->assertSame(
            'text@0-14(value="a [ ][] [x][ ]")',
            self::inline('a [ ][] [x][ ]'),
        );
    }

    /**
     * @return void
     */
    public function testC2I087FootnoteReference(): void
    {
        $this->assertSame(
            'text@0-1(value="a") footnote_ref@1-5(key="n" namespace="footnote") text@5-13(value="(/u) [^]")',
            self::inline('a[^n](/u) [^]'),
        );
    }

    /**
     * @return void
     */
    public function testC2I089InlineFootnote(): void
    {
        $this->assertSame(
            'text@0-1(value="a") inline_footnote@1-9{text@3-5(value="b ") strong@5-8{text@6-7(value="c")}} text@9-13(value=" ^[d")',
            self::inline('a^[b *c*] ^[d'),
        );
    }

    /**
     * @return void
     */
    public function testC2I090SigilBoundary(): void
    {
        $this->assertSame(
            "text@0-10(value=\"a@b \u{E9}@c _\") mention@10-12(target=\"d\" kind=\"user\")",
            self::inline("a@b \u{E9}@c _@d"),
        );
    }

    /**
     * @return void
     */
    public function testC2I092MentionAndTagNames(): void
    {
        $this->assertSame(
            'text@0-2(value="a ") mention@2-6(target="a.b" kind="user") text@6-8(value="'
            . '. ") tag@8-11(name="a9") text@11-16(value="- #9 ") mention@16-19(target="-x'
            . '" kind="user")',
            self::inline('a @a.b. #a9- #9 @-x'),
        );
    }

    /**
     * @return void
     */
    public function testC2I093Symbol(): void
    {
        $this->assertSame(
            'text@0-2(value="a ") symbol@2-9(name="smile") text@9-15(value=" :a-: ") symbol@15-19(name="+1")',
            self::inline('a :smile: :a-: :+1:'),
        );
    }

    /**
     * @return void
     */
    public function testC2I094InlineExtension(): void
    {
        $this->assertSame(
            'text@0-2(value="a ") inline_extension@2-16(name="kbd" attrs={"id":null,"cla'
            . 'sses":["k"],"keyValues":[]}){text@7-11(value="Ctrl")} text@16-29(value=" x:'
            . 'kbd[y] :e[")',
            self::inline('a :kbd[Ctrl]{.k} x:kbd[y] :e['),
        );
    }

    /**
     * @return void
     */
    public function testC2I096CrossReference(): void
    {
        $this->assertSame(
            'text@0-2(value="a ") xref@2-9(key="sec" namespace="heading") text@9-12(valu'
            . 'e=" </") tag@12-14(name="a") text@14-17(value=" b>")',
            self::inline('a </#sec> </#a b>'),
        );
    }

    /**
     * @return void
     */
    public function testC2I101CitationShapeIsText(): void
    {
        $this->assertSame(
            'text@0-3(value="a [") mention@3-5(target="k" kind="user") text@5-7(value="]'
            . ' ") link@7-15(href="/u"){mention@8-10(target="k" kind="user")}',
            self::inline('a [@k] [@k](/u)', ['k']),
        );
    }

    /**
     * @return void
     */
    public function testC2I102HardBreak(): void
    {
        $this->assertSame(
            'text@0-1(value="a") hard_break@1-3 text@3-4(value="b") hard_break@4-5',
            self::inline("a\\\nb\\"),
        );
    }

    /**
     * @return void
     */
    public function testC2I103SoftBreak(): void
    {
        $this->assertSame(
            'text@0-1(value="a") soft_break@1-2 text@2-3(value="b")',
            self::inline("a\nb"),
        );
    }

    /**
     * @return void
     */
    public function testC2I104QuotesOpenAndClose(): void
    {
        $this->assertSame(
            'text@0-2(value="a ") smart_punctuation@2-3(kind="left_double_quote" gly'
            . "ph=\"\u{201C}\" source=\"\\\"\") text@3-4(value=\"b\") smart_punctuation@4-"
            . "5(kind=\"right_double_quote\" glyph=\"\u{201D}\" source=\"\\\"\") text@5-7("
            . 'value=" (") smart_punctuation@7-8(kind="left_single_quote" glyph="'
            . "\u{2018}\" source=\"'\") text@8-9(value=\"c\") smart_punctuation@9-10(kind="
            . "\"right_single_quote\" glyph=\"\u{2019}\" source=\"'\") text@10-13(value=\""
            . ") x\") smart_punctuation@13-14(kind=\"right_double_quote\" glyph=\"\u{201D}"
            . '" source="\\"") text@14-15(value="y")',
            self::inline('a "b" (\'c\') x"y'),
        );
    }

    /**
     * @return void
     */
    public function testC2I105ApostropheBeforeDigit(): void
    {
        $this->assertSame(
            'text@0-2(value="a ") smart_punctuation@2-3(kind="right_single_quote" gl'
            . "yph=\"\u{2019}\" source=\"'\") text@3-6(value=\"90s\")",
            self::inline('a \'90s'),
        );
    }

    /**
     * @return void
     */
    public function testC2I104ApostropheUsesLocalNeighbors(): void
    {
        $this->assertSame(
            implode(' ', [
                'text@0-2(value="a ")',
                "smart_punctuation@2-3(kind=\"left_single_quote\" glyph=\"\u{2018}\" source=\"'\")",
                'text@3-7(value="tis ")',
                "smart_punctuation@7-8(kind=\"left_single_quote\" glyph=\"\u{2018}\" source=\"'\")",
                'text@8-12(value="twas")',
                "smart_punctuation@12-13(kind=\"right_single_quote\" glyph=\"\u{2019}\" source=\"'\")",
                'text@13-19(value=" rock ")',
                "smart_punctuation@19-20(kind=\"left_single_quote\" glyph=\"\u{2018}\" source=\"'\")",
                'text@20-21(value="n")',
                "smart_punctuation@21-22(kind=\"right_single_quote\" glyph=\"\u{2019}\" source=\"'\")",
                'text@22-27(value=" roll")',
            ]),
            self::inline('a \'tis \'twas\' rock \'n\' roll'),
        );
    }

    /**
     * @return void
     */
    public function testC2I104SingleQuotesUseLocalNeighbors(): void
    {
        $this->assertSame(
            implode(' ', [
                'text@0-2(value="a ")',
                "smart_punctuation@2-3(kind=\"left_single_quote\" glyph=\"\u{2018}\" source=\"'\")",
                'text@3-5(value="b ")',
                "smart_punctuation@5-6(kind=\"left_single_quote\" glyph=\"\u{2018}\" source=\"'\")",
                'text@6-7(value="c")',
                "smart_punctuation@7-8(kind=\"right_single_quote\" glyph=\"\u{2019}\" source=\"'\")",
                'text@8-10(value=" d")',
                "smart_punctuation@10-11(kind=\"right_single_quote\" glyph=\"\u{2019}\" source=\"'\")",
            ]),
            self::inline('a \'b \'c\' d\''),
        );
    }

    /**
     * @return void
     */
    public function testC2I111HyphenRuns(): void
    {
        $this->assertSame(
            'text@0-2(value="a ") smart_punctuation@2-4(kind="en_dash" glyph="'
            . "\u{2013}\" source=\"--\") text@4-7(value=\" b \") smart_punctuation@7-10(ki"
            . "nd=\"em_dash\" glyph=\"\u{2014}\" source=\"---\") text@10-13(value=\" c \")"
            . " smart_punctuation@13-16(kind=\"em_dash\" glyph=\"\u{2014}\" source=\"---\""
            . ") smart_punctuation@16-18(kind=\"en_dash\" glyph=\"\u{2013}\" source=\"--\""
            . ') text@18-20(value=" d")',
            self::inline('a -- b --- c ----- d'),
        );
    }

    /**
     * @return void
     */
    public function testC2I114Ellipsis(): void
    {
        $this->assertSame(
            'text@0-1(value="a") smart_punctuation@1-4(kind="ellipsis" glyph="'
            . "\u{2026}\" source=\"...\") text@4-6(value=\" b\") smart_punctuation@6-9(kin"
            . "d=\"ellipsis\" glyph=\"\u{2026}\" source=\"...\") text@9-10(value=\".\")",
            self::inline('a... b....'),
        );
    }

    /**
     * @return void
     */
    public function testC2I115Arrows(): void
    {
        $this->assertSame(
            'text@0-2(value="a ") smart_punctuation@2-5(kind="rightwards_arrow" glyp'
            . "h=\"\u{2192}\" source=\"-->\") text@5-6(value=\" \") smart_punctuation@6-9("
            . "kind=\"leftwards_arrow\" glyph=\"\u{2190}\" source=\"<--\") text@9-10(value"
            . "=\" \") smart_punctuation@10-14(kind=\"left_right_arrow\" glyph=\"\u{2194}"
            . '" source="<-->") text@14-15(value=" ") smart_punctuation@15-18(kind="'
            . "rightwards_double_arrow\" glyph=\"\u{21D2}\" source=\"==>\") text@18-19(val"
            . 'ue=" ") smart_punctuation@19-22(kind="leftwards_double_arrow" glyph="'
            . "\u{21D0}\" source=\"<==\") text@22-23(value=\" \") smart_punctuation@23-26("
            . "kind=\"left_right_double_arrow\" glyph=\"\u{21D4}\" source=\"<=>\") text@26"
            . '-32(value=" -> =>")',
            self::inline('a --> <-- <--> ==> <== <=> -> =>'),
        );
    }

    /**
     * @return void
     */
    public function testC2I116ArrowNeedsItsExactRun(): void
    {
        $this->assertSame(
            'text@0-3(value="a <") smart_punctuation@3-6(kind="em_dash" glyph="'
            . "\u{2014}\" source=\"---\") text@6-7(value=\" \") smart_punctuation@7-10(kin"
            . "d=\"em_dash\" glyph=\"\u{2014}\" source=\"---\") text@10-11(value=\">\")",
            self::inline('a <--- --->'),
        );
    }

    /**
     * @return void
     */
    public function testC2I117Signs(): void
    {
        $this->assertSame(
            'text@0-2(value="a ") smart_punctuation@2-4(kind="not_equal" glyph="'
            . "\u{2260}\" source=\"!=\") text@4-5(value=\" \") smart_punctuation@5-7(kind="
            . "\"less_than_or_equal\" glyph=\"\u{2264}\" source=\"<=\") text@7-8(value=\" "
            . "\") smart_punctuation@8-10(kind=\"greater_than_or_equal\" glyph=\"\u{2265}"
            . '" source=">=") text@10-11(value=" ") smart_punctuation@11-13(kind="pl'
            . "us_minus\" glyph=\"\u{B1}\" source=\"+-\") text@13-14(value=\" \") smart_pu"
            . "nctuation@14-18(kind=\"trademark\" glyph=\"\u{2122}\" source=\"(tm)\") text"
            . '@18-32(value=" (c) C++-style")',
            self::inline('a != <= >= +- (tm) (c) C++-style'),
        );
    }

    /**
     * @return void
     */
    public function testC2I119FailedOpenersAreText(): void
    {
        $this->assertSame(
            'text@0-17(value="a {*b [c ^[d :e[f")',
            self::inline('a {*b [c ^[d :e[f'),
        );
    }
}
