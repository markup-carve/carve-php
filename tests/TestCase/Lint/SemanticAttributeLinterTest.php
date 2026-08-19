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
     * Every host shape this rule reports on, each carrying a VALUE.
     *
     * The rule was written against a code span, and the ticket named one. The
     * walk reaches far more than that: these 22 shapes resolve to 20 distinct
     * node types, and the message was wrong on all 20 the moment the value was
     * one the renderer does not write verbatim. Two shapes fold into a type
     * another shape already covers (a list item into `list`, an autolink into
     * `link`) and are kept because they reach it by a different parse.
     *
     * 20 IS A MEASURED FLOOR, NOT A CEILING. It is the population that reports
     * today; a node type that starts accepting attributes belongs here too.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function outsideSpanWithAValueProvider(): array
    {
        return [
            'code span' => ['`c`{kbd="K"}', 'code'],
            'link' => ['[t](http://e.com){kbd="K"}', 'link'],
            'autolink' => ['<http://e.com>{kbd="K"}', 'link'],
            'image' => ['![a](i.png){kbd="K"}', 'image'],
            'paragraph' => ["{kbd=\"K\"}\nPara", 'paragraph'],
            'heading' => ["{kbd=\"K\"}\n# H", 'heading'],
            'block quote' => ["{kbd=\"K\"}\n> q", 'block_quote'],
            'list' => ["{kbd=\"K\"}\n- a", 'list'],
            'list item' => ["- a\n{kbd=\"K\"}\n- b", 'list'],
            'table' => ["{kbd=\"K\"}\n| a |\n|---|\n| b |", 'table'],
            'table row' => ["| a |{kbd=\"K\"}\n|---|\n| b |", 'table_row'],
            'code block' => ["{kbd=\"K\"}\n```\nx\n```", 'code_block'],
            'div' => ["{kbd=\"K\"}\n::: d\nx\n:::", 'div'],
            'thematic break' => ["{kbd=\"K\"}\n---", 'thematic_break'],
            'strong' => ['*b*{kbd="K"}', 'strong'],
            'underline' => ['_u_{kbd="K"}', 'underline'],
            'highlight' => ['=h={kbd="K"}', 'highlight'],
            'superscript' => ['{^s^}{kbd="K"}', 'superscript'],
            'subscript' => ['{,s,}{kbd="K"}', 'subscript'],
            'symbol' => [':smile:{kbd="K"}', 'symbol'],
            'literal inline' => ['!`v`{kbd="K"}', 'literal_inline'],
            'footnote reference' => ["x[^f]{kbd=\"K\"}\n\n[^f]: n", 'footnote_ref'],
        ];
    }

    /**
     * The load-bearing test for markup-carve/carve-js#1058, over every host.
     *
     * It does NOT compare the message against a second copy of the expected
     * value. It reads the attribute back OUT of the message and asserts the
     * rendered HTML contains exactly that, so a message naming anything the
     * renderer does not write fails it, whatever the reason. Run twice: once
     * with a value the renderer writes verbatim, and once with a value its
     * sanitizer blanks - which is the half that was wrong, and the half a test
     * written from the authored text would have got backwards.
     *
     * @param string $source
     * @param string $type
     */
    #[DataProvider('outsideSpanWithAValueProvider')]
    public function testTheQuotedAttributeIsTheOneTheRenderEmits(string $source, string $type): void
    {
        foreach (['K', 'javascript:alert(1)'] as $authored) {
            $document = str_replace('kbd="K"', sprintf('kbd="%s"', $authored), $source);
            $message = $this->onlyOutsideSpanMessage($document);

            $this->assertStringContainsString("on {$type} it stays a raw attribute", $message);
            $this->assertSame(
                1,
                preg_match('/renders as (kbd="[^"]*")\.$/', $message, $quoted),
                $message,
            );
            $this->assertStringContainsString(
                $quoted[1],
                (new CarveConverter())->convert($document),
                sprintf('%s: the message names an attribute the render does not contain', $document),
            );
        }
    }

    /**
     * The provider above is only worth its length if it really is the whole
     * population. A shape that folded into a type another shape already covers
     * would quietly shrink it.
     */
    public function testEveryHostTypeTheRuleReportsOnIsCovered(): void
    {
        $types = [];
        foreach (self::outsideSpanWithAValueProvider() as [$source, $type]) {
            $warnings = (new SemanticAttributeLinter())->lint($source);
            $this->assertNotSame([], $warnings, $source);
            $types[$type] = true;
        }

        $this->assertCount(20, $types, 'covered host types: ' . implode(', ', array_keys($types)));
    }

    /**
     * The exact bytes, so a later edit cannot satisfy the reader above by
     * quoting something merely present in the output - an `alt` or an `href`
     * would pass a containment check too.
     *
     * @param string $rendered
     *
     * @return string
     */
    private function outsideSpanMessage(string $rendered): string
    {
        return sprintf(
            '"kbd" is a semantic span attribute (PART 9 %s10) and only applies to an ordinary '
                . '[content]{attrs} span; on code it stays a raw attribute and renders as kbd="%s".',
            "\u{00A7}",
            $rendered,
        );
    }

    /**
     * @param string $source
     *
     * @return string
     */
    private function onlyOutsideSpanMessage(string $source): string
    {
        $messages = [];
        foreach ((new SemanticAttributeLinter())->lint($source) as $warning) {
            if ($warning->rule === SemanticAttributeLinter::RULE_SEMANTIC_ATTRIBUTE_OUTSIDE_SPAN) {
                $messages[] = $warning->message;
            }
        }
        $this->assertCount(1, $messages, $source);

        return $messages[0];
    }

    /**
     * The empty value is the TRUE one here: the sanitizer blanks a dangerous
     * scheme before the attribute is written. Quoting the authored text would be
     * the same defect as the fixed `kbd=""` was, pointing the other way.
     */
    public function testTheSanitizerRunsBeforeTheValueIsQuoted(): void
    {
        $source = '`c`{kbd="javascript:alert(1)"}';

        $this->assertSame($this->outsideSpanMessage(''), $this->onlyOutsideSpanMessage($source));
        $this->assertSame(
            '<p><code kbd="">c</code></p>',
            trim((new CarveConverter())->convert($source)),
        );
    }

    /**
     * And it is not over-applied: `url(x)` is only dangerous in a `style`, so on
     * any other name the renderer writes it and the message must say so.
     */
    public function testAValueTheSanitizerLeavesAloneIsQuotedInFull(): void
    {
        $this->assertSame(
            $this->outsideSpanMessage('url(x)'),
            $this->onlyOutsideSpanMessage('`c`{kbd="url(x)"}'),
        );
    }

    public function testAValueOfExactlyTheLimitIsQuotedWhole(): void
    {
        $value = str_repeat('x', 120);

        $this->assertSame(
            $this->outsideSpanMessage($value),
            $this->onlyOutsideSpanMessage(sprintf('`c`{kbd="%s"}', $value)),
        );
    }

    public function testOneCharacterPastTheLimitIsCutAndMarked(): void
    {
        $this->assertSame(
            $this->outsideSpanMessage(str_repeat('x', 120) . "\u{2026}"),
            $this->onlyOutsideSpanMessage(sprintf('`c`{kbd="%s"}', str_repeat('x', 121))),
        );
    }

    /**
     * Cutting the ESCAPED text could land inside an entity and quote `&qu` at an
     * author who wrote a quote. Each character here escapes to five, so a cut
     * applied after escaping shows entity wreckage instead of 120 ampersands.
     */
    public function testTheCutHappensBeforeEscapingSoNoEntityIsSplit(): void
    {
        $this->assertSame(
            $this->outsideSpanMessage(str_repeat('&amp;', 120) . "\u{2026}"),
            $this->onlyOutsideSpanMessage(sprintf('`c`{kbd="%s"}', str_repeat('&', 200))),
        );
    }

    /**
     * And the sanitizer reads the WHOLE value, not the cut prefix.
     *
     * THE PADDING IS THE TEST. A dangerous scheme sits at the front, so cutting
     * a `javascript:…` payload first leaves the scheme in the prefix and the
     * sanitizer still blanks it - that fixture passes whichever way round the
     * two run and pins nothing. The scheme has to be pushed PAST the cut for the
     * orders to differ: the renderer strips leading whitespace before reading
     * the scheme and blanks the value, while a cut-first linter sees 120 spaces,
     * no colon, and quotes them at an author whose attribute rendered empty.
     *
     * The padding is built by repetition rather than written out, so no
     * formatter can rewrite the run and leave the test passing while testing
     * nothing.
     */
    public function testTheWholeValueIsSanitizedNotTheCutPrefix(): void
    {
        $padding = str_repeat(' ', 200);
        $this->assertSame(200, strlen($padding), 'the padding must outrun the cut to mean anything');
        $this->assertSame('20', bin2hex($padding[0]), 'the padding must be spaces, not a rewritten tab');

        $source = sprintf('`c`{kbd="%sjavascript:alert(1)"}', $padding);

        $this->assertSame($this->outsideSpanMessage(''), $this->onlyOutsideSpanMessage($source));
        $this->assertStringContainsString(
            'kbd=""',
            (new CarveConverter())->convert($source),
            'the renderer really does blank it, padding and all',
        );

        // And the plain shape, where the scheme is inside the cut either way.
        $this->assertSame(
            $this->outsideSpanMessage(''),
            $this->onlyOutsideSpanMessage(sprintf('`c`{kbd="javascript:%s"}', str_repeat('a', 200))),
        );
    }

    /**
     * The cut counts CODEPOINTS, not bytes. Each character here is four bytes,
     * so a byte cut would slice a UTF-8 sequence in half and quote a broken
     * character back at the author.
     */
    public function testTheCutCountsCodepointsNotBytes(): void
    {
        $emoji = "\u{1F600}";

        $this->assertSame(
            $this->outsideSpanMessage(str_repeat($emoji, 120) . "\u{2026}"),
            $this->onlyOutsideSpanMessage(sprintf('`c`{kbd="%s"}', str_repeat($emoji, 200))),
        );
    }

    /**
     * The two spellings the message has always had, kept as exact bytes now that
     * everything around them moved: the boolean form really does render an empty
     * value, and an authored one really does reach the output.
     */
    public function testTheBooleanAndAuthoredFormsAreBothQuotedExactly(): void
    {
        $this->assertSame($this->outsideSpanMessage(''), $this->onlyOutsideSpanMessage('`c`{kbd}'));
        $this->assertSame(
            '<p><code kbd="">c</code></p>',
            trim((new CarveConverter())->convert('`c`{kbd}')),
        );

        $this->assertSame(
            $this->outsideSpanMessage('keyboard'),
            $this->onlyOutsideSpanMessage('`c`{kbd="keyboard"}'),
        );
        $this->assertSame(
            '<p><code kbd="keyboard">c</code></p>',
            trim((new CarveConverter())->convert('`c`{kbd="keyboard"}')),
        );
    }

    /**
     * The sibling rule was checked for the same assumption and does not carry
     * it: its message interpolates the NAME twice and never a value, so there is
     * nothing in it for the renderer to contradict.
     *
     * What it does assert is that the value reaches no output. That is pinned
     * here for every reserved name that loses one, rather than for the single
     * name the rule was written against.
     *
     * @param string $name
     */
    #[DataProvider('valueLosingNameProvider')]
    public function testTheValueIgnoredMessageNamesNoValue(string $name): void
    {
        $source = sprintf('[x]{%s="LOSTVALUE"}', $name);
        $options = $this->withExtension();

        $messages = array_map(
            static fn (LintWarning $warning): string => $warning->message,
            (new SemanticAttributeLinter())->lint($source, $options),
        );

        $expected = sprintf(
            'Value on the semantic attribute "%s" is discarded: it selects the <%s> element '
                . 'and reaches no output. Only abbr, dfn and time carry a value (as title or datetime).',
            $name,
            $name,
        );

        $this->assertSame([$expected], $messages);

        $converter = new CarveConverter();
        $converter->addExtension(new SemanticSpanExtension());
        $this->assertStringNotContainsString('LOSTVALUE', $converter->convert($source));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function valueLosingNameProvider(): array
    {
        return [
            'kbd' => ['kbd'],
            'samp' => ['samp'],
            'var' => ['var'],
            'cite' => ['cite'],
        ];
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

    /**
     * An option that is not a list at all reads as no extensions rather than
     * throwing: this mirrors `MarkdownHabitLinter`, where an unusable option is
     * ignored on the programmatic API because the caller has a type checker.
     */
    public function testANonIterableExtensionOptionReadsAsACoreRender(): void
    {
        $this->assertSame([], $this->rules('[x]{cite="V"}', ['extensions' => 'SemanticSpanExtension']));
        $this->assertSame(
            [SemanticAttributeLinter::RULE_SEMANTIC_ATTRIBUTE_VALUE_IGNORED],
            $this->rules('[x]{kbd="V"}', ['extensions' => 'SemanticSpanExtension']),
            'the core rules still run',
        );
    }
}
