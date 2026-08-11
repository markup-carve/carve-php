<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FencedCommentFenceTest extends TestCase
{
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
}
