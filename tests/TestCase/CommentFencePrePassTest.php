<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * The definition pre-pass and the `%%%` comment fence (carve-php#698).
 *
 * The pre-pass walks lines before the block parser runs, so it has to know
 * which of them are inside a comment. It did not, and four shapes fell out of
 * that - each one measured against carve-js, which resolves in every case
 * below except the unterminated one.
 */
final class CommentFencePrePassTest extends TestCase
{
    private function html(string $src): string
    {
        return CarveConverter::create()->convert($src);
    }

    private function assertResolves(string $src, string $message): void
    {
        $this->assertMatchesRegularExpression('/fnref|doc-noteref/', $this->html($src), $message);
    }

    public function testAColonFenceInsideACommentIsNotALineBlockOpener(): void
    {
        // The pre-pass entered line-block state on the commented opener, and a
        // comment's closer is not a colon fence - so the state never ended and
        // every later definition was skipped.
        $this->assertResolves(
            "%%%\n::: |\n%%%\n\n[^a]: note\n\nsee [^a]\n",
            'a `::: |` inside a comment must not open a line block',
        );
    }

    public function testAnUnterminatedCommentFenceIsNotAFencedComment(): void
    {
        // The block parser degrades a lone `%%%` to a single-line comment, so
        // the line block below it is REAL and the definition inside it is verse
        // text. Entering comment state here would suppress every later line
        // block instead.
        $this->assertDoesNotMatchRegularExpression(
            '/fnref|doc-noteref/',
            $this->html("%%%\n\n::: |\n[^a]: note\n:::\n\nsee [^a]\n"),
            'an unterminated %%% must not open a fenced comment',
        );
    }

    public function testACommentCloserMayCarryTrailingText(): void
    {
        // `%%% end` closes a `%%%` fence: the closer test counts the leading
        // `%` run. Matching only a bare line left the comment open.
        $this->assertResolves(
            "%%% trailing\n::: |\n%%% end\n\n[^a]: note\n\nsee [^a]\n",
            '`%%% end` must close a `%%%` fence',
        );
    }

    public function testACodeFenceInsideACommentIsCommentText(): void
    {
        // Letting the fence reach the pre-pass fence scanner opened a code
        // block that swallowed the real comment closer.
        $this->assertResolves(
            "%%%\n```\n%%%\n\n[^a]: note\n\nsee [^a]\n",
            'a code fence inside a comment must not open a code block',
        );
    }

    public function testACloserOfADifferentLengthDoesNotClose(): void
    {
        // Keyed by EXACT length: a `%%%%` line does not close a `%%%` fence, so
        // this comment runs to the `%%%` and the definition after it registers.
        $this->assertResolves(
            "%%%\n%%%%\n::: |\n%%%\n\n[^a]: note\n\nsee [^a]\n",
            'a fence closes only on its own length',
        );
    }

    public function testADefinitionInsideACommentDoesNotRegister(): void
    {
        // The body is skipped outright. Intended: a comment renders nothing,
        // and carve-js has never registered from inside one.
        $this->assertDoesNotMatchRegularExpression(
            '/fnref|doc-noteref/',
            $this->html("%%%\n[^a]: note\n%%%\n\nsee [^a]\n"),
            'a definition inside a comment must not register',
        );
    }
}
