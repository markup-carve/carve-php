<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

class InlineCommentTest extends TestCase
{
    protected function html(string $src): string
    {
        return trim((new CarveConverter())->convert($src));
    }

    public function testTrailingCommentStrippedPrefixKept(): void
    {
        $this->assertSame('<p>Also visible.</p>', $this->html('Also visible. %% gone'));
    }

    public function testNoSpaceBeforeStaysLiteral(): void
    {
        $this->assertSame('<p>50%% off and a%%b</p>', $this->html('50%% off and a%%b'));
    }

    public function testProtectedInsideCodeSpan(): void
    {
        $this->assertSame(
            '<p>Run <code>a %% b</code> then done.</p>',
            $this->html('Run `a %% b` then done. %% gone'),
        );
    }

    public function testEscapeBlocksTriggerEvenAfterSpace(): void
    {
        // The space before `\%%` would otherwise start a comment; the escape
        // keeps `%%` literal.
        $this->assertSame('<p>foo %% done</p>', $this->html('foo \\%% done'));
    }

    public function testTrailingCommentInHeadingKeepsId(): void
    {
        $html = $this->html('# Title %% note');
        $this->assertStringContainsString('<section id="Title">', $html);
        $this->assertStringContainsString('<h1>Title</h1>', $html);
        $this->assertStringNotContainsString('note', $html);
    }

    public function testCommentAtStartOfInlineRun(): void
    {
        // Heading text "%% all" reaches the inline parser at offset 0, so the
        // start-of-run branch fires and the whole title is a comment.
        $this->assertSame(
            "<section id=\"s\">\n  <h1></h1>\n</section>",
            $this->html('# %% all'),
        );
    }

    public function testEndsAtLineBreak(): void
    {
        $this->assertSame("<p>foo\nbar</p>", $this->html("foo %% note\nbar"));
    }

    public function testIndentedCommentOnlyLineRendersNothing(): void
    {
        // Leading whitespace before %% does not matter; matches carve-js / carve-rs.
        $this->assertSame('', $this->html('  %% indented comment'));
        $this->assertSame(
            "<p>before</p>\n<p>after</p>",
            $this->html("before\n\n  %% c\n\nafter"),
        );
    }

    public function testIndentedCommentLineInterruptsParagraph(): void
    {
        $this->assertSame("<p>x</p>\n<p>y</p>", $this->html("x\n  %% c\ny"));
    }

    public function testLineCommentConsumesBareEmphasisClosers(): void
    {
        $this->assertSame(
            "<p>*a</p>\n<p>_a</p>\n<p>/a</p>",
            $this->html("*a %% b* y\n\n_a %% b_ y\n\n/a %% b/ y"),
        );
    }

    /**
     * CARVE-P9-042: the combined token's closer and a forced span's `X}` are
     * EXPLICIT closers a comment does not cross, unlike the bare delimiters
     * above. Corpus section 490 pins all three shapes.
     */
    public function testLineCommentEndsAtAnExplicitCloser(): void
    {
        $this->assertSame(
            '<p><strong><em>a</em></strong> y</p>',
            $this->html('/*a %% b*/ y'),
        );
        $this->assertSame(
            '<p><strong>a</strong> y</p>',
            $this->html('{*a %% b*} y'),
        );
        $this->assertSame(
            "<p><strong><em>a\nb</em></strong> y</p>",
            $this->html("/*a %%c\nb*/ y"),
        );
    }
}
