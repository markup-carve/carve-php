<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Render;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * C2-H-019, C2-H-091: inline footnote content of only comments writes nothing before the backlink.
 */
final class InlineFootnoteCommentTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function commentOnlyNotes(): array
    {
        return [
            'comment on its own line' => ["^[\n%% c\n](u) z\n"],
            'comment line, then the closer' => ["^[\n%% c\n] z\n"],
            'two comment lines' => ["^[\n%% a\n%% b\n] z\n"],
            'hard break before a comment line' => ["^[\\\n%% c\n] z\n"],
        ];
    }

    #[DataProvider('commentOnlyNotes')]
    public function testTheBacklinkStandsAloneInTheNoteParagraph(string $source): void
    {
        $this->assertStringContainsString(
            '<li id="fn1">' . "\n" . '      <p><a href="#fnref1" role="doc-backlink" aria-label="Back to reference">↩</a></p>',
            Carve::toHtml($source),
        );
    }

    public function testABreakWithNoCommentIsStillWritten(): void
    {
        $this->assertStringContainsString(
            '<p>' . "\n" . '<a href="#fnref1" role="doc-backlink"',
            Carve::toHtml("^[\n] z\n"),
        );
    }

    public function testTextBesideTheCommentKeepsItsLineBreak(): void
    {
        $this->assertStringContainsString(
            '<p>a' . "\n" . '<a href="#fnref1" role="doc-backlink"',
            Carve::toHtml("^[a\n%% c\n] z\n"),
        );
    }
}
