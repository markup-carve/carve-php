<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * An unclosed verbatim run is stripped of the trailing whitespace at whatever
 * ends it. PART 9 §22 makes the closing `X}` the end of a forced span, so it
 * ends the run inside it and the run's own strip applies there as it does at
 * the block end (ruled on markup-carve/carve#2051).
 */
class AForcedSpanCloserEndsTheRunAndItsWhitespaceTest extends TestCase
{
    protected function html(string $source): string
    {
        return CarveConverter::create()->convert($source);
    }

    /**
     * @return array<string, array<string>>
     */
    public static function strippedProvider(): array
    {
        return [
            'the ruled case' => ["{~` ~}\n", "<p>{~<code> ~}</code></p>\n"],
            'two spaces' => ["{~`  ~}\n", "<p>{~<code>  ~}</code></p>\n"],
            'a tab' => ["{~`\t ~}\n", "<p>{~<code>\t ~}</code></p>\n"],
            'content before the whitespace' => ["{~`a  ~}\n", "<p>{~<code>a  ~}</code></p>\n"],
            'a strong span' => ["{*`a  *}\n", "<p>{*<code>a  *}</code></p>\n"],
            'an emphasis span' => ["{/`a  /}\n", "<p>{/<code>a  /}</code></p>\n"],
        ];
    }

    #[DataProvider('strippedProvider')]
    public function testTheCloserStripsTheRunsTrailingWhitespace(string $source, string $expected): void
    {
        $this->assertSame(preg_replace('/\n\z/', '', $expected), $this->html($source));
    }

    /**
     * BOUND: the containment was never in question, and it is unchanged.
     */
    public function testTheCloserStillBoundsTheRun(): void
    {
        $this->assertSame(preg_replace('/\n\z/', '', "<p>{~<code>x~}</code></p>\n"), $this->html("{~`x~}\n"));
    }

    /**
     * BOUND: the strip at the block end is the same strip, and still applies.
     */
    public function testTheBlockEndIsUnchanged(): void
    {
        $this->assertSame(preg_replace('/\n\z/', '', "<p><code>x</code></p>\n"), $this->html("`x \n"));
    }

    /**
     * BOUND: whitespace INSIDE the run is content, not trailing.
     */
    public function testInteriorWhitespaceSurvives(): void
    {
        $this->assertSame(preg_replace('/\n\z/', '', "<p>{~<code>a  b~}</code></p>\n"), $this->html("{~`a  b~}\n"));
    }

    /**
     * BOUND: the strip is TRAILING only. An unclosed run takes no surrounding
     * single-space strip, so a leading space is content.
     */
    public function testALeadingSpaceSurvives(): void
    {
        $this->assertSame(preg_replace('/\n\z/', '', "<p>{~<code> a~}</code></p>\n"), $this->html("{~` a~}\n"));
    }

    /**
     * BOUND: leading survives on the same run whose trailing is stripped.
     */
    public function testOnlyTheTrailingEndIsStripped(): void
    {
        $this->assertSame(preg_replace('/\n\z/', '', "<p>{~<code>  a  ~}</code></p>\n"), $this->html("{~`  a  ~}\n"));
    }

    /**
     * BOUND: the strip takes spaces and tabs, not a line break. In a line block
     * a line that is only an opener leaves its newline as content, and corpus
     * case 380 pins exactly that.
     */
    public function testALineBreakIsNotStripped(): void
    {
        $this->assertSame(
            preg_replace('/\n\z/', '', "<div class=\"line-block\">\n  <p><code>\n</code></p>\n</div>\n"),
            $this->html("::: |\n`\n%%\n:::\n"),
        );
    }

    /**
     * The OTHER unclosed branch: every candidate closer was part of a longer
     * run, so the loop exhausted rather than finding no backtick at all.
     */
    public function testTheExhaustedCandidateBranchStripsToo(): void
    {
        $this->assertSame(preg_replace('/\n\z/', '', "<p>{~<code>a``` ~}</code></p>\n"), $this->html("{~``a``` ~}\n"));
    }
}
