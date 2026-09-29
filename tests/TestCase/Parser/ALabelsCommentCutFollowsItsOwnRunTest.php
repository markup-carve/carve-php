<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use function explode;
use function preg_match;

/**
 * A container label's trailing-comment cut is the label's own inline run's answer,
 * so a `%%` a closed construct scopes is not a marker (`CARVE-P9-041`, ruled on
 * markup-carve/carve#2618).
 *
 * The scan this replaces named the opaque constructs and deleted the content of
 * every one it did not name. #2756 derived the cut instead but kept the brace
 * spelling out of it, so a label's trailing `{%% %%}` stayed in the string and
 * leaked into Markdown, plain text and ANSI (#2758).
 *
 * Expectations measured against carve-js at `8c40ded5`, on both halves the cut
 * has: the run the caption publishes and the label the Carve writer spells back.
 */
class ALabelsCommentCutFollowsItsOwnRunTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string}>
     */
    public static function labels(): array
    {
        return [
            // The four shapes the ruling names, and the verbatim span carve-js
            // found falling out with them.
            'an insertion' => ['{+a %% secret+}', '<ins>a</ins>', '{+a %% secret+}'],
            'a deletion' => ['{-a %% secret-}', '<del>a</del>', '{-a %% secret-}'],
            'a brace comment holding the marker' => ['{%%a %% secret%%}', '', ''],
            'a brace comment mid-label' => ['x{%%a %% s%%}y', 'xy', 'x{%%a %% s%%}y'],
            'a code span' => ['`a %% s`', '<code>a %% s</code>', '`a %% s`'],
            'a longer code span' => ['``a %% b``', '<code>a %% b</code>', '``a %% b``'],
            'a highlight' => ['{=a %% b=}', '<mark>a</mark>', '{=a %% b=}'],
            // A marker the run does reach still takes its whole separating run.
            'a plain trailing comment' => ['a %% secret', 'a', 'a'],
            'a second marker inside the first' => ['a %% b %% c', 'a', 'a'],
            'a marker at the label start' => ['%% all', '', ''],
            'a bare marker' => ['a %%', 'a', 'a'],
            'a tab separator' => ["a  %%\tz", 'a', 'a'],
            'a trailing brace comment' => ['a {%% x %%}', 'a', 'a'],
            'an escaped marker' => ['a \\%% keep', 'a %% keep', 'a \\%% keep'],
            // A construct the marker swallows before it can close is not closed,
            // so the comment is the run's last node and the cut stands.
            'an emphasis run the marker swallows' => ['*a %% b*', '*a', '*a'],
            'a link tail the marker swallows' => ['[l](u %% v)', '[l](u', '[l](u'],
        ];
    }

    #[DataProvider('labels')]
    public function testTheRenderedRunKeepsWhatTheConstructScopes(
        string $label,
        string $rendered,
        string $written,
    ): void {
        unset($written);
        $html = (new CarveConverter())->convert(":::[{$label}]\nx\n:::\n");
        preg_match('~<p class="div-label">(.*?)</p>~s', $html, $matches);

        $this->assertSame($rendered, $matches[1] ?? null, $label);
    }

    /**
     * The non-HTML targets write the label as the source the author typed, so the
     * cut has to reach the STRING or the comment leaks into Markdown, plain text
     * and ANSI.
     */
    #[DataProvider('labels')]
    public function testTheWriterSpellsTheLabelTheCutLeft(string $label, string $rendered, string $written): void
    {
        unset($rendered);
        $document = (new CarveConverter())->parse(":::[{$label}]\nx\n:::\n");
        $opener = explode("\n", (new CarveRenderer())->render($document))[0];

        $this->assertSame($written === '' ? '::: []' : "::: [{$written}]", $opener, $label);
    }
}
