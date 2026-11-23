<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Import;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Cli\Command;
use MarkupCarve\Carve\OptionError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Markdown, GFM and Djot import through the HTML hub, as carve-js#5074.
 */
final class HubImporterTest extends TestCase
{
    public function testNonOneOrderedMarkerAfterReferenceDefinitionRemainsParagraph(): void
    {
        $this->assertSame("10\\) y\n", self::source("[a]: /x\n10) y\n", 'markdown'));
        $this->assertSame("2\\. y\n", self::source("[label]: /destination\n2. y\n", 'markdown'));
        $this->assertSame("> 12\\) y\n", self::source("> [a]: /x\n> 12) y\n", 'markdown'));
        $this->assertSame("- 3\\. y\n", self::source("- [a]: /x\n  3. y\n", 'markdown'));
        $this->assertSame("```\n[a]: /x\n10) y\n```\n", self::source("```\n[a]: /x\n10) y\n```\n", 'markdown'));
        $this->assertSame(
            "```=html\n<div>\n[a]: /x\n10) y\n</div>\n```\n",
            self::source("<div>\n[a]: /x\n10) y\n</div>\n", 'markdown'),
        );
        $this->assertSame("> ```\n> code\n> ```\n\n2\\. y\n", self::source("> ```\n> code\n[a]: /x\n2. y\n", 'markdown'));
        $this->assertSame("> ```=html\n> <div>\n> ```\n\n2\\. y\n", self::source("> <div>\n[a]: /x\n2. y\n", 'markdown'));
        $this->assertSame("- ```\n  code\n  ```\n\n2\\. y\n", self::source("- ```\n  code\n[a]: /x\n2. y\n", 'markdown'));
    }

    public function testEmptyHubDocumentKeepsTheFinalLineEnding(): void
    {
        foreach (['markdown', 'gfm', 'djot', 'html'] as $format) {
            $this->assertSame("\n", Carve::importDocument('', ['from' => $format])->source);
        }
        $this->assertSame("\n", Carve::importDocument("[^a]: note\n", ['from' => 'gfm'])->source);
        $this->assertSame("\n", Carve::importDocument('<input type="checkbox">', ['from' => 'html'])->source);
        $this->assertSame("\n", Carve::format(''));
    }

    private static function source(string $input, string $from): string
    {
        return Carve::importDocument($input, ['from' => $from])->source;
    }

    public function testMarkdownHeadingAtMinimumDependencyVersion(): void
    {
        $this->assertSame("= Hi\n", self::source("# Hi\n", 'markdown'));
    }

    public function testKeepsARawHtmlBlockAndInlineHtmlRaw(): void
    {
        $this->assertSame(
            "```=html\n<div>\n*a*\n</div>\n```\n\nx `<b>`{=html}y`</b>`{=html}\n",
            self::source("<div>\n*a*\n</div>\n\nx <b>y</b>\n", 'markdown'),
        );
    }

    public function testRestoresARawBlockThatATightListItemReadsInline(): void
    {
        $this->assertSame("- a\n  `<!--x-->`{=html}\n- b\n", self::source("- a\n  <!--x-->\n- b\n", 'markdown'));
        $this->assertSame("- a\n  `<div>\n  x`{=html}\n- b\n", self::source("- a\n  <div>\n  x\n- b\n", 'markdown'));
    }

    public function testKeepsTheNoteOfANonAsciiFootnoteLabel(): void
    {
        $this->assertSame("x[^%C3%A9]\n\n[^%C3%A9]: note\n", self::source("x[^é]\n\n[^é]: note\n", 'gfm'));
        $this->assertSame(
            "x[^%E6%97%A5%E6%9C%AC]\n\n[^%E6%97%A5%E6%9C%AC]: note word\n",
            self::source("x[^日本]\n\n[^日本]: note word\n", 'gfm'),
        );
    }

    public function testKeepsAScriptBlockOfGfmRawInsteadOfTagFilteredText(): void
    {
        $this->assertSame("```=html\n<script>a()</script>\n```\n", self::source("<script>a()</script>\n", 'gfm'));
    }

    public function testKeepsATightListTightWhenAnItemHoldsASecondBlock(): void
    {
        $this->assertSame("- a\n\n  > b\n- c\n", self::source("* a\n  > b\n* c\n", 'markdown'));
        $this->assertSame("- a\n\n- b\n", self::source("* a\n\n* b\n", 'markdown'));
    }

    public function testWritesFrontmatterMathAndAnAdmonitionDirective(): void
    {
        $this->assertSame(
            "---\ntitle: a\n---\n\n\$`x` and\n\n\$\$`\\sum`\n\n::: note \"Head\"\nbody\n:::\n",
            self::source("---\ntitle: a\n---\n\n\$x\$ and\n\n\$\$\n\\sum\n\$\$\n\n:::note[Head]\nbody\n:::\n", 'markdown'),
        );
    }

    public function testReadsTomlFrontmatterAndLeavesAnUnclosedFenceToMarkdown(): void
    {
        $this->assertSame("---toml\na = 1\n---\n\nx\n", self::source("+++\na = 1\n+++\nx\n", 'markdown'));
        $this->assertSame("***\n\na\n", self::source("---\na\n", 'markdown'));
    }

    public function testSkipsALeadingByteOrderMarkBeforeTheFrontmatterFence(): void
    {
        foreach (['markdown', 'gfm'] as $format) {
            $this->assertSame("---\nx: y\n---\n\nz\n", self::source("\u{FEFF}---\nx: y\n---\nz\n", $format));
            $this->assertSame("---toml\na = 1\n---\n\nz\n", self::source("\u{FEFF}+++\r\na = 1\r\n+++\r\nz\r\n", $format));
            $this->assertSame("***\n\na\n", self::source("\u{FEFF}---\na\n", $format));
            $this->assertSame("z\n", self::source("\u{FEFF}z\n", $format));
        }
    }

    public function testReadsMathAsMicromarkDoes(): void
    {
        $this->assertSame("\$`a b` and \$`c`\n", self::source("\$\$ a b \$\$ and \$c\$\n", 'markdown'));
        $this->assertSame("\$5\n", self::source("\\\$5\n", 'markdown'));
        $this->assertSame("a \$ b\n", self::source("a \$ b\n", 'markdown'));
        $this->assertSame("\$\$`x\ny`\n\nafter\n", self::source("\$\$\nx\ny\n\$\$\$\nafter\n", 'markdown'));
    }

    public function testReadsNestedContainerDirectivesAndTheirAttributes(): void
    {
        $this->assertSame(
            "{#a .x}\n:::: warning\nout\n\n{.y}\n::: box\nin\n:::\n::::\n",
            self::source("::::warning{#a .x}\nout\n\n:::box{.y}\nin\n:::\n::::\n", 'markdown'),
        );
        // As in micromark, the outer directive is asked first, so the first closing run closes it.
        $this->assertSame(
            "{#a .x}\n:::: warning\nout\n\n{.y}\n::: box\nin\n:::\n::::\n\n\\:::\n",
            self::source(":::warning{#a .x}\nout\n\n:::box{.y}\nin\n:::\n:::\n", 'markdown'),
        );
        $this->assertSame(":::name{bad\n", self::source(":::name{bad\n", 'markdown'));
    }

    public function testReadsAGfmFootnoteWithoutThePointerAtTheNotesLabel(): void
    {
        $this->assertSame("x[^1]\n\n[^1]: note\n", self::source("x[^1]\n\n[^1]: note\n", 'gfm'));
    }

    public function testReadsAGithubAlertAsAnAdmonition(): void
    {
        $this->assertSame("::: note\nhi\n:::\n", self::source("> [!NOTE]\n> hi\n", 'gfm'));
        $this->assertSame("::: danger\nx\n:::\n", self::source("> [!CAUTION]\n> x\n", 'gfm'));
        $this->assertSame("> [!NOTE]\n> hi\n", self::source("> [!NOTE]\n> hi\n", 'markdown'));
    }

    public function testKeepsAZeroStartAndRawContentOfDjot(): void
    {
        $this->assertSame("{start=0}\n. a\n. b\n", self::source("0. a\n1. b\n", 'djot'));
        $this->assertSame(
            "`<a>`{=html} and\n\n```=latex\n\\x\n```\n",
            self::source("`<a>`{=html} and\n\n``` =latex\n\\x\n```\n", 'djot'),
        );
    }

    public function testWritesOnlyAnAuthoredDjotHeadingId(): void
    {
        $this->assertSame("= Head\n\ntext\n", self::source("# Head\n\ntext\n", 'djot'));
        $this->assertSame("{#mine}\n= Head\n", self::source("{#mine}\n# Head\n", 'djot'));
    }

    public function testReportsNoWhitespaceNormalizationForTheLibrariesLineBreaks(): void
    {
        $this->assertSame([], Carve::importDocument("- a\n- b\n", ['from' => 'djot'])->diagnostics);
        $this->assertSame([], Carve::importDocument("> a\n> b\n", ['from' => 'markdown'])->diagnostics);
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function entries(): array
    {
        return [
            'markdown' => ['markdown', "# Hi *there*\n", "= Hi /there/\n"],
            'gfm' => ['gfm', "~~gone~~\n", "{-gone-}\n"],
            'djot' => ['djot', "_hi_ *there*\n", "/hi/ *there*\n"],
            'html' => ['html', '<p><em>hi</em></p>', "/hi/\n"],
            'Laravel example' => ['markdown', "**bold**\n", "*bold*\n"],
        ];
    }

    #[DataProvider('entries')]
    public function testImportsAndWritesTheSourceWithTheEngineWriter(string $from, string $input, string $expected): void
    {
        $result = Carve::importDocument($input, ['from' => $from]);
        $this->assertSame($expected, $result->source);
        $this->assertSame('document', $result->tree->type);
    }

    public function testRefusesAnUnknownFormatOrOption(): void
    {
        $refused = [['from' => 'bbcode'], ['from' => 'markdown', 'adapter' => 'word'], ['from' => 'gfm', 'strictLosses' => 'yes']];
        foreach ($refused as $options) {
            try {
                Carve::importDocument('a', $options);
                $this->fail('no OptionError for ' . json_encode($options));
            } catch (OptionError) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testStrictLossesMakesEachLossAnError(): void
    {
        $result = Carve::importDocument("[a](javascript:x)\n", ['from' => 'markdown', 'strictLosses' => true]);
        $this->assertNotSame([], $result->diagnostics);
        foreach ($result->diagnostics as $entry) {
            $this->assertSame('error', $entry->severity);
            $this->assertSame(-1, $entry->startOffset);
        }
    }

    public function testAllowLossesAndMaxLossesReachTheHtmlImporter(): void
    {
        $input = "[a](javascript:x) [b](javascript:y)\n";
        $allowed = Carve::importDocument($input, ['from' => 'markdown', 'allowLosses' => ['import-destination-denied']]);
        $this->assertSame([], $allowed->diagnostics);
        $capped = Carve::importDocument($input, ['from' => 'markdown', 'maxLosses' => 1]);
        $this->assertCount(1, $capped->diagnostics);
        $this->assertSame(1, $capped->suppressedDiagnostics);
    }

    /**
     * @return array<string, array{string, string, array<string>}>
     */
    public static function djotDestinations(): array
    {
        return [
            'denied image is the only item content' => ["- ![](data:x)\n", "- %%\n", ['import-destination-denied']],
            'denied link keeps its text' => ["[l](data:y)\n", "l\n", ['import-destination-denied']],
            'denied image with alt' => ["a ![x](javascript:alert(1)) c\n", "a x c\n", ['import-destination-denied']],
            'denied reference link in a quote' => ["> [t][r]\n>\n> [r]: vbscript:x\n", "> t\n", ['import-destination-denied']],
            'denied autolink' => ["<javascript:x>\n", "javascript:x\n", ['import-destination-denied']],
            'allowed link' => ["[l](https://e.com)\n", "[l](https://e.com)\n", []],
        ];
    }

    /**
     * carve-php#5698, C2-M-051, C2-M-082: the library does not blank a Djot destination, so a denied one is reported.
     *
     * @param string $input
     * @param string $expected
     * @param array<string> $codes
     */
    #[DataProvider('djotDestinations')]
    public function testReportsADeniedDjotDestination(string $input, string $expected, array $codes): void
    {
        $result = Carve::importDocument($input, ['from' => 'djot']);
        $this->assertSame($expected, $result->source);
        $this->assertSame($codes, array_values(array_unique(array_map(static fn ($d): string => $d->code, $result->diagnostics))));
    }

    /**
     * @return array<string, array{string, string, string, array<string>}>
     */
    public static function emptiedItems(): array
    {
        return [
            'denied image is the only content' => ["- ![](data:x)\n", 'markdown', "- %%\n", ['import-destination-denied']],
            'denied image under gfm' => ["- ![](javascript:x)\n", 'gfm', "- %%\n", ['import-destination-denied']],
            'bare marker' => ["-\n", 'markdown', "- %%\n", []],
            'second item empty' => ["- a\n-\n", 'markdown', "- a\n- %%\n", []],
            'ordered' => ["1.\n", 'markdown', ". %%\n", []],
            'in a quote' => ["> -\n", 'markdown', "> - %%\n", []],
            'nested' => ["- - ![](data:x)\n", 'markdown', "- - %%\n", ['import-destination-denied']],
            'djot description' => [": t\n\n", 'djot', ":: t\n: %%\n", []],
        ];
    }

    /**
     * carve#6428, C2-A-186, C2-W-057: an item the library leaves empty is written as a marker plus a comment.
     *
     * @param string $input
     * @param string $from
     * @param string $expected
     * @param array<string> $codes
     */
    #[DataProvider('emptiedItems')]
    public function testWritesAnEmptiedItemAsAMarkerAndAComment(string $input, string $from, string $expected, array $codes): void
    {
        $result = Carve::importDocument($input, ['from' => $from]);
        $this->assertSame($expected, $result->source);
        $this->assertSame($codes, array_values(array_unique(array_map(static fn ($d): string => $d->code, $result->diagnostics))));
    }

    public function testCommandLineImportsEachHubFormat(): void
    {
        foreach (['markdown' => "*a*\n", 'gfm' => "~~a~~\n", 'djot' => "_a_\n"] as $from => $input) {
            $in = fopen('php://memory', 'r+');
            $out = fopen('php://memory', 'r+');
            $err = fopen('php://memory', 'r+');
            $this->assertNotFalse($in);
            $this->assertNotFalse($out);
            $this->assertNotFalse($err);
            fwrite($in, $input);
            rewind($in);
            $this->assertSame(0, Command::main(['import', '--from=' . $from], $in, $out, $err));
            rewind($out);
            $this->assertSame($from === 'gfm' ? "{-a-}\n" : "/a/\n", stream_get_contents($out));
        }
    }
}
