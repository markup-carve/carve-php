<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * THE REPORT READS THE OUTPUT, NOT A TAG ROSTER (markup-carve/carve#2261).
 *
 * Mark ruled #2261 with "`roundtrip` keeps the bytes", and the clause that
 * merged from it (markup-carve/carve#2263, `docs/html-import-contract.md` under
 * *Modes*) makes the kept bytes an exception to "all modes remove": they stay
 * whole and every refused attribute in them is reported as
 * `attribute-preserved` instead. So the fix owed here is the REPORT.
 *
 * The report had a roster of its own for which elements `roundtrip` keeps, and a
 * roster is a second copy of a decision the conversion already made. It had
 * drifted from it in both directions, and both readings are false statements:
 *
 * - `attribute-dropped` over an event handler and a `javascript:` destination
 *   that are LIVE in the kept bytes. A drop reported beside a success is the
 *   row `markup-carve/carve-js#1468` removed.
 * - `attribute-preserved` at `error` about a handler the conversion had in fact
 *   removed, which sends a reader looking for a danger that is not there.
 *
 * THE SWEEP IS THE POINT. A single tag would have passed before the fix for
 * nine of the tags below, so each case asserts the biconditional - the report
 * says `raw-preserved` exactly when the bytes are in the output - rather than a
 * row list per tag. `nav` is in the provider as the negative control that makes
 * the sweep able to fail: it genuinely unwraps, so its drop is a real drop.
 */
class KeptBytesAreNeverReportedAsDroppedTest extends TestCase
{
    /**
     * The payload from the ticket, whose `<form>` has no Carve spelling.
     *
     * @var string
     */
    private const PAYLOAD = '<form style="background:url(javascript:x)" onclick="y()">'
        . '<a href="javascript:alert(1)">t</a></form>';

    public function testThePayloadNamesEveryLiveAttributeInTheKeptBytes(): void
    {
        $result = (new HtmlToCarve(importMode: 'roundtrip'))->convertWithReport(self::PAYLOAD);

        $this->assertStringContainsString('<form style=', $result->value);
        $this->assertSame(
            [
                ['attribute-preserved', 'info', '/form[1]', 'Preserved attribute style on <form> in the raw HTML this element is kept as'],
                ['attribute-preserved', 'error', '/form[1]', 'Preserved event-handler attribute onclick on <form> in the raw HTML this element is kept as'],
                ['raw-preserved', 'warning', '/form[1]', 'Preserved unsupported <form> element as raw HTML'],
                ['attribute-preserved', 'error', '/form[1]/a[1]', 'Preserved href with a denied URL scheme on <a> inside the raw HTML <form> is kept as'],
            ],
            $this->rows($result->diagnostics),
        );
    }

    /**
     * No row may call a drop what the output kept, whatever the element is.
     *
     * @param string $tag
     * @param bool $keepsBytes
     */
    #[DataProvider('tagProvider')]
    public function testTheReportAgreesWithTheOutput(string $tag, bool $keepsBytes): void
    {
        $html = '<' . $tag . ' onclick="a()"><a href="javascript:alert(1)">t</a></' . $tag . '>';
        $result = (new HtmlToCarve(importMode: 'roundtrip'))->convertWithReport($html);
        $codes = array_map(static fn ($diagnostic): string => $diagnostic->code, $result->diagnostics);

        $this->assertSame(
            $keepsBytes,
            str_contains($result->value, '<' . $tag . ' onclick="a()">'),
            'the output of <' . $tag . '> is: ' . $result->value,
        );
        $this->assertSame($keepsBytes, in_array('raw-preserved', $codes, true));
        if ($keepsBytes) {
            $this->assertNotContains('attribute-dropped', $codes);
            $this->assertNotContains('element-unwrapped', $codes);

            return;
        }
        $this->assertNotContains('attribute-preserved', $codes);
        $this->assertContains('attribute-dropped', $codes);
    }

    /**
     * Every tag that reaches the raw-keep path, measured rather than listed from
     * the roster the fix removed. The four block-level names take a raw block,
     * the rest an inline raw span, and the last group is kept because the parent
     * that would give it a Carve spelling is absent.
     *
     * @return array<string, array{string, bool}>
     */
    public static function tagProvider(): array
    {
        $kept = [
            'form', 'fieldset', 'address', 'hgroup',
            'output', 'progress', 'meter', 'select', 'object', 'canvas', 'video', 'audio', 'map',
            'button', 'dialog', 'menu', 'search',
            'li', 'tr', 'tbody', 'thead', 'tfoot', 'summary', 'colgroup',
        ];
        $cases = [];
        foreach ($kept as $tag) {
            $cases[$tag] = [$tag, true];
        }
        foreach (['nav', 'aside', 'main', 'section', 'article'] as $tag) {
            $cases[$tag . ' unwraps'] = [$tag, false];
        }

        return $cases;
    }

    /**
     * The payload from markup-carve/carve-php#2357. The third row it reported
     * named `/xmp[1]/a[1]`, a path for an element the tree does not hold as an
     * element at all.
     */
    public function testTheXmpPayloadNamesNoPathTheTreeDoesNotHold(): void
    {
        $result = (new HtmlToCarve(importMode: 'roundtrip'))
            ->convertWithReport('<xmp onclick="go()"><a href="javascript:alert(2)">link</a></xmp>');

        $this->assertSame(
            ['/xmp[1]', '/xmp[1]'],
            array_map(static fn ($diagnostic): ?string => $diagnostic->path, $result->diagnostics),
        );
    }

    /**
     * A raw block inside a list item or a block quote carries that container's
     * prefix on its lines, and the keep still has to be visible through it. The
     * direction matters: reading a kept element as dropped is the leak, and a
     * probe anchored on the opening fence gets exactly this case wrong.
     *
     * @param string $html
     */
    #[DataProvider('containerProvider')]
    public function testAKeptElementInsideAContainerIsStillRead(string $html): void
    {
        $result = (new HtmlToCarve(importMode: 'roundtrip'))->convertWithReport($html);
        $codes = array_map(static fn ($diagnostic): string => $diagnostic->code, $result->diagnostics);

        $this->assertStringContainsString('<form onclick="a()">x</form>', $result->value);
        $this->assertContains('raw-preserved', $codes);
        $this->assertContains('attribute-preserved', $codes);
        $this->assertNotContains('attribute-dropped', $codes);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function containerProvider(): array
    {
        return [
            'in a list item' => ['<ul><li><form onclick="a()">x</form></li></ul>'],
            'in a block quote' => ['<blockquote><form onclick="a()">x</form></blockquote>'],
        ];
    }

    /**
     * A BOOLEAN attribute must not hide the keep. `saveHTML()` writes `hidden`
     * bare, so a probe that spells every attribute as `name=""` finds nothing and
     * reports the kept element as unwrapped with its handler dropped - the very
     * leak this class is about, reintroduced by the probe. The probe is the
     * serializer's own call for that reason.
     */
    public function testABooleanAttributeDoesNotHideTheKeep(): void
    {
        $result = (new HtmlToCarve(importMode: 'roundtrip'))
            ->convertWithReport('<form hidden onclick="x()">hi</form>');

        $this->assertSame(
            [
                ['attribute-preserved', 'error', '/form[1]', 'Preserved event-handler attribute onclick on <form> in the raw HTML this element is kept as'],
                ['raw-preserved', 'warning', '/form[1]', 'Preserved unsupported <form> element as raw HTML'],
            ],
            $this->rows($result->diagnostics),
        );
    }

    /**
     * The same tag in a kept position and in a spelled one, in one document. The
     * `<q>` in the kept `<form>` is preserved and the `<q>` in the paragraph is
     * mapped to quotation marks, and each gets the row for what happened to IT.
     */
    public function testTheSameTagKeptOnceAndSpelledOnceGetsBothAnswers(): void
    {
        $result = (new HtmlToCarve(importMode: 'roundtrip'))->convertWithReport(
            '<form><q onclick="x()">raw</q></form><p><q onclick="x()">mapped</q></p>',
        );

        $this->assertSame(
            [
                ['raw-preserved', 'warning', '/form[1]', 'Preserved unsupported <form> element as raw HTML'],
                ['attribute-preserved', 'error', '/form[1]/q[1]', 'Preserved event-handler attribute onclick on <q> inside the raw HTML <form> is kept as'],
                ['element-unwrapped', 'info', '/p[2]/q[1]', 'Read <q> as quotation marks: Carve has no quotation element, so the marks are the mapping'],
                ['attribute-dropped', 'warning', '/p[2]/q[1]', 'Dropped event-handler attribute onclick on <q>'],
            ],
            $this->rows($result->diagnostics),
        );
    }

    /**
     * The other direction: the roster called `q` and `input` kept, and the
     * conversion does not keep either, so the report claimed a handler was live
     * that it had removed.
     *
     * @param string $html
     * @param string $expectedCarve
     */
    #[DataProvider('unkeptProvider')]
    public function testAnElementTheOutputDroppedIsNotReportedAsPreserved(string $html, string $expectedCarve): void
    {
        $result = (new HtmlToCarve(importMode: 'roundtrip'))->convertWithReport($html);
        $codes = array_map(static fn ($diagnostic): string => $diagnostic->code, $result->diagnostics);

        $this->assertSame($expectedCarve, trim($result->value));
        $this->assertNotContains('raw-preserved', $codes);
        $this->assertNotContains('attribute-preserved', $codes);
        $this->assertContains('attribute-dropped', $codes);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function unkeptProvider(): array
    {
        return [
            'q reads as quotation marks' => ['<p><q onclick="a()">t</q></p>', '“t”'],
            'a consumed input is dropped' => ['<p><input onclick="a()" value="v"></p>', ''],
        ];
    }

    /**
     * CONTROL 1: `safe` is not touched by any of this. It imports the same
     * payload by removing the element and the attributes, and says so.
     */
    public function testSafeModeIsUnchanged(): void
    {
        $result = (new HtmlToCarve(importMode: 'safe'))->convertWithReport(self::PAYLOAD);

        $this->assertStringNotContainsString('javascript', $result->value);
        $this->assertStringNotContainsString('onclick', $result->value);
        $this->assertSame(
            [
                ['element-unwrapped', 'info', '/form[1]', 'Unwrapped unsupported <form> element'],
                ['style-unmapped', 'info', '/form[1]', 'CSS declarations may not have a Carve mapping'],
                ['attribute-dropped', 'warning', '/form[1]', 'Dropped event-handler attribute onclick on <form>'],
                ['attribute-dropped', 'warning', '/form[1]/a[1]', 'Dropped href with a denied URL scheme on <a>'],
            ],
            $this->rows($result->diagnostics),
        );
    }

    /**
     * CONTROL 2: the bytes of a raw-kept element with nothing refused in them
     * still come back byte for byte, with one row and no attribute row. This is
     * what shows the report was the thing that changed.
     */
    public function testAHarmlessKeptElementIsStillByteIdentical(): void
    {
        $html = '<form id="f"><a href="/ok" title="t">t</a></form>';
        $result = (new HtmlToCarve(importMode: 'roundtrip'))->convertWithReport($html);

        $this->assertStringContainsString($html, $result->value);
        $this->assertSame(
            [['raw-preserved', 'warning', '/form[1]', 'Preserved unsupported <form> element as raw HTML']],
            $this->rows($result->diagnostics),
        );
    }

    /**
     * CONTROL 3: benign CSS inside kept bytes keeps its `attribute-preserved`
     * row at `info`, on the element and on a descendant alike. The `style` gap
     * markup-carve/carve#2267 reports is real and identical in all three
     * engines, and no engine moves on it until a clause pins the wording, so
     * this row is here to stay put.
     */
    public function testBenignCssKeepsItsInfoRow(): void
    {
        $result = (new HtmlToCarve(importMode: 'roundtrip'))
            ->convertWithReport('<form style="color:red"><p style="font-weight:bold">a</p></form>');

        $this->assertSame(
            [
                ['attribute-preserved', 'info', '/form[1]', 'Preserved attribute style on <form> in the raw HTML this element is kept as'],
                ['raw-preserved', 'warning', '/form[1]', 'Preserved unsupported <form> element as raw HTML'],
                ['attribute-preserved', 'info', '/form[1]/p[1]', 'Preserved attribute style on <p> inside the raw HTML <form> is kept as'],
            ],
            $this->rows($result->diagnostics),
        );
    }

    /**
     * Two refusals on one element follow the order that element spells them,
     * which the contract's ordering section requires.
     */
    public function testTwoRefusalsOnOneElementFollowTheSpelling(): void
    {
        $result = (new HtmlToCarve(importMode: 'roundtrip'))->convertWithReport(
            '<form action="javascript:b()" onclick="a()">t</form>',
        );

        $this->assertSame(
            [
                'Preserved action with a denied URL scheme on <form> in the raw HTML this element is kept as',
                'Preserved event-handler attribute onclick on <form> in the raw HTML this element is kept as',
                'Preserved unsupported <form> element as raw HTML',
            ],
            array_map(static fn ($diagnostic): string => $diagnostic->message, $result->diagnostics),
        );
    }

    /**
     * A kept element that holds its content as CHARACTER DATA gets no descendant
     * row (markup-carve/carve-php#2357). This is #2261's inverse and just as bad:
     * a danger reported and not present. A consumer cannot act on it and a
     * reviewer who chases it finds nothing, which is how a report stops being
     * read.
     *
     * READ THE BYTES BEFORE "FIXING" THIS. The validating parser this importer
     * loads with hands the walk an `<a>` element, and the kept bytes are
     * serialized UNESCAPED, so the output literally reads
     * `<a href="javascript:alert(1)">`. It is still inert: re-parsing returns to
     * the RAWTEXT or RCDATA state at the opening tag and no reader ever builds
     * that element. The row looks owed and is not. The control below is the other
     * half - a kept element with a REAL element descendant must still report it,
     * or a patch that stops descending everywhere passes this test and silently
     * undoes #2261.
     *
     * @param string $tag
     */
    #[DataProvider('textContentProvider')]
    public function testAKeptElementHoldingTextGetsNoDescendantRow(string $tag): void
    {
        $html = '<' . $tag . ' onclick="a()"><a href="javascript:alert(1)">t</a></' . $tag . '>';
        $result = (new HtmlToCarve(importMode: 'roundtrip'))->convertWithReport($html);

        $this->assertSame(
            [
                ['attribute-preserved', 'error', '/' . $tag . '[1]', 'Preserved event-handler attribute onclick on <' . $tag . '> in the raw HTML this element is kept as'],
                ['raw-preserved', 'warning', '/' . $tag . '[1]', 'Preserved unsupported <' . $tag . '> element as raw HTML'],
            ],
            $this->rows($result->diagnostics),
        );
    }

    /**
     * Every character-data element that reaches the raw-keep path, measured:
     * `title` and `textarea` are RCDATA, `iframe`, `noembed`, `noframes`, `xmp`
     * and `plaintext` are RAWTEXT. `script` and `style` are RAWTEXT too and are
     * absent on purpose - they are dropped as active content long before this
     * walk, so a case for them would asserting nothing.
     *
     * @return array<string, array{string}>
     */
    public static function textContentProvider(): array
    {
        $cases = [];
        foreach (['textarea', 'title', 'iframe', 'noembed', 'noframes', 'xmp', 'plaintext'] as $tag) {
            $cases[$tag] = [$tag];
        }

        return $cases;
    }

    /**
     * The control for the exemption above: `<button>` holds real markup, so the
     * descendant inside it IS live and does get its row.
     */
    public function testAKeptElementHoldingMarkupStillReportsItsDescendant(): void
    {
        $result = (new HtmlToCarve(importMode: 'roundtrip'))->convertWithReport(
            '<button onclick="a()"><a href="javascript:alert(1)">t</a></button>',
        );

        $this->assertSame(
            [
                ['attribute-preserved', 'error', '/button[1]', 'Preserved event-handler attribute onclick on <button> in the raw HTML this element is kept as'],
                ['raw-preserved', 'warning', '/button[1]', 'Preserved unsupported <button> element as raw HTML'],
                ['attribute-preserved', 'error', '/button[1]/a[1]', 'Preserved href with a denied URL scheme on <a> inside the raw HTML <button> is kept as'],
            ],
            $this->rows($result->diagnostics),
        );
    }

    /**
     * @param array<\MarkupCarve\Carve\Converter\HtmlImportDiagnostic> $diagnostics
     *
     * @return array<array{string, string, string|null, string}>
     */
    private function rows(array $diagnostics): array
    {
        return array_map(
            static fn ($diagnostic): array => [
                $diagnostic->code,
                $diagnostic->severity,
                $diagnostic->path,
                $diagnostic->message,
            ],
            $diagnostics,
        );
    }
}
