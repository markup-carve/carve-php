<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Performance;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Performance\BorrowedHtmlLayout;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class BorrowedHtmlLayoutTest extends TestCase
{
    private const ROUTING_SOURCE = <<<'CRV'
# Benchmark

[site]: https://example.com "Example"

Paragraph has *strong*, /emphasis/, `code`, and a [link][site].

- first
- second
  - nested

> quoted /text/

```js
return 1;
```

| Name | Value |
| --- | ---: |
| alpha | 1 |
CRV;

    public function testLargeBufferedDocumentsKeepHtmlAndLateFallback(): void
    {
        foreach ([65535, 65536, 65537, 262144] as $size) {
            $source = str_repeat('x', $size) . "\n";
            $attempt = (new BorrowedHtmlLayout())->render($source);
            $this->assertNotNull($attempt);
            $this->assertSame($this->authoritative()->convert($source), $attempt['html']);
            $this->assertSame($attempt['html'], (new CarveConverter())->convert($source));
        }
        $prefix = str_repeat("plain paragraph\n\n", 8192);
        foreach (["=marked=\n", "é\n", "- loose\n\n- list\n", "paragraph \n", "*bold*\n", "1. item\n", "# heading\n", "(c)\n", "...\n", "--\n"] as $tail) {
            $source = $prefix . $tail;
            $this->assertNull((new BorrowedHtmlLayout())->render($source));
            $this->assertSame($this->authoritative()->convert($source), (new CarveConverter())->convert($source));
        }
    }

    public function testLargeNestedLayoutsKeepTheBufferedSizeBudget(): void
    {
        $source = '';
        for ($level = 0; $level < 300; $level++) {
            $source .= str_repeat('  ', $level) . "- item\n";
        }
        $this->assertGreaterThan(65536, strlen($source));
        $this->assertNull((new BorrowedHtmlLayout())->render($source));
    }

    public function testNestedListsRespectTheParserDepthBudgetBeforeStreaming(): void
    {
        foreach ([200, 201, 300] as $levels) {
            $source = '';
            for ($level = 0; $level < $levels; $level++) {
                $source .= str_repeat('  ', $level) . "- item\n";
            }
            $attempt = (new BorrowedHtmlLayout())->render($source);
            if ($levels === 200) {
                $this->assertNotNull($attempt);
                $this->assertSame($this->authoritative()->convert($source), $attempt['html']);
            } else {
                $this->assertNull($attempt);
                $called = false;
                $this->assertSame('needs-ast', (new CarveConverter())->tryRenderHtmlStreaming(
                    $source,
                    static function () use (&$called): void {
                        $called = true;
                    },
                ));
                $this->assertFalse($called);
            }
        }
    }

    public function testPcreListCheckErrorsRejectTheBorrowedPath(): void
    {
        $previous = ini_get('pcre.backtrack_limit');
        $this->assertNotFalse($previous);
        try {
            $source = "- entry\n\nplain paragraph\n";
            ini_set('pcre.backtrack_limit', '1000000');
            $this->assertNotNull((new BorrowedHtmlLayout())->render($source));
            ini_set('pcre.backtrack_limit', '0');
            // These probes mirror eligibleSource() and check whether PCRE can expose its list-check error.
            if (
                preg_match('/[^\x00-\x7F]|[\x00\x09\x0B\x0C\x0D]/', $source) !== 0
                || preg_match('/(?:^|\n)( *)- [^\n]*\n\n(?:\n)*\1- /', $source) !== false
            ) {
                $this->markTestSkipped('PCRE does not expose the expected list backtrack-limit failure.');
            }
            $attempt = (new BorrowedHtmlLayout())->render($source);
            $this->assertNull($attempt);
            $this->assertSame(PREG_BACKTRACK_LIMIT_ERROR, preg_last_error());
        } finally {
            ini_set('pcre.backtrack_limit', $previous);
        }
    }

    public function testLongPlainPrefixesAndDenseSpansKeepExactHtml(): void
    {
        foreach (
            [
                str_repeat('plain & text ', 600) . '*bold* /em/ `code` [link](/url)',
                str_repeat('*bold* ', 128),
                str_repeat('plain text ', 80),
                '*bold* ' . str_repeat('plain & text ', 600),
                '*bold* ' . str_repeat('one ', 128) . '`code` ' . str_repeat('two ', 128),
            ] as $source
        ) {
            $source = rtrim($source) . "\n";
            $attempt = (new BorrowedHtmlLayout())->render($source);
            $this->assertNotNull($attempt);
            $this->assertSame($this->authoritative()->convert($source), $attempt['html']);
            $this->assertSame($attempt['html'], (new CarveConverter())->convert($source));
            $chunks = [];
            $this->assertSame('complete', (new CarveConverter())->tryRenderHtmlStreaming(
                $source,
                static function (string $chunk) use (&$chunks): void {
                    $chunks[] = $chunk;
                },
            ));
            $this->assertSame($attempt['html'], implode('', $chunks));
            foreach ($chunks as $chunk) {
                $this->assertLessThanOrEqual(4096, strlen($chunk));
            }
        }
    }

    public function testUnsupportedFirstParagraphLinesStillFallBackWithoutStreamingOutput(): void
    {
        foreach (["1) item\n", "+ item\n", ". item\n", "A. item\n"] as $source) {
            $this->assertNull((new BorrowedHtmlLayout())->render($source));
            $called = false;
            $this->assertSame('needs-ast', (new CarveConverter())->tryRenderHtmlStreaming(
                $source,
                static function () use (&$called): void {
                    $called = true;
                },
            ));
            $this->assertFalse($called);
        }
    }

    public function testTheBenchmarkShapedCoreRouteHasTypedAcceptanceCountsAndExactHtml(): void
    {
        $attempt = (new BorrowedHtmlLayout())->render(self::ROUTING_SOURCE, true);

        $this->assertNotNull($attempt);
        $this->assertSame([
            'headings' => 1,
            'paragraphs' => 1,
            'blockQuotes' => 1,
            'codeFences' => 1,
            'thematicBreaks' => 0,
            'images' => 0,
            'unorderedListItems' => 3,
            'orderedListItems' => 0,
            'tableRows' => 2,
            'linkDefinitions' => 1,
            'consumedLines' => 13,
            'activeDefinitions' => 1,
        ], $attempt['accepted']);
        $this->assertSame($this->authoritative()->convert(self::ROUTING_SOURCE), $attempt['html']);
    }

    public function testEveryAcceptedPinnedCorpusSourceHasExactShadowParity(): void
    {
        $layout = new BorrowedHtmlLayout();
        $converter = $this->authoritative();
        $accepted = 0;
        $paths = glob(__DIR__ . '/../../spec/tests/corpus/*.crv');
        $this->assertIsArray($paths);
        foreach ($paths as $path) {
            $source = file_get_contents($path);
            $this->assertIsString($source);
            $attempt = $layout->render($source, true);
            if ($attempt === null) {
                continue;
            }
            $accepted++;
            $this->assertSame($converter->convert($source), $attempt['html'], basename($path));
        }

        // Unicode letters, simple images, and flat star lists add seven sources.
        $this->assertSame(59, $accepted, 'A fast-path routing change needs explicit review.');
    }

    public function testAmbiguousOrStatefulDocumentsFallBackBeforePublishingOutput(): void
    {
        $layout = new BorrowedHtmlLayout();
        foreach (
            [
                "---\ntitle: x\n---\n# x\n",
                "::: note\nx\n:::\n",
                "[^n]: note\n\nref[^n]\n",
                "- loose\n\n- list\n",
                "Unicode punctuation 😀\n",
                "| H | G |\n| --- | --- |\n| a | ^ |\n",
                "| H | G |\n| --- | --- |\n| a | < |\n",
                "| A | < |\n| --- | --- |\n| a | b |\n",
                "| ^ | G |\n| --- | --- |\n| a | b |\n",
            ] as $source
        ) {
            $this->assertNull($layout->render($source), $source);
        }
    }

    /**
     * `bare_opener` (CARVE-P3-013) refuses a marker preceded by the same
     * marker, so the second run of `/x//y/` is text. The facade read it as a
     * second span, which is the one thing it may never do: `convert()` and
     * `HtmlRenderer` over `parse()` then disagree about the same document
     * (markup-carve/carve-php#2001).
     *
     * @return array<string, array{string}>
     */
    public static function sameMarkerProvider(): array
    {
        return [
            'two italic runs' => ["/x//y/\n"],
            'two strong runs' => ["*x**y*\n"],
            'in running text' => ["a /x//y/ b\n"],
            'three runs' => ["/x//y//z/\n"],
        ];
    }

    #[DataProvider('sameMarkerProvider')]
    public function testAMarkerRightAfterASameMarkerCloserHandsTheLineBack(string $source): void
    {
        $this->assertNull((new BorrowedHtmlLayout())->render($source), $source);
    }

    #[DataProvider('sameMarkerProvider')]
    public function testTheTwoEntryPointsAgreeOnIt(string $source): void
    {
        $this->assertSame(
            $this->authoritative()->convert($source),
            (new CarveConverter())->convert($source),
        );
    }

    /**
     * The guard is not a blanket refusal of two spans on one line: a marker
     * that is not preceded by the same marker still takes the facade.
     *
     * @return array<string, array{string}>
     */
    public static function stillAcceptedProvider(): array
    {
        return [
            'separated by a space' => ["/x/ /y/\n"],
            'separated by words' => ["a *x* b /y/ c\n"],
        ];
    }

    #[DataProvider('stillAcceptedProvider')]
    public function testASpanTheGuardDoesNotNameStillTakesTheFacade(string $source): void
    {
        $this->assertNotNull((new BorrowedHtmlLayout())->render($source), $source);
        $this->assertSame(
            $this->authoritative()->convert($source),
            (new CarveConverter())->convert($source),
        );
    }

    private function authoritative(): CarveConverter
    {
        // A caller-supplied renderer deliberately disables the facade.
        return new CarveConverter(renderer: new HtmlRenderer());
    }
}
