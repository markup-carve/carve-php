<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Profile;
use MarkupCarve\Carve\SafeMode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PART 10 §6: an escaping safe policy writes a raw block exactly like a fenced
 * code block whose language is the raw block's format (markup-carve/carve#2795).
 */
class AnEscapedRawBlockIsACodeBlockInItsFormatTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function escapedRawBlocks(): array
    {
        return [
            'the ruling example' => [
                "Before.\n\n```=html\n<p>raw</p>\n```\n\nAfter.\n",
                "<p>Before.</p>\n<pre><code class=\"language-html\">&lt;p&gt;raw&lt;/p&gt;\n</code></pre>\n<p>After.</p>\n",
            ],
            'markup characters and a blank payload line' => [
                "```=html\na & <b>\n\nc\n```\n",
                "<pre><code class=\"language-html\">a &amp; &lt;b&gt;\n\nc\n</code></pre>\n",
            ],
        ];
    }

    #[DataProvider('escapedRawBlocks')]
    public function testSafeModeWritesTheRawBlockAsACodeBlock(string $source, string $expected): void
    {
        $result = (new CarveConverter(safeMode: true))->convertWithReport($source);

        self::assertSame($expected, $result->value);
        self::assertSame([], $result->losses);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function escapedRawBlockSources(): array
    {
        return array_map(static fn (array $case): array => [$case[0]], self::escapedRawBlocks());
    }

    #[DataProvider('escapedRawBlockSources')]
    public function testTheOutputMatchesTheSameFencedCodeBlock(string $source): void
    {
        $asCode = preg_replace('/^```=(\w+)$/m', '```$1', $source);

        self::assertSame(
            (new CarveConverter())->convert((string)$asCode),
            (new CarveConverter(safeMode: true))->convert($source),
        );
    }

    public function testSafeModeStillDropsANonHtmlRawBlock(): void
    {
        $result = (new CarveConverter(safeMode: true))->convertWithReport("```=latex\n\\textbf{x}\n```\n");

        self::assertSame('', $result->value);
        self::assertSame(['raw-format-dropped'], array_column($result->losses, 'code'));
    }

    public function testRawAllowedPassesTheContentThroughUnchanged(): void
    {
        $source = "Before.\n\n```=html\n<p>raw</p>\n```\n\nAfter.\n";

        self::assertSame(
            "<p>Before.</p>\n<p>raw</p>\n<p>After.</p>\n",
            (new CarveConverter())->convert($source),
        );
        self::assertSame(
            "<p>Before.</p>\n<p>raw</p>\n<p>After.</p>\n",
            (new CarveConverter(safeMode: SafeMode::defaults()->setRawHtmlMode(SafeMode::RAW_HTML_ALLOW)))->convert($source),
        );
    }

    public function testStrictModeStillDropsRawBlocks(): void
    {
        $converter = new CarveConverter(safeMode: SafeMode::strict());

        $html = $converter->convertWithReport("Before.\n\n```=html\n<p>raw</p>\n```\n\nAfter.\n");
        self::assertSame("<p>Before.</p>\n<p>After.</p>\n", $html->value);
        self::assertSame([], $html->losses);

        $latex = $converter->convertWithReport("```=latex\n\\textbf{x}\n```\n");
        self::assertSame('', $latex->value);
        self::assertSame(['raw-format-dropped'], array_column($latex->losses, 'code'));
    }

    #[DataProvider('escapedRawBlocks')]
    public function testAProfileThatEscapesARawBlockWritesACodeBlock(string $source, string $expected): void
    {
        self::assertSame($expected, (new CarveConverter(profile: Profile::article()))->convert($source));
    }

    public function testAProfileNamesTheCodeClassAfterAnyRawFormat(): void
    {
        self::assertSame(
            "<pre><code class=\"language-latex\">\\textbf{x}\n</code></pre>\n",
            (new CarveConverter(profile: Profile::article()))->convert("```=latex\n\\textbf{x}\n```\n"),
        );
    }

    public function testTheProfileCodeBlockKeepsTheRawBlockAttributes(): void
    {
        self::assertSame(
            "<pre id=\"i\" class=\"c\"><code class=\"language-html\">&lt;b&gt;x&lt;/b&gt;\n</code></pre>\n",
            (new CarveConverter(profile: Profile::article()))->convert("{#i .c}\n```=html\n<b>x</b>\n```\n"),
        );
    }

    public function testAProfileThatStripsStillDropsTheRawBlock(): void
    {
        $profile = Profile::article()->onDisallowed(Profile::ACTION_STRIP);

        self::assertSame(
            "<p>Para.</p>\n",
            (new CarveConverter(profile: $profile))->convert("Para.\n\n```=html\n<b>x</b>\n```\n"),
        );
    }

    public function testAProfileThatDeniesCodeBlocksKeepsTheTextFallback(): void
    {
        self::assertSame(
            "<p>Para.</p>\n<p>&lt;b&gt;x&lt;/b&gt;</p>\n",
            (new CarveConverter(profile: Profile::minimal()))->convert("Para.\n\n```=html\n<b>x</b>\n```\n"),
        );
    }
}
