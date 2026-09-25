<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Converter\HtmlImportDiagnostic;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `style` reaches the report through the refusal policy every other attribute
 * goes through (markup-carve/carve#2267, clause `docs/html-import-contract.md`
 * under *A refused declaration in `style` is a refused attribute*, ticket
 * markup-carve/carve-php#2368).
 *
 * This engine already answered `attribute-preserved` here, which is why the CODE
 * is the one field a test of it cannot rest on: the code alone passed while the
 * class said `info` about a live `javascript:` URL and the message was this
 * engine's own wording rather than the clause's. So every row is asserted WHOLE
 * - code, severity, fidelity, confidence, path and message - which is also the
 * six fields the spec repo's cross-engine gate compares.
 */
class ARefusedDeclarationInStyleIsARefusedAttributeTest extends TestCase
{
    /**
     * The ticket's payload: a refused declaration on the kept element AND on a
     * descendant, each with its own reason.
     *
     * @var string
     */
    private const PAYLOAD = '<form style="background:url(javascript:x)" onclick="y()">'
        . '<p style="width:expression(alert(1))">a</p></form>';

    public function testBothStylesInThePayloadNameTheirOwnReason(): void
    {
        $result = (new HtmlToCarve(importMode: 'roundtrip'))->convertWithReport(self::PAYLOAD);

        $this->assertSame(
            [
                [
                    'attribute-preserved', 'error', 'preserved', 'exact', '/form[1]',
                    'Preserved style with a denied URL scheme in a declaration value on <form> in the raw HTML this element is kept as',
                ],
                [
                    'attribute-preserved', 'error', 'preserved', 'exact', '/form[1]',
                    'Preserved event-handler attribute onclick on <form> in the raw HTML this element is kept as',
                ],
                [
                    'raw-preserved', 'warning', 'degraded', 'exact', '/form[1]',
                    'Preserved unsupported <form> element as raw HTML',
                ],
                [
                    'attribute-preserved', 'error', 'preserved', 'exact', '/form[1]/p[1]',
                    'Preserved style with a construct the CSS sanitizer refuses on <p> inside the raw HTML <form> is kept as',
                ],
            ],
            $this->rows($result->diagnostics),
        );
    }

    /**
     * Nothing is removed. The ruling is about the report, not about the bytes.
     */
    public function testBothDeclarationsStayInTheOutput(): void
    {
        $value = (new HtmlToCarve(importMode: 'roundtrip'))->convertWithReport(self::PAYLOAD)->value;

        $this->assertStringContainsString('style="background:url(javascript:x)"', $value);
        $this->assertStringContainsString('style="width:expression(alert(1))"', $value);
    }

    /**
     * `style-unmapped` names a CSS mapping the kept bytes do not run.
     */
    public function testNoStyleUnmappedRowInsideTheKeptBytes(): void
    {
        $result = (new HtmlToCarve(importMode: 'roundtrip'))->convertWithReport(self::PAYLOAD);

        $this->assertNotContains(
            'style-unmapped',
            array_map(static fn (HtmlImportDiagnostic $d): string => $d->code, $result->diagnostics),
        );
    }

    /**
     * CONTROL FOR THE CLASS BOUNDARY. Without it a change that marks every kept
     * `style` an error passes the case above.
     */
    public function testBenignCssInKeptBytesStaysAtInfo(): void
    {
        $result = (new HtmlToCarve(importMode: 'roundtrip'))
            ->convertWithReport('<form style="color:red"><a href="/ok" style="color:blue">t</a></form>');

        $this->assertSame(
            [
                [
                    'attribute-preserved', 'info', 'preserved', 'exact', '/form[1]',
                    'Preserved style on <form> in the raw HTML this element is kept as',
                ],
                [
                    'raw-preserved', 'warning', 'degraded', 'exact', '/form[1]',
                    'Preserved unsupported <form> element as raw HTML',
                ],
                [
                    'attribute-preserved', 'info', 'preserved', 'exact', '/form[1]/a[1]',
                    'Preserved style on <a> inside the raw HTML <form> is kept as',
                ],
            ],
            $this->rows($result->diagnostics),
        );
    }

    /**
     * CONTROL FOR THE ROUTING. Outside kept bytes the CSS mapping does run, so
     * an unmapped declaration is still `style-unmapped` in both modes that map
     * CSS.
     *
     * @param string $mode
     */
    #[DataProvider('mappingModeProvider')]
    public function testAStyleOutsideKeptBytesStaysUnmapped(string $mode): void
    {
        $result = (new HtmlToCarve(importMode: $mode))->convertWithReport('<p style="color:red">x</p>');

        $this->assertSame(
            [['style-unmapped', 'info', 'degraded', 'exact', '/p[1]', 'CSS declarations may not have a Carve mapping']],
            $this->rows($result->diagnostics),
        );
        $this->assertSame("x\n", $result->value);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function mappingModeProvider(): array
    {
        return ['roundtrip' => ['roundtrip'], 'semantic' => ['semantic']];
    }

    /**
     * The reason comes from a closed set of two, so every value lands in one of
     * three buckets. `background:url(pic.png)` is the case that shows the two
     * error reasons are not the same test: the sanitizer blanks the value for
     * the construct, and no scheme in it is denied.
     *
     * @param string $declaration
     * @param string $severity
     * @param string $subject
     */
    #[DataProvider('declarationProvider')]
    public function testEachValueLandsInItsBucket(string $declaration, string $severity, string $subject): void
    {
        $result = (new HtmlToCarve(importMode: 'roundtrip'))
            ->convertWithReport('<form style="' . $declaration . '">t</form>');

        $this->assertSame(
            [
                'attribute-preserved', $severity, 'preserved', 'exact', '/form[1]',
                'Preserved ' . $subject . ' on <form> in the raw HTML this element is kept as',
            ],
            $this->rows($result->diagnostics)[0] ?? [],
        );
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function declarationProvider(): array
    {
        $denied = 'style with a denied URL scheme in a declaration value';
        $construct = 'style with a construct the CSS sanitizer refuses';

        return [
            'javascript in url()' => ['background:url(javascript:x)', 'error', $denied],
            'vbscript in url()' => ['background:url(vbscript:x)', 'error', $denied],
            'quoted javascript in url()' => ['background:url(\'javascript:x\')', 'error', $denied],
            'expression()' => ['width:expression(alert(1))', 'error', $construct],
            'a benign url()' => ['background:url(pic.png)', 'error', $construct],
            'a behavior binding' => ['behavior:url(x.htc)', 'error', $construct],
            // Read off the DECODED declarations, as the sanitizer reads them.
            // Off the raw bytes the two went out of step in both directions: a
            // denied URL inside a comment looked live, and an escaped one looked
            // like an unnamed construct.
            'an escaped scheme' => ['background:url(java\73 cript:x)', 'error', $denied],
            'an escaped url()' => ['background:u\72l(javascript:x)', 'error', $denied],
            'a url() behind a comment' => ['/*c*/background:url(javascript:y)', 'error', $denied],
            'an escaped expression()' => ['width:expr\65 ssion(alert(1))', 'error', $construct],
            // The reason set is CLOSED at two, so a value the sanitizer blanks
            // for its leading scheme rather than for a `url(...)` argument lands
            // in the second. Naming a third reason would invent a string the
            // clause does not pin, which is the thing it exists to prevent.
            'a leading denied scheme' => ['javascript:alert(1)', 'error', $construct],
            'a color' => ['color:red', 'info', 'style'],
            'an alignment' => ['text-align:left', 'info', 'style'],
            'a commented-out url()' => ['color:red;/*url(javascript:x)*/', 'info', 'style'],
            // The sanitizer answers `''` for an empty value as well as for a
            // blanked one, so an empty `style` is the case that reads refused
            // from the answer alone. It is one row like any other.
            'an empty value' => ['', 'info', 'style'],
            'whitespace only' => ['   ', 'info', 'style'],
        ];
    }

    /**
     * The class is the renderer's own answer, not a second reading of it: the
     * row is `error` exactly where the sanitizer blanks the value. This is what
     * the declaration table above would not catch on its own, since a table
     * pins the cases somebody thought of.
     *
     * @param string $declaration
     */
    #[DataProvider('declarationValueProvider')]
    public function testTheClassAgreesWithTheSanitizer(string $declaration): void
    {
        $result = (new HtmlToCarve(importMode: 'roundtrip'))
            ->convertWithReport('<form style="' . $declaration . '">t</form>');

        $this->assertSame(
            HtmlRenderer::baselineAttributeValue('style', $declaration) !== $declaration,
            $result->diagnostics[0]->severity === 'error',
            'the report and the sanitizer disagree about: ' . $declaration,
        );
    }

    /**
     * The same table, values only, so the invariant cannot drift from the cases
     * the bucket test pins.
     *
     * @return array<string, array{string}>
     */
    public static function declarationValueProvider(): array
    {
        return array_map(
            static fn (array $case): array => [$case[0]],
            self::declarationProvider(),
        );
    }

    /**
     * The inline arm keeps a raw span through its own walk.
     */
    public function testAStyleInTheBytesOfARawKeptSpan(): void
    {
        $result = (new HtmlToCarve(importMode: 'roundtrip'))
            ->convertWithReport('<p>a <output style="color:red"><b style="width:expression(1)">t</b></output> b</p>');

        $this->assertSame(
            [
                [
                    'attribute-preserved', 'info', 'preserved', 'exact', '/p[1]/output[2]',
                    'Preserved style on <output> in the raw HTML this element is kept as',
                ],
                [
                    'raw-preserved', 'warning', 'degraded', 'exact', '/p[1]/output[2]',
                    'Preserved unsupported <output> element as raw HTML',
                ],
                [
                    'attribute-preserved', 'error', 'preserved', 'exact', '/p[1]/output[2]/b[1]',
                    'Preserved style with a construct the CSS sanitizer refuses on <b> inside the raw HTML <output> is kept as',
                ],
            ],
            $this->rows($result->diagnostics),
        );
    }

    /**
     * A `style` row is ordered where the element spells the attribute, not by
     * the class the importer put it in.
     *
     * @param string $html
     * @param array<string> $expected
     */
    #[DataProvider('spellingOrderProvider')]
    public function testTheStyleRowFollowsTheSpelling(string $html, array $expected): void
    {
        $result = (new HtmlToCarve(importMode: 'roundtrip'))->convertWithReport($html);

        $this->assertSame(
            $expected,
            array_slice(array_map(static fn (HtmlImportDiagnostic $d): string => $d->message, $result->diagnostics), 0, 2),
        );
    }

    /**
     * @return array<string, array{string, array<string>}>
     */
    public static function spellingOrderProvider(): array
    {
        $style = 'Preserved style on <form> in the raw HTML this element is kept as';
        $handler = 'Preserved event-handler attribute onclick on <form> in the raw HTML this element is kept as';

        return [
            'style first' => ['<form style="color:red" onclick="a()">t</form>', [$style, $handler]],
            'handler first' => ['<form onclick="a()" style="color:red">t</form>', [$handler, $style]],
        ];
    }

    /**
     * `safe` and `semantic` UNWRAP the form instead of keeping it, so no bytes
     * are kept and the CSS mapping is the only reading - the state the routing
     * must not reach outside the exemption.
     *
     * @param string $mode
     */
    #[DataProvider('unwrappingModeProvider')]
    public function testAnUnwrappingModeReportsTheUnmappedDeclaration(string $mode): void
    {
        $result = (new HtmlToCarve(importMode: $mode))->convertWithReport(self::PAYLOAD);
        $codes = array_map(static fn (HtmlImportDiagnostic $d): string => $d->code, $result->diagnostics);
        $paths = array_values(array_map(
            static fn (HtmlImportDiagnostic $d): ?string => $d->path,
            array_filter($result->diagnostics, static fn (HtmlImportDiagnostic $d): bool => $d->code === 'style-unmapped'),
        ));

        $this->assertNotContains('attribute-preserved', $codes);
        $this->assertSame(['/form[1]', '/form[1]/p[1]'], $paths);
        $this->assertStringNotContainsString('expression(', $result->value);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unwrappingModeProvider(): array
    {
        return ['safe' => ['safe'], 'semantic' => ['semantic']];
    }

    /**
     * @param list<\MarkupCarve\Carve\Converter\HtmlImportDiagnostic> $diagnostics
     *
     * @return array<array<string>>
     */
    private function rows(array $diagnostics): array
    {
        return array_map(
            static fn (HtmlImportDiagnostic $d): array => [
                $d->code,
                $d->severity,
                $d->fidelity(),
                $d->confidence(),
                (string)$d->path,
                $d->message,
            ],
            $diagnostics,
        );
    }
}
