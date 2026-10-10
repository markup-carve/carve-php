<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use MarkupCarve\Carve\Parser\BlockParser;
use MarkupCarve\Carve\Renderer\MarkdownRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * CARVE-P11-063, PART 11 §10s: under the opt-in carrier mode the Markdown
 * target brackets every ELEMENT-LESS CONTAINER with an HTML comment carrying
 * its Carve opener and closer verbatim, so an export and a re-import return
 * the container.
 *
 * THE TWO CONTROLS ARE THE POINT: with the mode off the emitted bytes are the
 * ones this target emits today, and a document holding no element-less
 * container gains no comment either way. A carrier that moved the default
 * output would be a breaking change rather than an opt-in mode.
 *
 * TWO OF THE CLAUSE'S OWN SAMPLES CANNOT BE MEASURED. `::: wrapper {.fancy}`
 * is not a Carve opener: PART 9 §12 and the container grammar are STRICT, the
 * opener line carries no inline `{...}` attributes, so both this engine and
 * carve-js drop the brace block at PARSE time and no writer can carry what the
 * tree does not hold. An attributed container's Carve spelling is a PRECEDING
 * attribute line, and that is the line carried here.
 */
class AnElementLessContainerIsCarriedInACommentTest extends TestCase
{
    /**
     * The mode-off path never touches the new API, so the controls below are
     * controls on EVERY build rather than only on one that has the mode.
     */
    private function markdown(string $carve, bool $carry = false): string
    {
        $renderer = new MarkdownRenderer();
        if ($carry) {
            $renderer->setCarryMarkers();
        }

        return CarveConverter::create(new BlockParser(), $renderer)->convert($carve);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function carriedProvider(): array
    {
        $cases = [
            'a tab set and each of its panels' => [
                "::: tabs\n:::: tab [Overview]\nFirst panel.\n::::\n\n:::: tab [Install]\nSecond panel.\n::::\n:::\n",
                "<!-- carve: ::: tabs -->\n<!-- carve: :::: tab [Overview] -->\n**Overview**\n\nFirst panel.\n\n"
                . "<!-- carve: :::: -->\n<!-- carve: :::: tab [Install] -->\n**Install**\n\nSecond panel.\n\n"
                . "<!-- carve: :::: -->\n<!-- carve: ::: -->\n",
            ],
            'a code-group set and its panel' => [
                "::: code-group\n:::: tab [sh]\n```sh\nx\n```\n::::\n:::\n",
                "<!-- carve: ::: code-group -->\n<!-- carve: :::: tab [sh] -->\n**sh**\n\n```sh\nx\n```\n\n"
                . "<!-- carve: :::: -->\n<!-- carve: ::: -->\n",
            ],
            'a named div, whose name is what today drops' => [
                "::: wrapper\nA generic div.\n:::\n",
                "<!-- carve: ::: wrapper -->\nA generic div.\n\n<!-- carve: ::: -->\n",
            ],
            // An attributed container is TWO Carve lines, so it is two markers.
            'a named div carrying attributes' => [
                "{.fancy #w}\n::: wrapper\nA generic div.\n:::\n",
                "<!-- carve: {.fancy #w} -->\n<!-- carve: ::: wrapper -->\nA generic div.\n\n<!-- carve: ::: -->\n",
            ],
            'a div nested in a named div' => [
                "::: outer\n:::: inner\nx\n::::\n:::\n",
                "<!-- carve: ::: outer -->\n<!-- carve: :::: inner -->\nx\n\n<!-- carve: :::: -->\n<!-- carve: ::: -->\n",
            ],
            'a columns container and each column' => [
                "::: columns\n:::: column\nA\n::::\n\n:::: column\nB\n::::\n:::\n",
                "<!-- carve: ::: columns -->\n<!-- carve: :::: column -->\nA\n\n<!-- carve: :::: -->\n"
                . "<!-- carve: :::: column -->\nB\n\n<!-- carve: :::: -->\n<!-- carve: ::: -->\n",
            ],
            'a disclosure' => [
                "::: details \"Open me\"\nHidden.\n:::\n",
                "<!-- carve: ::: details \"Open me\" -->\n**Open me**\n\nHidden.\n\n<!-- carve: ::: -->\n",
            ],
            'a spoiler' => [
                "::: spoiler\nHidden.\n:::\n",
                "<!-- carve: ::: spoiler -->\nHidden.\n\n<!-- carve: ::: -->\n",
            ],
            'a composite figure group wrapper' => [
                "::: figure\n![a](a.png)\n:::\n",
                "<!-- carve: ::: figure -->\n![a](a.png)\n\n<!-- carve: ::: -->\n",
            ],
            'a typeless div carrying a label' => [
                "::: [First]\nx\n:::\n",
                "<!-- carve: ::: [First] -->\n**First**\n\nx\n\n<!-- carve: ::: -->\n",
            ],
            // THE ESCAPE, the only spelling in the payload that is not Carve
            // source read back verbatim. A quoted title takes a `-->` and has
            // no escape of its own, so it is where the case lives.
            'a payload carrying `-->`' => [
                "::: note \"a --> b\"\nBody.\n:::\n",
                "<!-- carve: ::: note \"a --\\> b\" -->\n**a \u{2192} b**\n\nBody.\n\n<!-- carve: ::: -->\n",
            ],
            'a payload already carrying a backslash before that `>`' => [
                "::: note \"a --\\> b\"\nBody.\n:::\n",
                "<!-- carve: ::: note \"a --\\\\> b\" -->\n**a --\\> b**\n\nBody.\n\n<!-- carve: ::: -->\n",
            ],
            'a label carrying `-->`' => [
                "::: wrapper [a --> b]\nx\n:::\n",
                "<!-- carve: ::: wrapper [a --\\> b] -->\n**a --> b**\n\nx\n\n<!-- carve: ::: -->\n",
            ],
        ];
        foreach (['note', 'tip', 'warning', 'danger', 'info', 'success', 'example', 'quote'] as $kind) {
            $cases["an admonition of kind $kind"] = [
                "::: $kind\nAn admonition body.\n:::\n",
                "<!-- carve: ::: $kind -->\nAn admonition body.\n\n<!-- carve: ::: -->\n",
            ];
            $cases["an admonition of kind $kind carrying a title"] = [
                "::: $kind \"A title\"\nAn admonition body.\n:::\n",
                "<!-- carve: ::: $kind \"A title\" -->\n**A title**\n\nAn admonition body.\n\n<!-- carve: ::: -->\n",
            ];
        }

        return $cases;
    }

    #[DataProvider('carriedProvider')]
    public function testTheCarrierModeWritesTheOpenerVerbatim(string $carve, string $carrier): void
    {
        $this->assertSame($carrier, $this->markdown($carve, true));
    }

    #[DataProvider('carriedProvider')]
    public function testTheCarrierModeRoundTripsTheContainer(string $carve, string $carrier): void
    {
        $this->assertSame($carve, (new MarkdownToCarve())->convert($carrier));
    }

    #[DataProvider('carriedProvider')]
    public function testTheModeOffEmitsExactlyTheBytesThisTargetEmitsToday(string $carve, string $carrier): void
    {
        $this->assertStringNotContainsString('<!-- carve:', $this->markdown($carve));
    }

    public function testTheModeOffEmitsTodaysBytes(): void
    {
        $tabs = "::: tabs\n:::: tab [Overview]\nFirst panel.\n::::\n\n:::: tab [Install]\nSecond panel.\n::::\n:::\n";
        $this->assertSame("**Overview**\n\nFirst panel.\n\n**Install**\n\nSecond panel.\n", $this->markdown($tabs));
        $this->assertSame("An admonition body.\n", $this->markdown("::: note\nAn admonition body.\n:::\n"));
        $this->assertSame("A generic div.\n", $this->markdown("::: wrapper\nA generic div.\n:::\n"));
    }

    /**
     * @var string
     */
    private const PLAIN = "# Head\n\nA paragraph with *bold* text.\n\n- one\n- two\n";

    /**
     * @var string
     */
    private const PLAIN_MARKDOWN = "# Head\n\nA paragraph with **bold** text.\n\n- one\n- two\n";

    public function testADocumentWithNoElementLessContainerGainsNoCommentWithTheModeOff(): void
    {
        $out = $this->markdown(self::PLAIN);
        $this->assertStringNotContainsString('<!-- carve:', $out);
        $this->assertSame(self::PLAIN_MARKDOWN, $out);
    }

    public function testADocumentWithNoElementLessContainerGainsNoCommentWithTheModeOn(): void
    {
        $out = $this->markdown(self::PLAIN, true);
        $this->assertStringNotContainsString('<!-- carve:', $out);
        $this->assertSame(self::PLAIN_MARKDOWN, $out);
    }

    /**
     * A container this target DOES spell is not element-less and takes no
     * marker: an attributes-only opener is a paragraph, and a list table is
     * written as a pipe table.
     *
     * @return array<string, array{0: string}>
     */
    public static function spelledProvider(): array
    {
        return [
            'an attributes-only opener' => ["::: {.warning}\nx\n:::\n"],
            'a list table' => ["{header-rows=1}\n::: list-table\n- - A\n  - B\n- - one\n  - x\n:::\n"],
        ];
    }

    #[DataProvider('spelledProvider')]
    public function testAContainerThisTargetSpellsTakesNoMarker(string $carve): void
    {
        $this->assertStringNotContainsString('<!-- carve:', $this->markdown($carve, true));
    }

    /**
     * A host that prefixes its lines takes no marker yet: the comment would sit
     * at the host's content column or behind its `>`, where the import does not
     * read it, so it would be written and never read back. The container
     * degrades there exactly as it does with the mode off.
     *
     * @return array<string, array{0: string}>
     */
    public static function prefixedHostProvider(): array
    {
        return [
            'inside a list item' => ["- item\n\n  ::: note\n  Body.\n  :::\n"],
            'inside a block quote' => ["> ::: note\n> Body.\n> :::\n"],
        ];
    }

    #[DataProvider('prefixedHostProvider')]
    public function testAContainerInAPrefixedHostTakesNoMarker(string $carve): void
    {
        $carrier = $this->markdown($carve, true);
        $this->assertStringNotContainsString('<!-- carve:', $carrier);
        $this->assertSame($this->markdown($carve), $carrier);
        // And no marker written means no damage claimed on the way back.
        $codes = array_map(
            static fn ($diagnostic): string => $diagnostic->code,
            (new MarkdownToCarve())->convertWithFidelityReport($carrier)->diagnostics,
        );
        $this->assertNotContains('carrier-markers-damaged', $codes);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function damagedProvider(): array
    {
        return [
            'one marker deleted' => ["Body.\n<!-- carve: ::: -->\n"],
            'two markers reordered' => ["<!-- carve: ::: -->\nBody.\n<!-- carve: ::: note -->\n"],
            'an unbalanced set' => ["<!-- carve: ::: note -->\n<!-- carve: ::: wrapper -->\nBody.\n<!-- carve: ::: -->\n"],
        ];
    }

    #[DataProvider('damagedProvider')]
    public function testADamagedSetImportsAsPlainMarkdownPlusOneDiagnostic(string $source): void
    {
        $result = (new MarkdownToCarve())->convertWithFidelityReport($source);
        $damaged = array_values(array_filter(
            $result->diagnostics,
            static fn ($diagnostic): bool => $diagnostic->code === 'carrier-markers-damaged',
        ));
        $this->assertCount(1, $damaged, 'a damaged marker set owes exactly one diagnostic');
        $this->assertSame('degraded', $damaged[0]->fidelity);
        $this->assertSame('fallback', $damaged[0]->confidence);
        // Never a guess: no container is reconstructed, and the markers come
        // back as the raw HTML they are.
        $this->assertSame(0, preg_match('/^:{3,}/m', $result->value), 'a damaged set reconstructed a container');
        $this->assertStringContainsString('```=html', $result->value);
    }

    /**
     * markup-carve/carve-php#3038: a code construct's payload is verbatim
     * content, so a marker-shaped line in one records no container and the
     * import must leave it where it is. The page documenting the mode holds
     * exactly such lines, and lifting one rewrote its own sample.
     *
     * @return array<string, array{0: string}>
     */
    public static function verbatimMarkerProvider(): array
    {
        return [
            'a fenced code block' => ["Prose.\n\n```markdown\n<!-- carve: ::: note -->\nBody.\n<!-- carve: ::: -->\n```\n\nTail.\n"],
            'a tilde fence' => ["Prose.\n\n~~~\n<!-- carve: ::: note -->\n~~~\n\nTail.\n"],
            'an indented code block' => ["Prose.\n\n    <!-- carve: ::: note -->\n    body\n\nTail.\n"],
            'an inline code span' => ["A `<!-- carve: ::: note -->` span.\n"],
            'a raw block' => ["Prose.\n\n```=html\n<!-- carve: ::: note -->\n```\n"],
        ];
    }

    #[DataProvider('verbatimMarkerProvider')]
    public function testAMarkerShapedLineInACodeConstructComesBackVerbatim(string $source): void
    {
        $result = (new MarkdownToCarve())->convertWithFidelityReport($source);
        $this->assertStringContainsString('<!-- carve: ::: note -->', $result->value, 'the payload lost its marker-shaped line');
        $this->assertSame(0, preg_match('/^:{3,}/m', $result->value), 'a verbatim payload was read as a container');
        $codes = array_map(static fn ($diagnostic): string => $diagnostic->code, $result->diagnostics);
        $this->assertNotContains('carrier-markers-damaged', $codes);
    }

    /**
     * The control, and the diagnostic's own arithmetic: the set OUTSIDE the
     * fence is one unclosed opener, so it is damaged; the marker-shaped line
     * inside the fence is not its closer and must not balance it.
     */
    public function testADamagedSetOutsideAFenceCountsOnlyTheRealMarkers(): void
    {
        $result = (new MarkdownToCarve())->convertWithFidelityReport(
            "<!-- carve: ::: note -->\n\nBody.\n\n```md\n<!-- carve: ::: -->\n```\n",
        );
        $damaged = array_values(array_filter(
            $result->diagnostics,
            static fn ($diagnostic): bool => $diagnostic->code === 'carrier-markers-damaged',
        ));
        $this->assertCount(1, $damaged, 'the fenced line was counted as part of the set');
        $this->assertSame(0, preg_match('/^:{3,}/m', $result->value));
        $this->assertStringContainsString("```md\n<!-- carve: ::: -->\n```", $result->value);
    }

    public function testASoundSetReportsNoDamage(): void
    {
        $result = (new MarkdownToCarve())->convertWithFidelityReport(
            "<!-- carve: ::: note -->\nBody.\n\n<!-- carve: ::: -->\n",
        );
        $codes = array_map(static fn ($diagnostic): string => $diagnostic->code, $result->diagnostics);
        $this->assertNotContains('carrier-markers-damaged', $codes);
        $this->assertSame("::: note\nBody.\n:::\n", $result->value);
    }
}
