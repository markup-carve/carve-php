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

    public function testRepeatedUnterminatedFencedCommentsScaleLinearly(): void
    {
        $small = str_repeat("%%% x\n", 2000);
        $large = str_repeat("%%% x\n", 4000);

        (new CarveConverter())->convert($small);

        $smallStart = hrtime(true);
        (new CarveConverter())->convert($small);
        $smallElapsed = hrtime(true) - $smallStart;

        $largeStart = hrtime(true);
        (new CarveConverter())->convert($large);
        $largeElapsed = hrtime(true) - $largeStart;

        $this->assertLessThan(
            3.5,
            $largeElapsed / max(1, $smallElapsed),
            sprintf('Expected 2n input to stay near linear; ratio was %.2f.', $largeElapsed / max(1, $smallElapsed)),
        );
    }
}
