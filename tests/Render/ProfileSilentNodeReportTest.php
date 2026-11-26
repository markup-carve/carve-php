<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Render;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve#6876, carve#6877: definitions, comments and frontmatter outside an allow-list profile write nothing and are still reported.
 */
final class ProfileSilentNodeReportTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: array<string>}>
     */
    public static function cases(): array
    {
        return [
            'CC-2475 definitions under comment' => [
                "[r]: /u\n\nSee [x][r] and[^n].\n\n[^n]: note\n",
                'comment',
                '<p>See <a href="/u" rel="nofollow ugc">x</a> and[^n].</p>',
                ['1:1 link_reference_definition', '3:15 footnote_ref', '5:1 footnote'],
            ],
            'CC-2476 abbreviation definition is exempt under minimal' => [
                "*[B]: bee\n\n[r]: /u\n\nA B.\n",
                'minimal',
                '<p>A B.</p>',
                ['3:1 link_reference_definition', '5:3 abbreviation'],
            ],
            'CC-2477 comments of every form under comment' => [
                "x %% c\n\n%%%\nblock\n%%%\n\nb {% c %} d\n",
                'comment',
                "<p>x</p>\n<p>b  d</p>",
                ['1:2 comment', '3:1 comment', '7:3 comment'],
            ],
            'comment-only paragraph and nested block comment, once each' => [
                "{% c %}\n\n- a\n\n  %%%\n  x\n  %%%\n\n  b\n",
                'comment',
                "<ul>\n  <li>a\n    <p>b</p>\n  </li>\n</ul>",
                ['1:1 comment', '5:3 comment'],
            ],
            'nested block comment reported in source order' => [
                "> # H\n>\n> %%%\n> x\n> %%%\n>\n> # I\n",
                'comment',
                "<blockquote>\n  <p># H</p>\n  <p># I</p>\n</blockquote>",
                ['1:3 heading', '3:3 comment', '7:3 heading'],
            ],
            'CC-2478 frontmatter under comment' => [
                "---\na: 1\n---\n\nx\n",
                'comment',
                '<p>x</p>',
                ['1:1 frontmatter'],
            ],
            'CC-2479 frontmatter under minimal' => [
                "---\na: 1\n---\n\nx\n",
                'minimal',
                '<p>x</p>',
                ['1:1 frontmatter'],
            ],
            'later frontmatter reported from its attribute line' => [
                "x\n\n{#i}\n---toml\nb = 2\n---\n\ny\n",
                'comment',
                "<p>x</p>\n<p>y</p>",
                ['3:1 frontmatter'],
            ],
            'no report without an allow list' => [
                "[r]: /u\n\n[x][r] %% c\n",
                'article',
                '<p><a href="/u">x</a></p>',
                [],
            ],
        ];
    }

    /**
     * @param string $input
     * @param string $profile
     * @param string $html
     * @param array<string> $reports
     */
    #[DataProvider('cases')]
    public function testSilentNodesAreReported(string $input, string $profile, string $html, array $reports): void
    {
        $result = Carve::renderWithDiagnostics($input, ['profile' => $profile]);

        self::assertSame($html, $result->html);
        $got = [];
        foreach ($result->diagnostics as $diagnostic) {
            self::assertSame('profile-construct-degraded', $diagnostic->code);
            $before = substr($input, 0, $diagnostic->startOffset);
            $line = substr_count($before, "\n") + 1;
            $column = $diagnostic->startOffset - (int)strrpos("\n" . $before, "\n") + 1;
            $got[] = $line . ':' . $column . ' '
                . substr($diagnostic->message, (int)strrpos($diagnostic->message, ' ') + 1, -1);
        }
        self::assertSame($reports, $got);
    }

    public function testMaxLossesKeepsTheFirstLossInSourceOrder(): void
    {
        $result = Carve::renderWithDiagnostics("> # H\n>\n> %%%\n> x\n> %%%\n", ['profile' => 'comment', 'maxLosses' => 1]);

        self::assertCount(1, $result->diagnostics);
        self::assertSame(2, $result->diagnostics[0]->startOffset);
    }
}
