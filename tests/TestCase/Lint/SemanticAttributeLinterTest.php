<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Lint;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Extension\SemanticSpanExtension;
use MarkupCarve\Carve\Lint\LintWarning;
use MarkupCarve\Carve\Lint\SemanticAttributeLinter;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SemanticAttributeLinterTest extends TestCase
{
    /**
     * @param string $source
     * @param array<string, mixed> $options
     *
     * @return list<string>
     */
    private function rules(string $source, array $options = []): array
    {
        return array_map(
            static fn (LintWarning $warning): string => $warning->rule,
            (new SemanticAttributeLinter())->lint($source, $options),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function withExtension(): array
    {
        return ['extensions' => [new SemanticSpanExtension()]];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function coreValueIgnoredProvider(): array
    {
        // `kbd` is the whole core-only population: `abbr` and `time` map their
        // value to `title` and `datetime`, and the other four are not elements
        // at all without the extension.
        return [
            'bare span' => ['[x]{kbd="V"}'],
            'inside a paragraph' => ['before [x]{kbd="Ctrl"} after'],
            'combined with a class' => ['[x]{.k kbd="V"}'],
        ];
    }

    #[DataProvider('coreValueIgnoredProvider')]
    public function testReportsADiscardedValueOnACoreName(string $source): void
    {
        $this->assertSame(
            [SemanticAttributeLinter::RULE_SEMANTIC_ATTRIBUTE_VALUE_IGNORED],
            $this->rules($source),
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function valueKeptProvider(): array
    {
        return [
            'abbr maps to title' => ['[x]{abbr="V"}'],
            'time maps to datetime' => ['[x]{time="V"}'],
            'a bare name loses nothing' => ['[x]{kbd}'],
            'an ordinary attribute' => ['[x]{foo="V"}'],
        ];
    }

    #[DataProvider('valueKeptProvider')]
    public function testStaysQuietWhenNoValueIsLost(string $source): void
    {
        $this->assertSame([], $this->rules($source));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function outsideSpanProvider(): array
    {
        return [
            'code span' => ['`c`{kbd}', 'code'],
            'link' => ['[t](http://e.com){kbd}', 'link'],
            'image' => ['![a](i.png){kbd}', 'image'],
            'paragraph' => ["{kbd}\nPara", 'paragraph'],
            'heading' => ["{kbd}\n# H", 'heading'],
            'block quote' => ["{kbd}\n> q", 'block_quote'],
            'list' => ["{kbd}\n- a", 'list'],
            'table row' => ["| a |{kbd}\n|---|\n| b |", 'table_row'],
            'strong' => ['*b*{kbd}', 'strong'],
        ];
    }

    #[DataProvider('outsideSpanProvider')]
    public function testReportsAReservedNameOutsideASpan(string $source, string $type): void
    {
        $warnings = (new SemanticAttributeLinter())->lint($source);
        $this->assertCount(1, $warnings);
        $this->assertSame(
            SemanticAttributeLinter::RULE_SEMANTIC_ATTRIBUTE_OUTSIDE_SPAN,
            $warnings[0]->rule,
        );
        // The message names the host, because "this only works on a span" is
        // not actionable without saying what it landed on instead.
        $this->assertStringContainsString("on {$type} it stays a raw attribute", $warnings[0]->message);
    }

    /**
     * The output the message describes, measured rather than asserted from
     * memory: if the renderer ever stops emitting the raw attribute the
     * diagnostic becomes a lie.
     */
    #[DataProvider('outsideSpanProvider')]
    public function testTheReportedFormReallyRendersTheRawAttribute(string $source, string $type): void
    {
        $this->assertNotSame('', $type);
        $this->assertStringContainsString('kbd=""', (new CarveConverter())->convert($source));
    }

    /**
     * The message quotes the attribute the renderer WILL emit, value and all. A
     * fixed `name=""` would be false for every host that carries a value, which
     * is the shape most worth reporting - and a diagnostic that states output
     * the renderer does not produce is worse than none.
     */
    public function testTheMessageQuotesTheAttributeTheRendererEmits(): void
    {
        $warnings = (new SemanticAttributeLinter())->lint('`c`{kbd="keyboard"}');

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('renders as kbd="keyboard".', $warnings[0]->message);
        $this->assertStringContainsString(
            'kbd="keyboard"',
            (new CarveConverter())->convert('`c`{kbd="keyboard"}'),
        );
    }

    /**
     * And the value is escaped the way the renderer escapes it, not printed raw.
     */
    public function testTheQuotedValueIsEscapedLikeTheOutput(): void
    {
        $source = "{kbd=\"a\\\"b\"}\n> q";
        $warnings = (new SemanticAttributeLinter())->lint($source);

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('renders as kbd="a&quot;b".', $warnings[0]->message);
        $this->assertStringContainsString(
            'kbd="a&quot;b"',
            (new CarveConverter())->convert($source),
        );
    }

    /**
     * `cite` IS a URL attribute of `blockquote` in HTML, so a quote carrying one
     * is the author getting exactly what they asked for. This is the carve-out
     * the port shares with carve-js (markup-carve/carve-js#1022); without it the
     * two engines diverge on the first quote with a citation URL.
     */
    public function testCiteOnABlockQuoteIsNotReported(): void
    {
        $source = "{cite=\"https://example.org/dune\"}\n> q";

        $this->assertSame([], $this->rules($source, $this->withExtension()));
    }

    /**
     * The control above is only worth anything if the rule was RUNNING. With the
     * extension registered `cite` is an element name, so every other host
     * reports - and the same document with `kbd` instead reports on the quote.
     */
    public function testTheBlockQuoteCarveOutIsNarrow(): void
    {
        $options = $this->withExtension();

        $this->assertSame(
            [SemanticAttributeLinter::RULE_SEMANTIC_ATTRIBUTE_OUTSIDE_SPAN],
            $this->rules('`c`{cite="https://example.org/dune"}', $options),
            'cite outside a span and outside a quote still reports',
        );
        $this->assertSame(
            [SemanticAttributeLinter::RULE_SEMANTIC_ATTRIBUTE_OUTSIDE_SPAN],
            $this->rules("{kbd}\n> q", $options),
            'another reserved name on the same quote still reports',
        );
    }

    /**
     * And the output the carve-out protects, measured.
     */
    public function testCiteOnABlockQuoteRendersTheAttribute(): void
    {
        $this->assertStringContainsString(
            '<blockquote cite="https://example.org/dune">',
            (new CarveConverter())->convert("{cite=\"https://example.org/dune\"}\n> q"),
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function extensionOnlyNameProvider(): array
    {
        return [
            'samp' => ['samp'],
            'var' => ['var'],
            'cite' => ['cite'],
        ];
    }

    /**
     * PART 9 §9 leaves these four to the SemanticSpan extension. In a core
     * render they are ORDINARY attributes whose value reaches the output intact,
     * so reporting them would report a loss that is not happening.
     */
    #[DataProvider('extensionOnlyNameProvider')]
    public function testAnExtensionNameIsQuietInACoreRender(string $name): void
    {
        $this->assertSame([], $this->rules("[x]{{$name}=\"V\"}"));
        $this->assertSame([], $this->rules("`c`{{$name}}"));
        $this->assertStringContainsString(
            "{$name}=\"V\"",
            (new CarveConverter())->convert("[x]{{$name}=\"V\"}"),
            'the value really does reach a core render',
        );
    }

    #[DataProvider('extensionOnlyNameProvider')]
    public function testAnExtensionNameReportsOnceTheExtensionIsRegistered(string $name): void
    {
        $options = $this->withExtension();

        $this->assertSame(
            [SemanticAttributeLinter::RULE_SEMANTIC_ATTRIBUTE_VALUE_IGNORED],
            $this->rules("[x]{{$name}=\"V\"}", $options),
        );
        $this->assertSame(
            [SemanticAttributeLinter::RULE_SEMANTIC_ATTRIBUTE_OUTSIDE_SPAN],
            $this->rules("`c`{{$name}}", $options),
        );
    }

    /**
     * `dfn` is the extension's one value-keeping name, so it is the shape that
     * separates "is an element" from "loses its value".
     */
    public function testDfnKeepsItsValueUnderTheExtension(): void
    {
        $this->assertSame([], $this->rules('[x]{dfn="V"}', $this->withExtension()));
    }

    /**
     * The linter's `NAMES_KEEPING_A_VALUE` is a copy of a mapping that lives in
     * `HtmlRenderer::renderSpan()`. Measure the renderer against the rule for
     * every reserved name so the copy cannot drift unnoticed: a name that starts
     * carrying its value while the linter still calls it discarded reports a
     * loss that stopped happening, and the reverse goes unreported.
     */
    public function testTheDiscardedClassificationMatchesWhatTheRendererEmits(): void
    {
        $options = $this->withExtension();
        foreach (HtmlRenderer::EXTENDED_SEMANTIC_SPAN_ORDER as $name) {
            $converter = new CarveConverter();
            $converter->addExtension(new SemanticSpanExtension());
            $html = $converter->convert("[x]{{$name}=\"VALUE\"}");
            $valueReachesTheOutput = str_contains($html, 'VALUE');
            $reported = $this->rules("[x]{{$name}=\"VALUE\"}", $options) !== [];

            $this->assertSame(
                !$valueReachesTheOutput,
                $reported,
                "{$name}: renderer emitted {$html}",
            );
        }
    }

    /**
     * Retired names (markup-carve/carve#1162 took `code` and `mark` out of the
     * registry) are ordinary attributes now, so neither rule applies to them.
     */
    public function testARetiredNameIsNotReserved(): void
    {
        $this->assertSame([], $this->rules('[x]{code="V"}', $this->withExtension()));
        $this->assertSame([], $this->rules('`c`{mark}', $this->withExtension()));
    }

    public function testReportsTheNodePosition(): void
    {
        $warnings = (new SemanticAttributeLinter())->lint("one\n\nbefore [x]{kbd=\"V\"} after");

        $this->assertCount(1, $warnings);
        $this->assertSame(3, $warnings[0]->line);
        $this->assertSame(8, $warnings[0]->column);
    }

    /**
     * `LintWarning` offsets are BYTES, like every other rule this package emits.
     * A multi-byte character before the finding is the only thing that tells
     * byte offsets and the AST's codepoint offsets apart.
     */
    public function testOffsetsAreBytesIntoTheSourceAsGiven(): void
    {
        $source = "Z\u{00E4}h [x]{kbd=\"V\"}";
        $warnings = (new SemanticAttributeLinter())->lint($source);

        $this->assertCount(1, $warnings);
        $this->assertSame(
            '[x]{kbd="V"}',
            substr($source, $warnings[0]->start, $warnings[0]->end - $warnings[0]->start),
        );
        $this->assertSame(5, $warnings[0]->start);
    }

    public function testAnEmptyDocumentReportsNothing(): void
    {
        $this->assertSame([], $this->rules(''));
        $this->assertSame([], $this->rules("Plain text with no attributes.\n"));
    }

    public function testIgnoresNonExtensionsInTheOption(): void
    {
        $options = ['extensions' => ['SemanticSpanExtension', 42, null]];

        $this->assertSame([], $this->rules('[x]{cite="V"}', $options));
    }
}
