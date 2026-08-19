<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `<math>` imports through the three-tier lookup of carve#1210 D6.
 *
 * 1. An `<annotation>` declaring a TeX encoding, exactly, as a direct child of
 *    the element's own `<semantics>`.
 * 2. Else `alttext`, plus an `encoding-assumed` `info` saying the encoding was
 *    assumed.
 * 3. Else no TeX exists. `roundtrip` keeps the element verbatim; `safe` and
 *    `semantic` drop it and the report names it. The children are never
 *    concatenated: MathML's children are a token stream, and reading
 *    `<mfrac><mn>1</mn><mn>2</mn></mfrac>` as `12` turns one half into twelve.
 */
class MathMlImportsItsDeclaredTexTest extends TestCase
{
    protected function fixture(string $name): string
    {
        return trim((string)file_get_contents(__DIR__ . '/../../fixtures/html-import/' . $name . '.html'));
    }

    /**
     * A `<math>` element taken verbatim from the Wikipedia REST HTML for
     * "Mass-energy equivalence". Mathoid writes the same TeX into both tiers,
     * so what changes here is the report: the element used to spend nine
     * diagnostics describing span metadata for `<mrow>`, `<msup>`, `<mi>` and
     * the annotation itself, none of which is emitted, and none of which named
     * `<math>`. A lossless import now says nothing.
     */
    public function testWikipediaElementTakesItsAnnotationAndReportsNothing(): void
    {
        $result = (new HtmlToCarve())->convertWithReport('<p>Thus ' . $this->fixture('wikipedia-math') . '</p>');

        $this->assertSame('Thus $`{\displaystyle E=mc^{2}.}`$', trim($result->value));
        $this->assertSame([], $result->report()['diagnostics']);
    }

    /**
     * A `display="block"` element taken verbatim from the ar5iv HTML of
     * arXiv:1706.03762, LaTeXML output. `display="block"` selects the display
     * delimiter, and the TeX arrives byte for byte, backslashes and braces
     * intact. `intent` is the one thing this element loses, and it is reported:
     * stopping the walk at `<math>` stops the descent into the token stream,
     * not the element's own attributes.
     */
    public function testAr5ivBlockElementKeepsItsTexByteForByte(): void
    {
        $result = (new HtmlToCarve())->convertWithReport($this->fixture('ar5iv-math'));

        $this->assertSame(
            '$$`\mathrm{Attention}(Q,K,V)=\mathrm{softmax}(\frac{QK^{T}}{\sqrt{d_{k}}})V`$$',
            trim($result->value),
        );
        // `id` and `class` JOINED THE REPORT when the diagnostic started
        // reading the emitted document instead of predicting it
        // (carve-php#1346). Both are declared representable for every element,
        // and a mapped `<math>` emits `$$`…`$$` - a bare math block with no
        // attribute block on it - so this fixture's `id` and `class` really are
        // gone. They were dropped just as silently before; the drop is not new,
        // only the row saying so is.
        $this->assertSame(
            [
                [
                    'code' => 'attribute-dropped',
                    'message' => 'Dropped unsupported attribute id on <math>',
                    'severity' => 'info',
                    'path' => '/math[1]',
                ],
                [
                    'code' => 'attribute-dropped',
                    'message' => 'Dropped unsupported attribute class on <math>',
                    'severity' => 'info',
                    'path' => '/math[1]',
                ],
                [
                    'code' => 'attribute-dropped',
                    'message' => 'Dropped unsupported attribute intent on <math>',
                    'severity' => 'info',
                    'path' => '/math[1]',
                ],
            ],
            $result->report()['diagnostics'],
        );
    }

    /**
     * An active attribute on a mapped `<math>` is still reported. The report
     * walk stops descending at `<math>`, and stopping one line earlier would
     * have called an element carrying an event handler a lossless import.
     */
    public function testAnActiveAttributeOnAMappedMathIsStillReported(): void
    {
        $html = '<math onclick="evil()" style="color:red"><semantics><mrow><mi>x</mi></mrow>'
            . '<annotation encoding="application/x-tex">x</annotation></semantics></math>';

        $result = (new HtmlToCarve())->convertWithReport($html);

        $this->assertSame('$`x`$', trim($result->value));
        $this->assertSame(
            [['attribute-dropped', 'warning'], ['style-unmapped', 'info']],
            array_map(
                static fn (array $d): array => [$d['code'], $d['severity']],
                $result->report()['diagnostics'],
            ),
        );
    }

    /**
     * The ordering itself. Where the two tiers disagree, the declared encoding
     * wins: this importer used to take `alttext` first, so this element came
     * back as the assumed value.
     */
    public function testDeclaredAnnotationBeatsAlttextWhenTheyDisagree(): void
    {
        $html = '<math alttext="ASSUMED"><semantics><mrow><mi>x</mi></mrow>'
            . '<annotation encoding="application/x-tex">DECLARED</annotation></semantics></math>';

        $result = (new HtmlToCarve())->convertWithReport($html);

        $this->assertSame('$`DECLARED`$', trim($result->value));
        $this->assertSame([], $result->report()['diagnostics']);
    }

    /**
     * Tier 2, and the `info` that goes with it. MathML does not declare what
     * `alttext` holds, so reading it as TeX is an assumption the report has to
     * record.
     *
     * `encoding-assumed` is the code the spec added for this exact case
     * (`markup-carve/carve#1235`), and it files it apart from
     * `element-unwrapped` on purpose: unwrapping is a note about the input's
     * structure and loses no meaning, while an assumed encoding is a warning
     * about the OUTPUT - the math node this produces is only correct while the
     * guess is, and it may hold something that is not TeX at all. A consumer
     * told only that an element is gone cannot tell those apart, and that is
     * the one signal it could act on.
     *
     * The severity stays `info`, matching carve-js. The spec maps no code to a
     * severity, so raising this one would divide the engines over something
     * nothing rules on.
     */
    public function testAlttextAloneIsTakenAsTexWithAnInfo(): void
    {
        $result = (new HtmlToCarve())->convertWithReport('<p><math alttext="a + b"><mrow><mi>a</mi></mrow></math></p>');

        $this->assertSame('$`a + b`$', trim($result->value));
        $this->assertSame(
            [
                [
                    'code' => 'encoding-assumed',
                    'message' => 'Read <math> through its alttext: MathML does not declare the encoding of alttext, so TeX is assumed',
                    'severity' => 'info',
                    'path' => '/p[1]/math[1]',
                ],
            ],
            $result->report()['diagnostics'],
        );
    }

    /**
     * The case that settled the ruling. Hand-written presentation MathML with
     * no TeX anywhere: it used to import as `12`, a plausible wrong value that
     * survives review. It is dropped now, and the report names it.
     */
    public function testHandWrittenFractionIsDroppedRatherThanReadAsTwelve(): void
    {
        $result = (new HtmlToCarve())->convertWithReport('<p>Bare <math><mfrac><mn>1</mn><mn>2</mn></mfrac></math> here.</p>');

        $this->assertStringNotContainsString('12', $result->value);
        $this->assertStringNotContainsString('$', $result->value);
        $this->assertSame(
            [
                [
                    'code' => 'element-dropped',
                    'message' => 'Dropped <math>: no TeX annotation and no alttext, and its children are a token stream, not an equation',
                    'severity' => 'warning',
                    // `math[2]`, not `math[1]`: the `Bare ` text ahead of it is
                    // the paragraph's first child node and takes the number.
                    'path' => '/p[1]/math[2]',
                ],
            ],
            $result->report()['diagnostics'],
        );
    }

    /**
     * MathType's own encoding, which is not TeX and must never be read as it.
     */
    public function testMathTypeAnnotationIsNotTex(): void
    {
        $html = '<p>Bare <math><semantics><mrow><mn>1</mn></mrow>'
            . '<annotation encoding="MathType-MTEF">MTEFgarbage</annotation></semantics></math> here.</p>';

        $result = (new HtmlToCarve())->convertWithReport($html);

        $this->assertStringNotContainsString('MTEF', $result->value);
        $this->assertStringNotContainsString('$', $result->value);
        $this->assertSame(['element-dropped'], array_column($result->report()['diagnostics'], 'code'));
    }

    /**
     * The substring bug the exact match replaces. `tex` is a substring of
     * `text/plain`, so a plain-text payload used to arrive as an equation.
     */
    public function testPlainTextEncodingIsNotTex(): void
    {
        $html = '<p><math><semantics><mrow><mn>1</mn></mrow>'
            . '<annotation encoding="text/plain">one over two</annotation></semantics></math></p>';

        $result = (new HtmlToCarve())->convertWithReport($html);

        $this->assertStringNotContainsString('one over two', $result->value);
        $this->assertSame(['element-dropped'], array_column($result->report()['diagnostics'], 'code'));
    }

    /**
     * The recursive-lookup bug. `getElementsByTagName()` reaches the whole
     * subtree, so an `<annotation>` buried in an `<annotation-xml>` payload
     * used to answer as if the element had declared it.
     */
    public function testAnnotationInsideAnAnnotationXmlPayloadDoesNotLeak(): void
    {
        $html = '<p><math><semantics><mrow><mn>1</mn></mrow>'
            . '<annotation-xml encoding="application/xhtml+xml">'
            . '<annotation encoding="application/x-tex">LEAK</annotation>'
            . '</annotation-xml></semantics></math></p>';

        $result = (new HtmlToCarve())->convertWithReport($html);

        $this->assertStringNotContainsString('LEAK', $result->value);
        $this->assertSame(['element-dropped'], array_column($result->report()['diagnostics'], 'code'));
    }

    /**
     * The other TeX encodings the ruling names, matched case-insensitively.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function texEncodingProvider(): array
    {
        return [
            'application/x-tex' => ['application/x-tex', '$`E`$'],
            'text/x-tex' => ['text/x-tex', '$`E`$'],
            'LaTeX' => ['LaTeX', '$`E`$'],
            'uppercased' => ['APPLICATION/X-TEX', '$`E`$'],
        ];
    }

    /**
     * @param string $encoding
     * @param string $expected
     */
    #[DataProvider('texEncodingProvider')]
    public function testEveryDeclaredTexEncodingIsAccepted(string $encoding, string $expected): void
    {
        $html = '<math><semantics><mrow><mi>x</mi></mrow>'
            . '<annotation encoding="' . $encoding . '">E</annotation></semantics></math>';

        $this->assertSame($expected, trim((new HtmlToCarve())->convert($html)));
    }

    /**
     * The tier-3 arm `roundtrip` takes. This is not a control: the ruling
     * records `roundtrip` as already raw-preserving the element, and this
     * importer did not - it concatenated the children in every mode, so a
     * trusted round trip lost the element exactly as the untrusted ones did.
     * It keeps the whole element as a raw-HTML inline now, which is the only
     * mode where nothing has to be thrown away.
     */
    public function testRoundtripKeepsTheWholeElementInsteadOfDroppingIt(): void
    {
        $result = (new HtmlToCarve(trustedRoundTrip: true))
            ->convertWithReport('<p>Bare <math><mfrac><mn>1</mn><mn>2</mn></mfrac></math> here.</p>');

        $this->assertSame(
            'Bare `<math><mfrac><mn>1</mn><mn>2</mn></mfrac></math>`{=html} here.',
            trim($result->value),
        );
        $this->assertSame([], $result->report()['diagnostics']);
    }

    /**
     * Control: a document with no `<math>` at all neither loses content nor
     * gains a math diagnostic.
     */
    public function testADocumentWithoutMathIsUnaffected(): void
    {
        $result = (new HtmlToCarve())->convertWithReport('<p>Bare 1/2 here.</p>');

        $this->assertSame('Bare 1/2 here.', trim($result->value));
        $this->assertSame([], $result->report()['diagnostics']);
    }

    /**
     * The code this importer emits has to be one the published schema admits,
     * read out of the schema rather than copied into a list here. This is what
     * a ninth code of the importer's own broke, and it is read from the pinned
     * `tests/spec` so a code that leaves the enum fails here rather than in a
     * consumer.
     *
     * The second assertion is the one that keeps the first honest: a test that
     * only checked membership would pass if the arm went back to any of the
     * other admitted codes.
     */
    public function testTheAssumedEncodingCodeIsOneThePublishedSchemaAdmits(): void
    {
        $schemaJson = file_get_contents(dirname(__DIR__, 2) . '/spec/resources/html-import-schema.json');
        $this->assertNotFalse($schemaJson);
        $schema = json_decode($schemaJson, true, flags: JSON_THROW_ON_ERROR);
        $codes = $schema['properties']['diagnostics']['items']['properties']['code']['enum'];
        $severities = $schema['properties']['diagnostics']['items']['properties']['severity']['enum'];

        $this->assertContains('encoding-assumed', $codes);

        $report = (new HtmlToCarve())
            ->convertWithReport('<p><math alttext="a + b"><mrow><mi>a</mi></mrow></math></p>')
            ->report();

        $this->assertSame(['encoding-assumed'], array_column($report['diagnostics'], 'code'));
        foreach ($report['diagnostics'] as $diagnostic) {
            $this->assertContains($diagnostic['code'], $codes);
            $this->assertContains($diagnostic['severity'], $severities);
        }
    }

    /**
     * The distinction the spec draws, stated as a test rather than left to the
     * code string alone. Tier 2 unwraps nothing that the report should call an
     * unwrapping: the loss it names is about the OUTPUT, whose content is TeX
     * only while the guess holds.
     *
     * The tier-1 and tier-3 rows beside it are controls. If the arm ever
     * reported the assumption for a tier that did not make one, or stopped
     * reporting it for the tier that did, exactly one of these three moves.
     */
    public function testOnlyTheTierThatGuessesReportsAnAssumedEncoding(): void
    {
        $importer = new HtmlToCarve();
        $declared = '<p><math alttext="ASSUMED"><semantics><mrow><mi>x</mi></mrow>'
            . '<annotation encoding="application/x-tex">DECLARED</annotation></semantics></math></p>';
        $assumed = '<p><math alttext="a + b"><mrow><mi>a</mi></mrow></math></p>';
        $neither = '<p><math><mfrac><mn>1</mn><mn>2</mn></mfrac></math></p>';

        $this->assertSame([], array_column($importer->convertWithReport($declared)->report()['diagnostics'], 'code'));
        $this->assertSame(
            ['encoding-assumed'],
            array_column($importer->convertWithReport($assumed)->report()['diagnostics'], 'code'),
        );
        $this->assertSame(
            ['element-dropped'],
            array_column($importer->convertWithReport($neither)->report()['diagnostics'], 'code'),
        );
    }
}
