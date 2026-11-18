<?php

declare(strict_types=1);

namespace MarkupCarve\Carve2\Test\Parity;

use MarkupCarve\Carve2\Carve2;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class Candidate16Test extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string, 2: array{0: int, 1: int}|null}>
     */
    public static function lintCases(): array
    {
        return [
            'C2-B-025 second item block' => ["- = a\n == b\n", 'list-item-body-below-content-column', [7, 11]],
            'C2-I-124 later bare tag' => [
                "<https://example.com/a_b?c=*d*> and <a@b.com> and <a@b> and <not a link>\n",
                'bare-html-tag',
                [60, 72],
            ],
            'C2-I-077 inline footnote is not a label' => ["^[a %% c\nd] e\n", 'comment-in-reference-label', null],
            'C2-B-044 three blank lines separate lists' => [
                "i.  first 1\n\n\n\nc.  second 1\nd.  second 2\n",
                'ordered-list-kind-split',
                null,
            ],
            'C2-B-044 adjacent split remains reported' => ["i. first\nk. second\n", 'ordered-list-kind-split', [0, 8]],
            'C2-B-055 first nested marker has no deeper list yet' => ["- a\n - %%%\n%%%\nx\n", 'list-marker-below-content-column', null],
            'C2-B-055 marker between two lists is reported' => ["- a\n  - b\n - c\n", 'list-marker-below-content-column', [11, 14]],
            'C2-B-054 marker still inside an outer list is even' => ["- a\n  - b\n - c\n", 'uneven-list-indent', null],
        ];
    }

    #[DataProvider('lintCases')]
    public function testLintRange(string $source, string $code, ?array $range): void
    {
        $matching = array_values(array_filter(
            Carve2::lint($source),
            static fn ($diagnostic): bool => $diagnostic->code === $code,
        ));
        if ($range === null) {
            self::assertSame([], $matching);

            return;
        }
        self::assertCount(1, $matching);
        self::assertSame($range, [$matching[0]->startOffset, $matching[0]->endOffset]);
    }

    public function testC2B085DuplicateFootnoteRangesOnlyItsMarker(): void
    {
        $source = "[^a]: +\n[^a]: +\n- b\n\n[^a]\n";
        $matching = array_values(array_filter(
            Carve2::parseWithDiagnostics($source)->diagnostics,
            static fn ($diagnostic): bool => $diagnostic->code === 'duplicate-footnote-definition',
        ));
        self::assertCount(1, $matching);
        self::assertSame([8, 13], [$matching[0]->startOffset, $matching[0]->endOffset]);
    }

    public function testC2A151LineBlockCommentEndsAtLastOwnedCharacter(): void
    {
        $tree = json_decode(Carve2::toJson("::: |\na\n%%%\nhidden\n:::\n"), true, 512, JSON_THROW_ON_ERROR);
        $comment = $tree['children'][0]['children'][1];
        self::assertSame('comment', $comment['type']);
        self::assertSame(18, $comment['pos']['endOffset']);
    }

    public function testC2B024BlankLineEndsLazyLineBlockStanza(): void
    {
        $source = "- ::: |\n  a\nb\n\n- v.  x\n  vi.  y\n";
        self::assertSame(
            "<ul>\n  <li>\n    <div class=\"line-block\">\n      <p>a<br>\nb</p>\n    </div>\n  </li>\n"
                . "  <li>\n    <ol type=\"i\" start=\"5\">\n      <li>x</li>\n      <li>y</li>\n    </ol>\n  </li>\n</ul>",
            Carve2::toHtml($source),
        );
    }
}
