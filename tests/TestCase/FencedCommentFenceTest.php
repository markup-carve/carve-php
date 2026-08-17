<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FencedCommentFenceTest extends TestCase
{
    /**
     * @var string
     */
    protected const FOOTNOTE_CALL = '<a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a>';

    /**
     * @var string
     */
    protected const FOOTNOTE_SECTION = "<section role=\"doc-endnotes\">\n"
        . "  <hr>\n"
        . "  <ol>\n"
        . "    <li id=\"fn1\">\n"
        . "      <p>note<a href=\"#fnref1\" role=\"doc-backlink\">↩</a></p>\n"
        . "    </li>\n"
        . "  </ol>\n"
        . "</section>\n";

    /**
     * @return array<string, array{string, string}>
     */
    public static function pinnedFencedCommentProvider(): array
    {
        return [
            'bare fence' => [
                "before\n\n%%%\nsecret\n%%%\n\nafter\n",
                "<p>before</p>\n<p>after</p>\n",
            ],
            'spaced html tail' => [
                "before\n\n%%% html\nsecret\n%%%\n\nafter\n",
                "<p>before</p>\n<p>after</p>\n",
            ],
            'spaced notes tail' => [
                "before\n\n%%% notes\nsecret\n%%%\n\nafter\n",
                "<p>before</p>\n<p>after</p>\n",
            ],
            'attached tail' => [
                "before\n\n%%%html\nsecret\n%%%\n\nafter\n",
                "<p>before</p>\n<p>after</p>\n",
            ],
            'closer tail discarded' => [
                "before\n\n%%%\nsecret\n%%% end\n\nafter\n",
                "<p>before</p>\n<p>after</p>\n",
            ],
            'longer opener ignores shorter inner run' => [
                "before\n\n%%%% html\nhidden %%% inner\n%%%%\n\nafter\n",
                "<p>before</p>\n<p>after</p>\n",
            ],
            'unterminated opener with tail degrades' => [
                "before\n\n%%% TODO\nsecret\n\nafter\n",
                "<p>before</p>\n<p>secret</p>\n<p>after</p>\n",
            ],
            'unterminated bare opener degrades' => [
                "before\n\n%%%\nsecret\n\nafter\n",
                "<p>before</p>\n<p>secret</p>\n<p>after</p>\n",
            ],
            'shorter closer does not close' => [
                "before\n\n%%%%\nsecret\n%%%\n\nafter\n",
                "<p>before</p>\n<p>secret</p>\n<p>after</p>\n",
            ],
            'longer closer does not close' => [
                "before\n\n%%%\nsecret\n%%%%\n\nafter\n",
                "<p>before</p>\n<p>secret</p>\n<p>after</p>\n",
            ],
        ];
    }

    #[DataProvider('pinnedFencedCommentProvider')]
    public function testPinnedFencedCommentCases(string $input, string $expected): void
    {
        $this->assertSame($expected, (new CarveConverter())->convert($input));
    }

    public function testFencedCommentTailRoundTripsAsHiddenBodyText(): void
    {
        $input = "before\n\n%%% TODO\nsecret\n%%%\n\nafter\n";

        $this->assertSame(
            "before\n\n%%%\nTODO\nsecret\n%%%\n\nafter\n",
            CarveConverter::toCarve($input),
        );
    }

    public function testUnterminatedFencedCommentDegradeEmitsWarningWhenEnabled(): void
    {
        $converter = new CarveConverter(warnings: true);

        $this->assertSame("<p>before</p>\n<p>secret</p>\n", $converter->convert("before\n\n%%% TODO\nsecret\n"));

        $warnings = $converter->getWarnings();
        $this->assertCount(1, $warnings);
        $this->assertSame('Unclosed fenced comment', $warnings[0]->getMessage());
        $this->assertSame(3, $warnings[0]->getLine());
    }

    public function testFencedCommentRulesApplyInsideBlockquotes(): void
    {
        $input = "> before\n>\n> %%% TODO\n> secret\n> %%% end\n>\n> after\n";

        $this->assertSame(
            "<blockquote>\n  <p>before</p>\n  <p>after</p>\n</blockquote>\n",
            (new CarveConverter())->convert($input),
        );
    }

    public function testFencedCommentTailRulesApplyInHeadingTerminatorLookahead(): void
    {
        $input = "# before\n%%% TODO\nsecret\n%%% end\nafter\n";

        $this->assertSame(
            "<section id=\"before\">\n  <h1>before</h1>\n  <p>after</p>\n</section>\n",
            (new CarveConverter())->convert($input),
        );
    }

    public function testFencedCommentTailRulesApplyInsideListItems(): void
    {
        $input = "- before\n  %%% TODO\n  secret\n  %%% end\n  after\n";

        $this->assertSame(
            "<ul>\n  <li>before\n    after\n  </li>\n</ul>\n",
            (new CarveConverter())->convert($input),
        );
    }

    /**
     * Every line is a fence opener of a DISTINCT width, so no line can close any
     * other and each one has to answer "is there a closer ahead?".
     *
     * This is the shape a per-width negative cache can never help with, because
     * each width is seen once. The previous test here repeated ONE width, where
     * the second line simply closes the first, so it never reached the closer
     * lookahead at all and passed no matter what that lookahead did.
     *
     * The input's own size grows quadratically with the line count (the widths
     * get longer), so this asserts ELAPSED TIME PER BYTE, which stays flat for a
     * linear parse. Measured on this input: ~1.4 with a per-opener scan to the
     * end of the line set, ~0.5 with the width index.
     */
    public function testDistinctWidthFenceOpenersDoNotRescanPerOpener(): void
    {
        $build = static function (int $n): string {
            $out = '';
            for ($i = 0; $i < $n; $i++) {
                $out .= str_repeat('%', 3 + $i) . "\n\n";
            }

            return $out;
        };

        $small = $build(300);
        $large = $build(600);

        // Warm up so autoloading and JIT are not attributed to the first sample.
        (new CarveConverter())->convert($small);

        $perByte = static function (string $src): float {
            $best = INF;
            for ($run = 0; $run < 3; $run++) {
                $start = hrtime(true);
                (new CarveConverter())->convert($src);
                $best = min($best, (float)(hrtime(true) - $start));
            }

            return $best / strlen($src);
        };

        $ratio = $perByte($large) / max($perByte($small), 1e-9);

        $this->assertLessThan(
            1.1,
            $ratio,
            sprintf('Expected flat cost per byte; ratio was %.2f.', $ratio),
        );
    }

    /**
     * An INDENTED opener asks a second question the column-0 one never does:
     * does its closer arrive before its container ends? The answer is a walk
     * down the tail, and the naive version walks it once per opener.
     *
     * This is the shape that makes that cubic: every opener is a distinct width
     * at ONE item's content column, so no opener closes another and none of
     * them can share a per-width answer; the filler between them and the dedent
     * grows quadratically; and every closer sits PAST the dedent, so each walk
     * runs to the end of the container rather than stopping early.
     *
     * The bound is memoized per column instead - once the first dedent below a
     * column is known it is still the answer for every opener above it - so the
     * cost stays flat per byte. Measured on this input: ~1.54 with a walk per
     * opener, ~0.99 with the memo.
     */
    public function testContainerScopedOpenersDoNotWalkTheTailPerOpener(): void
    {
        $build = static function (int $n): string {
            $out = "- item\n";
            for ($i = 0; $i < $n; $i++) {
                $out .= '  ' . str_repeat('%', 3 + $i) . "\n";
            }
            $out .= str_repeat("  filler\n", $n * $n);
            $out .= "\ndedent\n\n";
            for ($i = 0; $i < $n; $i++) {
                $out .= str_repeat('%', 3 + $i) . "\n\n";
            }

            return $out;
        };

        $small = $build(40);
        $large = $build(80);

        // Warm up so autoloading and JIT are not attributed to the first sample.
        (new CarveConverter())->convert($small);

        $perByte = static function (string $src): float {
            $best = INF;
            for ($run = 0; $run < 3; $run++) {
                $start = hrtime(true);
                (new CarveConverter())->convert($src);
                $best = min($best, (float)(hrtime(true) - $start));
            }

            return $best / strlen($src);
        };

        $ratio = $perByte($large) / max($perByte($small), 1e-9);

        $this->assertLessThan(
            1.2,
            $ratio,
            sprintf('Expected flat cost per byte; ratio was %.2f.', $ratio),
        );
    }

    public function testFencedCommentInsideABlockQuoteHidesItsBody(): void
    {
        $input = "> %%% x\n> hidden\n> %%%\n\nafter\n";

        $this->assertSame(
            "<blockquote>\n\n</blockquote>\n<p>after</p>\n",
            (new CarveConverter())->convert($input),
        );
    }

    public function testUnterminatedFencedCommentInsideABlockQuoteKeepsTheBody(): void
    {
        // No closer inside the quote, so the opener degrades to a line comment
        // and the quoted body still renders.
        //
        // The quote holds one VISIBLE child - the degraded comment renders
        // nothing - so it takes the compact form. What this row pins is that
        // the body survives and `after` stays outside (carve#1106).
        $input = "> %%% x\n> visible\n\nafter\n";

        $this->assertSame(
            "<blockquote><p>visible</p></blockquote>\n<p>after</p>\n",
            (new CarveConverter())->convert($input),
        );
    }

    public function testFencedCommentInABlockQuoteEndsAtABlankLine(): void
    {
        // A blank line ends the quote, so a closer after it cannot close the
        // fence inside it: the opener degrades and the body renders.
        $input = "> %%% x\n> visible\n\n> %%%\n";

        $html = (new CarveConverter())->convert($input);

        $this->assertStringContainsString('visible', $html);
    }

    /**
     * A definition inside a QUOTED comment registers nothing, per KIND.
     *
     * The fence itself was never in doubt: the block parser read the comment
     * and closed the quote as an empty blockquote, which is why every row below
     * still expects one. What leaked was registration - the definition was
     * active in the link and footnote tables while absent from the page.
     *
     * The rows are per kind rather than one aggregate because the leak SORTED
     * BY KIND, and that is what identified it as leakage rather than a
     * competing reading of PART 9 section 28: this engine registered the link
     * reference and the footnote, carve-js only the link reference. A rule an
     * engine was following would not sort definitions by kind
     * (markup-carve/carve#1341).
     *
     * @return array<string, array{string, string}>
     */
    public static function quotedCommentDefersEveryKindProvider(): array
    {
        return [
            'link reference' => [
                "> %%%\n> [r]: /url\n> %%%\n\nSee [r][].\n",
                "<blockquote>\n\n</blockquote>\n<p>See [r][].</p>\n",
            ],
            'footnote' => [
                "> %%%\n> [^f]: note\n> %%%\n\nSee [^f].\n",
                "<blockquote>\n\n</blockquote>\n<p>See [^f].</p>\n",
            ],
            // Already right before the fence learned about quotes, and right
            // for a reason of its own: PART 12 section 7 recognizes an
            // abbreviation definition at DOCUMENT level, so the anchored
            // pattern refuses a quoted one whether or not a comment hides it.
            // Pinned here so the row that was correct by accident stays
            // correct on purpose.
            'abbreviation' => [
                "> %%%\n> *[AB]: abbrev\n> %%%\n\nThe AB here.\n",
                "<blockquote>\n\n</blockquote>\n<p>The AB here.</p>\n",
            ],
        ];
    }

    #[DataProvider('quotedCommentDefersEveryKindProvider')]
    public function testQuotedCommentDefersEveryKind(string $input, string $expected): void
    {
        $this->assertSame($expected, (new CarveConverter())->convert($input));
    }

    /**
     * The two spellings that were already pinned, per kind.
     *
     * These are CONTROLS, not new coverage. Reading the opener past a quote
     * marker touches the one place all three columns are decided, and breaking
     * either of these is worse than the gap that motivated the change.
     *
     * @return array<string, array{string, string}>
     */
    public static function unprefixedCommentStillDefersProvider(): array
    {
        return [
            'link reference at column 0' => [
                "%%%\n[r]: /url\n%%%\n\nSee [r][].\n",
                "<p>See [r][].</p>\n",
            ],
            'link reference at an item content column' => [
                "- %%%\n  [r]: /url\n  %%%\n\nSee [r][].\n",
                "<ul>\n  <li></li>\n</ul>\n<p>See [r][].</p>\n",
            ],
            'footnote at column 0' => [
                "%%%\n[^f]: note\n%%%\n\nSee [^f].\n",
                "<p>See [^f].</p>\n",
            ],
            'footnote at an item content column' => [
                "- %%%\n  [^f]: note\n  %%%\n\nSee [^f].\n",
                "<ul>\n  <li></li>\n</ul>\n<p>See [^f].</p>\n",
            ],
            'abbreviation at column 0' => [
                "%%%\n*[AB]: abbrev\n%%%\n\nThe AB here.\n",
                "<p>The AB here.</p>\n",
            ],
            'abbreviation at an item content column' => [
                "- %%%\n  *[AB]: abbrev\n  %%%\n\nThe AB here.\n",
                "<ul>\n  <li></li>\n</ul>\n<p>The AB here.</p>\n",
            ],
        ];
    }

    #[DataProvider('unprefixedCommentStillDefersProvider')]
    public function testUnprefixedCommentStillDefers(string $input, string $expected): void
    {
        $this->assertSame($expected, (new CarveConverter())->convert($input));
    }

    /**
     * Each kind still registers where NO comment hides it.
     *
     * Without these the rows above pass on an engine that has stopped
     * collecting the kind entirely, which is the same page for the reader and a
     * different defect.
     *
     * @return array<string, array{string, string}>
     */
    public static function definitionKindStillRegistersProvider(): array
    {
        return [
            'link reference in a quote' => [
                "> [r]: /url\n\nSee [r][].\n",
                "<blockquote>\n\n</blockquote>\n<p>See <a href=\"/url\">r</a>.</p>\n",
            ],
            'footnote in a quote' => [
                "> [^f]: note\n\nSee [^f].\n",
                "<blockquote>\n\n</blockquote>\n<p>See " . self::FOOTNOTE_CALL . ".</p>\n" . self::FOOTNOTE_SECTION,
            ],
            // Not quoted: PART 12 section 7 is document level, so a quoted
            // abbreviation definition renders as the text it is. The collector
            // is alive at column 0, which is what has to stay true for the
            // quoted abbreviation row above to mean anything.
            'abbreviation at column 0' => [
                "*[AB]: abbrev\n\nThe AB here.\n",
                "<p>The <abbr title=\"abbrev\">AB</abbr> here.</p>\n",
            ],
        ];
    }

    #[DataProvider('definitionKindStillRegistersProvider')]
    public function testDefinitionKindStillRegisters(string $input, string $expected): void
    {
        $this->assertSame($expected, (new CarveConverter())->convert($input));
    }

    /**
     * A quoted opener whose closer is not in the same quote opens NOTHING.
     *
     * Widening the opener alone is a worse defect than the gap it closes: the
     * block parser degrades an opener with no closer in its container to a
     * one-line comment, so a prepass that enters the region on a far closer
     * suppresses every definition between the two. Every row here registers.
     *
     * @return array<string, array{string}>
     */
    public static function quotedOpenerNeedsItsCloserInTheQuoteProvider(): array
    {
        return [
            'no closer at all' => ["> %%%\n> [r]: /url\n\nSee [r][].\n"],
            // A blank ends the quote, so the closer below it is a DIFFERENT
            // quote's - pinned for the block parser by
            // testFencedCommentInABlockQuoteEndsAtABlankLine, and the prepasses
            // have to agree or the page and the link table disagree.
            'closer below a blank line' => ["> %%%\n> [r]: /url\n\n> %%%\n\nSee [r][].\n"],
            'closer back at column 0' => ["> %%%\n> [r]: /url\n\n%%%\n\nSee [r][].\n"],
            // A nested quote's fence is quoted comment CONTENT, so it is not
            // the outer closer - read at the depth the fence opened at, exactly
            // as a quoted code fence's closer is.
            'closer one quote deeper' => ["> %%%\n> > %%%\n> [r]: /url\n\nSee [r][].\n"],
            'closer of the wrong width' => ["> %%%\n> [r]: /url\n> %%%%\n\nSee [r][].\n"],
        ];
    }

    #[DataProvider('quotedOpenerNeedsItsCloserInTheQuoteProvider')]
    public function testQuotedOpenerNeedsItsCloserInTheQuote(string $input): void
    {
        $this->assertStringContainsString('<a href="/url">r</a>', (new CarveConverter())->convert($input));
    }

    /**
     * A quoted comment CLOSES, per kind.
     *
     * The deferral rows above cannot see this: their documents hold nothing
     * below the comment, so a region that never closed would render the same
     * page. It is the failure a widened closer invites - matching the closer at
     * the wrong depth leaves the region open, and every definition in the rest
     * of the document then stops being collected while the block parser goes on
     * rendering the page normally.
     *
     * @return array<string, array{string, string}>
     */
    public static function quotedCommentClosesProvider(): array
    {
        return [
            'link reference' => [
                "> %%%\n> hidden\n> %%%\n\n[r]: /url\n\nSee [r][].\n",
                "<blockquote>\n\n</blockquote>\n<p>See <a href=\"/url\">r</a>.</p>\n",
            ],
            'footnote' => [
                "> %%%\n> hidden\n> %%%\n\n[^f]: note\n\nSee [^f].\n",
                "<blockquote>\n\n</blockquote>\n<p>See " . self::FOOTNOTE_CALL . ".</p>\n" . self::FOOTNOTE_SECTION,
            ],
            'abbreviation' => [
                "> %%%\n> hidden\n> %%%\n\n*[AB]: abbrev\n\nThe AB here.\n",
                "<blockquote>\n\n</blockquote>\n<p>The <abbr title=\"abbrev\">AB</abbr> here.</p>\n",
            ],
        ];
    }

    #[DataProvider('quotedCommentClosesProvider')]
    public function testQuotedCommentCloses(string $input, string $expected): void
    {
        $this->assertSame($expected, (new CarveConverter())->convert($input));
    }

    public function testANestedQuotesFenceDoesNotCloseTheOuterOne(): void
    {
        // The `> > %%%` is comment body; the `> %%%` below it closes, so the
        // definition between them is the comment's and registers nothing.
        $input = "> %%%\n> > %%%\n> [r]: /url\n> %%%\n\nSee [r][].\n";

        $this->assertSame(
            "<blockquote>\n\n</blockquote>\n<p>See [r][].</p>\n",
            (new CarveConverter())->convert($input),
        );
    }

    public function testAQuotedItemsCommentDefersAtItsContentColumn(): void
    {
        // Both prefixes at once: the quote marker comes off first and the
        // column is measured inside the quote, which is where the prepasses
        // already measure the content column they hand the fence (carve#658).
        $input = "> - %%%\n>   [r]: /url\n>   %%%\n\nSee [r][].\n";

        $this->assertSame(
            "<blockquote>\n  <ul>\n    <li></li>\n  </ul>\n</blockquote>\n<p>See [r][].</p>\n",
            (new CarveConverter())->convert($input),
        );
    }

    /**
     * A quote INSIDE an item defers too, at every prefix that reaches it.
     *
     * The quote-only prefixes were read by stripping every marker from position
     * 0 and the item-only ones by walking indents and list markers, so a
     * document that INTERLEAVES the two matched neither spelling: the fence was
     * never entered and the definition under it registered, one prefix further
     * than markup-carve/carve-php#1405 went (markup-carve/carve-php#1413).
     *
     * The rows go two prefixes deep in each direction because the walk is what
     * changed, not one shape in it - `- > >` and `- - >` reach the same fence by
     * different sequences, and a walk that only learned the first would leave
     * the second registering. Both kinds the quoted rows above cover are asked
     * again here, so a prefix that leaks for one kind cannot hide behind the
     * other.
     *
     * @return array<string, array{string, string}>
     */
    public static function nestedPrefixCommentDefersProvider(): array
    {
        return [
            'quote in an item, link reference' => [
                "- > %%%\n  > [r]: /url\n  > %%%\n\nSee [r][].\n",
                "<ul>\n  <li>\n    <blockquote>\n\n    </blockquote>\n  </li>\n</ul>\n"
                    . "<p>See [r][].</p>\n",
            ],
            'quote in an item, footnote' => [
                "- > %%%\n  > [^f]: note\n  > %%%\n\nSee [^f].\n",
                "<ul>\n  <li>\n    <blockquote>\n\n    </blockquote>\n  </li>\n</ul>\n"
                    . "<p>See [^f].</p>\n",
            ],
            'two quotes in an item' => [
                "- > > %%%\n  > > [r]: /url\n  > > %%%\n\nSee [r][].\n",
                "<ul>\n  <li>\n    <blockquote>\n      <blockquote>\n\n      </blockquote>\n"
                    . "    </blockquote>\n  </li>\n</ul>\n<p>See [r][].</p>\n",
            ],
            'a quote in two items' => [
                "- - > %%%\n    > [r]: /url\n    > %%%\n\nSee [r][].\n",
                "<ul>\n  <li>\n    <ul>\n      <li>\n        <blockquote>\n\n        </blockquote>\n"
                    . "      </li>\n    </ul>\n  </li>\n</ul>\n<p>See [r][].</p>\n",
            ],
        ];
    }

    #[DataProvider('nestedPrefixCommentDefersProvider')]
    public function testNestedPrefixCommentDefers(string $input, string $expected): void
    {
        $this->assertSame($expected, (new CarveConverter())->convert($input));
    }

    public function testAnItemsQuotedCommentCloses(): void
    {
        // The deferral rows above hold nothing below the comment, so a region
        // that never closed would render the same page. This one does: if the
        // closer is not matched at the interleaved prefix it opened at, the
        // region stays open and the definition below stops being collected
        // while the block parser goes on rendering the page normally.
        $input = "- > %%%\n  > hidden\n  > %%%\n\n[r]: /url\n\nSee [r][].\n";

        $this->assertSame(
            "<ul>\n  <li>\n    <blockquote>\n\n    </blockquote>\n  </li>\n</ul>\n"
                . "<p>See <a href=\"/url\">r</a>.</p>\n",
            (new CarveConverter())->convert($input),
        );
    }

    /**
     * An item's quoted opener needs its closer at the SAME prefix.
     *
     * The bound from markup-carve/carve-php#1405 one prefix over. Reading the
     * interleaved opener without carrying its prefix into the closer index
     * would pair `- > %%%` with a top-level `> %%%` far below and suppress
     * every definition in between - a worse defect than the gap. Every row here
     * registers.
     *
     * @return array<string, array{string}>
     */
    public static function nestedPrefixOpenerNeedsItsOwnCloserProvider(): array
    {
        return [
            'no closer at all' => ["- > %%%\n  > [r]: /url\n\nSee [r][].\n"],
            // The quote inside the item ended at the blank, so the `> %%%`
            // below is a top-level quote's fence and not this one's.
            'closer in a top-level quote' => ["- > %%%\n  > [r]: /url\n\n> %%%\n\nSee [r][].\n"],
            'closer back at column 0' => ["- > %%%\n  > [r]: /url\n\n%%%\n\nSee [r][].\n"],
            'closer of the wrong width' => ["- > %%%\n  > [r]: /url\n  > %%%%\n\nSee [r][].\n"],
            // Spelled at the fence's own prefix, so the closer index finds it -
            // and the BOUND is what refuses it, because the blank line ended
            // the quote inside the item. This is the row the index alone does
            // not cover.
            'closer at the same prefix past a blank' => [
                "- > %%%\n  > [r]: /url\n\nxxx\n\n  > %%%\n\nSee [r][].\n",
            ],
        ];
    }

    #[DataProvider('nestedPrefixOpenerNeedsItsOwnCloserProvider')]
    public function testNestedPrefixOpenerNeedsItsOwnCloser(string $input): void
    {
        $this->assertStringContainsString('<a href="/url">r</a>', (new CarveConverter())->convert($input));
    }

    public function testAListMarkerDoesNotCloseAFence(): void
    {
        // The walk reads a list marker for an OPENER only. A fence opens on the
        // line that opens its item, but a closer is a CONTINUATION line, where
        // a marker opens a new item instead. Reading markers on both sides
        // would let `- %%%` close the top-level fence above it - and the
        // definition BETWEEN the two is what makes that observable, because a
        // fence with no closer opens nothing and suppresses nothing.
        $input = "%%%\n[r]: /url\n- %%%\n\nSee [r][].\n";

        $this->assertStringContainsString('<a href="/url">r</a>', (new CarveConverter())->convert($input));
    }

    /**
     * The container bound is memoized per DEPTH as well as per column.
     *
     * Same shape as testContainerScopedOpenersDoNotWalkTheTailPerOpener one
     * prefix over: every opener is a distinct width inside one quote, so no
     * opener closes another and none can share a per-width answer; the filler
     * between them and the blank that ends the quote grows quadratically; and
     * every closer sits past that blank, so each walk runs to the end of the
     * quote rather than stopping early.
     *
     * Keying the memo by column alone would answer a quoted opener with a
     * top-level opener's bound, so the key carries both and this input is what
     * keeps the memo reachable at depth.
     */
    public function testQuotedOpenersDoNotWalkTheQuotePerOpener(): void
    {
        $build = static function (int $n): string {
            $out = "> item\n";
            for ($i = 0; $i < $n; $i++) {
                $out .= '> ' . str_repeat('%', 3 + $i) . "\n";
            }
            $out .= str_repeat("> filler\n", $n * $n);
            $out .= "\ndedent\n\n";
            for ($i = 0; $i < $n; $i++) {
                $out .= '> ' . str_repeat('%', 3 + $i) . "\n\n";
            }

            return $out;
        };

        $small = $build(40);
        $large = $build(80);

        // Warm up so autoloading and JIT are not attributed to the first sample.
        (new CarveConverter())->convert($small);

        $perByte = static function (string $src): float {
            $best = INF;
            for ($run = 0; $run < 3; $run++) {
                $start = hrtime(true);
                (new CarveConverter())->convert($src);
                $best = min($best, (float)(hrtime(true) - $start));
            }

            return $best / strlen($src);
        };

        $ratio = $perByte($large) / max($perByte($small), 1e-9);

        $this->assertLessThan(
            1.2,
            $ratio,
            sprintf('Expected flat cost per byte; ratio was %.2f.', $ratio),
        );
    }
}
