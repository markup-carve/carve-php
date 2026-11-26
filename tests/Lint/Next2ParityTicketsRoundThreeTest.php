<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Lint;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class Next2ParityTicketsRoundThreeTest extends TestCase
{
    /**
     * @return array<string, array{string, string, list<array{int, int}>}>
     */
    public static function cases(): array
    {
        return [
            '#5393 lazy attribute' => ["a\n{.c}\nb\n", 'unattached-block-attribute', []],
            '#5389 glued quote' => ["> %%%\n>%%%\n> |\n", 'block-opener-without-blank-line', [[11, 12]]],
            '#5383 quoted item' => ["> * a\n>\n>  # b\n", 'list-item-body-below-content-column', [[11, 14]]],
            '#5390 quoted table' => ["> : x\n> | a |\n", 'caption-looking-paragraph', [[2, 3]]],
            '#5396 glued alert' => [">* > [!NOTE]\n", 'github-alert-marker', []],
            '#5391 quoted colon' => ["> ::::\n> :::\n", 'colon-fence-length-mismatch', [[9, 12]]],
            '#5384 paragraph list text' => ["   {%c%}\n1. ___\n", 'thematic-break-spelling', []],
            '#5397 comment body' => ["\t>:::\n## H\ni) i) %%%%\n       %%% \n>  a <b>\n", 'bare-html-tag', []],
            '#5395 term continuation' => ["2) :: term\n   - - -\n", 'deprecated-spaced-thematic-break', [[14, 19]]],
            '#5394 quoted blank' => ["> {#id}\n>\n", 'unattached-block-attribute', [[2, 7]]],
            '#5392 longer colon' => [":::\n%%%\n%%%\n::::\n", 'colon-fence-length-mismatch', [[12, 16]]],
            'carve#6194 trailing spaces' => [":::: x\n:::::  \n", 'colon-fence-length-mismatch', [[7, 12]]],
            'carve#6194 quote boundary' => ["::::\n> :::\n", 'colon-fence-length-mismatch', []],
            'carve#6194 item boundary' => ["::::\n- a\n\n  :::  \n", 'colon-fence-length-mismatch', []],
            '#5387 lazy quote' => ["c\n> %%%\n", 'block-opener-without-blank-line', [[2, 3]]],
            '#5386 quoted after attrs' => ["{#id}\n> | x |\n", 'block-opener-overindented', []],
            '#5388 caption quote' => [">\n^ Caption\n  > code\n", 'block-opener-without-blank-line', [[14, 15]]],
        ];
    }

    /**
      * @param string $source
     * @param string $rule
@param list<array{int, int}> $expected
     */
    #[DataProvider('cases')]
    public function testTicket(string $source, string $rule, array $expected): void
    {
        $actual = [];
        foreach (Carve::lint($source, ['upgrade' => true]) as $diagnostic) {
            if ($diagnostic->code === $rule) {
                $actual[] = [$diagnostic->startOffset, $diagnostic->endOffset];
            }
        }
        self::assertSame($expected, $actual);
    }

    public function testGluedQuoteDoesNotCloseCommentOrRetainQuote(): void
    {
        $html = Carve::toHtml("> %%%\n>%%%\n> |\n");
        self::assertStringContainsString("<p>&gt;%%%\n&gt; |</p>", $html);
        self::assertStringNotContainsString('<p>|</p>', $html);
    }
}
