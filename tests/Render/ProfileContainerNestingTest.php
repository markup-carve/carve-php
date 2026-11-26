<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Render;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\TestCase;

final class ProfileContainerNestingTest extends TestCase
{
    public function testPlainListIsLosslessUnderMinimalAndStrictLosses(): void
    {
        $input = "- a\n- b";
        $html = "<ul>\n  <li>a</li>\n  <li>b</li>\n</ul>";

        self::assertSame($html, Carve::render($input, ['profile' => 'minimal', 'strictLosses' => true]));
        self::assertSame([], Carve::renderWithDiagnostics($input, ['profile' => 'minimal'])->diagnostics);
    }

    public function testNestedListIsAllowedAtBoundAndDegradedPastIt(): void
    {
        $atBound = "- a\n  - b";
        $pastBound = "- a\n  - b\n    - c";

        self::assertSame([], Carve::renderWithDiagnostics($atBound, ['profile' => 'minimal'])->diagnostics);
        $result = Carve::renderWithDiagnostics($pastBound, ['profile' => 'minimal']);
        self::assertSame("<ul>\n  <li>a\n    <ul>\n      <li>b\n        <p>- c</p>\n      </li>\n    </ul>\n  </li>\n</ul>", $result->html);
        self::assertCount(1, $result->diagnostics);
        self::assertSame('profile-construct-degraded', $result->diagnostics[0]->code);
        self::assertStringContainsString('exceeds the nesting limit', $result->diagnostics[0]->message);
    }

    public function testQuoteUsesCommentProfileContainerBound(): void
    {
        self::assertSame([], Carve::renderWithDiagnostics('> > > > a', ['profile' => 'comment'])->diagnostics);

        $result = Carve::renderWithDiagnostics('> > > > > a', ['profile' => 'comment']);
        $expected = "<blockquote>\n  <blockquote>\n    <blockquote>\n"
            . "      <blockquote><p>&gt; a</p></blockquote>\n"
            . "    </blockquote>\n  </blockquote>\n</blockquote>";
        self::assertSame($expected, $result->html);
        self::assertCount(1, $result->diagnostics);
        self::assertStringContainsString('exceeds the nesting limit', $result->diagnostics[0]->message);
    }

    public function testExcludedTypeKeepsTypeExclusionMessage(): void
    {
        $result = Carve::renderWithDiagnostics('> a', ['profile' => 'minimal']);

        self::assertCount(1, $result->diagnostics);
        self::assertSame(
            'Degraded a construct the profile "minimal" does not allow: block_quote.',
            $result->diagnostics[0]->message,
        );
    }

    public function testInlineNodesDoNotConsumeContainerDepth(): void
    {
        $quote = Carve::renderWithDiagnostics('> > > > `x`', ['profile' => 'comment']);
        self::assertSame([], $quote->diagnostics);
        self::assertStringContainsString('<blockquote><p><code>x</code></p></blockquote>', $quote->html);

        $list = Carve::renderWithDiagnostics('- - a `b` <c:d>', ['profile' => 'minimal']);
        self::assertStringContainsString('<li>a <code>b</code> c:d</li>', $list->html);
        self::assertCount(1, $list->diagnostics);
        self::assertSame(
            'Degraded a construct the profile "minimal" does not allow: autolink.',
            $list->diagnostics[0]->message,
        );
    }
}
