<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Renderer\MarkdownRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A Markdown destination is resolved by whatever renders that Markdown, so a
 * scheme blanked in HTML and passed through there is not blocked -- it is the same
 * sink one step removed (PART 9 section 25, markup-carve/carve#385).
 *
 * This renderer carried its own copy of the denylist listing four schemes, and
 * probed with an ASCII-only whitespace strip. So the twenty OS protocol-handler
 * schemes reached the output, and `\u{202F}javascript:` slipped past -- both
 * blanked by the HTML renderer. There is now one implementation.
 */
class MarkdownUrlDenylistTest extends TestCase
{
    private CarveConverter $converter;

    private MarkdownRenderer $renderer;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
        $this->renderer = new MarkdownRenderer();
    }

    private function destinationOf(string $url): string
    {
        $markdown = $this->renderer->render($this->converter->parse("[click][a]\n\n[a]: {$url}\n"));
        if (preg_match('/\]\(([^)]*)\)/', $markdown, $m) !== 1) {
            return '<none>';
        }

        return $m[1];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function dangerousSchemeProvider(): array
    {
        return [
            'javascript' => ['javascript:alert(1)'],
            'vbscript' => ['vbscript:msgbox(1)'],
            'data' => ['data:text/html,<script>x</script>'],
            'file' => ['file:///etc/passwd'],
            // No SPACE in the fixture. It used to read `ms-msdt:/id
            // PCWDiagnostic`, and the space is incidental to what this case is
            // about - since markup-carve/carve#911 anchored the definition line
            // at end of line, that form is not a definition at all and there is
            // no destination to blank. Testing the denylist through a line that
            // no longer parses would leave this scheme uncovered while still
            // reading green, so the space moved to a case of its own below.
            'ms-msdt' => ['ms-msdt:/id'],
            'search-ms' => ['search-ms:query=x'],
            'shell' => ['shell:startup'],
            'vscode' => ['vscode://x'],
            'jar' => ['jar:http://x!/'],
            'unicode-hidden javascript' => ["\u{202F}javascript:alert(1)"],
        ];
    }

    #[DataProvider('dangerousSchemeProvider')]
    public function testADangerousSchemeIsBlanked(string $url): void
    {
        $this->assertSame('', $this->destinationOf($url));
    }

    /**
     * A dangerous scheme carrying a SPACE never becomes a destination at all.
     *
     * Strictly stronger than blanking, and asserted separately so the two
     * outcomes are not confused: there is no link node, so nothing reaches the
     * denylist. The reference stays unresolved and prints as the bracket run
     * the author typed.
     */
    public function testADangerousSchemeWithASpaceIsNotEvenADefinition(): void
    {
        $source = "[click][a]\n\n[a]: ms-msdt:/id PCWDiagnostic\n";
        $out = (new CarveConverter())->convert($source);

        $this->assertStringNotContainsString('<a', $out);
        $this->assertStringContainsString('[click][a]', $out);
    }

    /**
     * The point of the change: one target must not undo the other's blanking.
     */
    public function testItAgreesWithTheHtmlTarget(): void
    {
        foreach (['ms-msdt:/id', "\u{202F}javascript:alert(1)"] as $url) {
            $source = "[click][a]\n\n[a]: {$url}\n";

            $this->assertStringContainsString('href=""', $this->converter->convert($source));
            $this->assertSame('', $this->destinationOf($url));
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function safeSchemeProvider(): array
    {
        return [
            'https' => ['https://example.com/ok'],
            'mailto' => ['mailto:a@b.com'],
            'tel' => ['tel:+15551234'],
        ];
    }

    #[DataProvider('safeSchemeProvider')]
    public function testAnOrdinarySchemeIsUntouched(string $url): void
    {
        $this->assertSame($url, $this->destinationOf($url));
    }
}
