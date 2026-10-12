<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AStoredSourceMarkerIsReportedAndKeptBytesAreNotRewrittenTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string, array<string>}>
     */
    public static function cases(): array
    {
        return [
            'a roundtrip' => [
                'roundtrip',
                '<form><p data-carve-src="stored">text</p></form>',
                '```=html
<form><p data-carve-src="stored">text</p></form>
```
',
                [
                    'raw-preserved|warning|degraded|exact|/form[1]|Preserved unsupported <form> element as raw HTML',
                    'attribute-preserved|info|preserved|exact|/form[1]/p[1]|Preserved round-trip marker attribute data-carve-src on <p> inside the raw HTML <form> is kept as',
                ],
            ],
            'a safe' => [
                'safe',
                '<form><p data-carve-src="stored">text</p></form>',
                'text
',
                [
                    'element-unwrapped|info|degraded|exact|/form[1]|Unwrapped unsupported <form> element',
                    'attribute-dropped|info|dropped|exact|/form[1]/p[1]|Dropped round-trip marker attribute data-carve-src on <p>',
                ],
            ],
            'a semantic' => [
                'semantic',
                '<form><p data-carve-src="stored">text</p></form>',
                'text
',
                [
                    'element-unwrapped|info|degraded|exact|/form[1]|Unwrapped unsupported <form> element',
                    'attribute-dropped|info|dropped|exact|/form[1]/p[1]|Dropped round-trip marker attribute data-carve-src on <p>',
                ],
            ],
            'b roundtrip' => [
                'roundtrip',
                '<form onclick="go()" data-carve-src="x">kept</form>
',
                '```=html
<form onclick="go()" data-carve-src="x">kept</form>
```
',
                [
                    'attribute-preserved|error|preserved|exact|/form[1]|Preserved event-handler attribute onclick on <form> in the raw HTML this element is kept as',
                    'attribute-preserved|info|preserved|exact|/form[1]|Preserved round-trip marker attribute data-carve-src on <form> in the raw HTML this element is kept as',
                    'raw-preserved|warning|degraded|exact|/form[1]|Preserved unsupported <form> element as raw HTML',
                ],
            ],
            'b safe' => [
                'safe',
                '<form onclick="go()" data-carve-src="x">kept</form>
',
                'kept
',
                [
                    'element-unwrapped|info|degraded|exact|/form[1]|Unwrapped unsupported <form> element',
                    'attribute-dropped|warning|dropped|exact|/form[1]|Dropped event-handler attribute onclick on <form>',
                    'attribute-dropped|info|dropped|exact|/form[1]|Dropped round-trip marker attribute data-carve-src on <form>',
                ],
            ],
            'b semantic' => [
                'semantic',
                '<form onclick="go()" data-carve-src="x">kept</form>
',
                'kept
',
                [
                    'element-unwrapped|info|degraded|exact|/form[1]|Unwrapped unsupported <form> element',
                    'attribute-dropped|warning|dropped|exact|/form[1]|Dropped event-handler attribute onclick on <form>',
                    'attribute-dropped|info|dropped|exact|/form[1]|Dropped round-trip marker attribute data-carve-src on <form>',
                ],
            ],
            'c roundtrip' => [
                'roundtrip',
                '<form data-carve-src="javascript:x()"><cite cite="javascript:y()">t</cite></form>
',
                '```=html
<form data-carve-src="javascript:x()"><cite cite="javascript:y()">t</cite></form>
```
',
                [
                    'attribute-preserved|error|preserved|exact|/form[1]|Preserved round-trip marker attribute data-carve-src on <form> in the raw HTML this element is kept as',
                    'raw-preserved|warning|degraded|exact|/form[1]|Preserved unsupported <form> element as raw HTML',
                    'attribute-preserved|error|preserved|exact|/form[1]/cite[1]|Preserved cite on <cite> inside the raw HTML <form> is kept as: the semantic span\'s marker owns that key',
                ],
            ],
            'c safe' => [
                'safe',
                '<form data-carve-src="javascript:x()"><cite cite="javascript:y()">t</cite></form>
',
                '[t]{cite}
',
                [
                    'element-unwrapped|info|degraded|exact|/form[1]|Unwrapped unsupported <form> element',
                    'attribute-dropped|info|dropped|exact|/form[1]|Dropped round-trip marker attribute data-carve-src on <form>',
                    'attribute-dropped|warning|dropped|exact|/form[1]/cite[1]|Dropped cite on <cite>: the semantic span\'s marker owns that key',
                ],
            ],
            'c semantic' => [
                'semantic',
                '<form data-carve-src="javascript:x()"><cite cite="javascript:y()">t</cite></form>
',
                '[t]{cite}
',
                [
                    'element-unwrapped|info|degraded|exact|/form[1]|Unwrapped unsupported <form> element',
                    'attribute-dropped|info|dropped|exact|/form[1]|Dropped round-trip marker attribute data-carve-src on <form>',
                    'attribute-dropped|warning|dropped|exact|/form[1]/cite[1]|Dropped cite on <cite>: the semantic span\'s marker owns that key',
                ],
            ],
            'd roundtrip' => [
                'roundtrip',
                '<form><p data-djot-src="stored">text</p></form>',
                '```=html
<form><p data-djot-src="stored">text</p></form>
```
',
                [
                    'raw-preserved|warning|degraded|exact|/form[1]|Preserved unsupported <form> element as raw HTML',
                    'attribute-preserved|info|preserved|exact|/form[1]/p[1]|Preserved round-trip marker attribute data-djot-src on <p> inside the raw HTML <form> is kept as',
                ],
            ],
            'd safe' => [
                'safe',
                '<form><p data-djot-src="stored">text</p></form>',
                'text
',
                [
                    'element-unwrapped|info|degraded|exact|/form[1]|Unwrapped unsupported <form> element',
                    'attribute-dropped|info|dropped|exact|/form[1]/p[1]|Dropped round-trip marker attribute data-djot-src on <p>',
                ],
            ],
            'd semantic' => [
                'semantic',
                '<form><p data-djot-src="stored">text</p></form>',
                'text
',
                [
                    'element-unwrapped|info|degraded|exact|/form[1]|Unwrapped unsupported <form> element',
                    'attribute-dropped|info|dropped|exact|/form[1]/p[1]|Dropped round-trip marker attribute data-djot-src on <p>',
                ],
            ],
            'g roundtrip' => [
                'roundtrip',
                '<p DATA-CARVE-SRC="*stored*">shown</p><p>lead</p><div DATA-DJOT-SRC="*stored*"><p>shown</p></div>',
                'shown

lead

shown
',
                [
                    'attribute-dropped|info|dropped|exact|/p[1]|Dropped round-trip marker attribute data-carve-src on <p>',
                    'attribute-dropped|info|dropped|exact|/div[3]|Dropped round-trip marker attribute data-djot-src on <div>',
                ],
            ],
            'g safe' => [
                'safe',
                '<p DATA-CARVE-SRC="*stored*">shown</p><p>lead</p><div DATA-DJOT-SRC="*stored*"><p>shown</p></div>',
                'shown

lead

shown
',
                [
                    'attribute-dropped|info|dropped|exact|/p[1]|Dropped round-trip marker attribute data-carve-src on <p>',
                    'attribute-dropped|info|dropped|exact|/div[3]|Dropped round-trip marker attribute data-djot-src on <div>',
                ],
            ],
            'g semantic' => [
                'semantic',
                '<p DATA-CARVE-SRC="*stored*">shown</p><p>lead</p><div DATA-DJOT-SRC="*stored*"><p>shown</p></div>',
                'shown

lead

shown
',
                [
                    'attribute-dropped|info|dropped|exact|/p[1]|Dropped round-trip marker attribute data-carve-src on <p>',
                    'attribute-dropped|info|dropped|exact|/div[3]|Dropped round-trip marker attribute data-djot-src on <div>',
                ],
            ],
            'h roundtrip' => [
                'roundtrip',
                '<p>a <span data-x="1" data-carve-src="i">in</span> <em data-djot-src="e">em</em> <output data-carve-src="o">out</output></p>
<form data-carve-src="x"><cite cite="u">text</cite></form><table data-carve-src="t"><tr><td data-djot-src="c">x</td></tr></table>',
                'a [in]{data-x=1} /em/ `<output data-carve-src="o">out</output>`{=html}

```=html
<form data-carve-src="x"><cite cite="u">text</cite></form>
```

| x |
',
                [
                    'attribute-dropped|info|dropped|exact|/p[1]/span[2]|Dropped round-trip marker attribute data-carve-src on <span>',
                    'attribute-dropped|info|dropped|exact|/p[1]/em[4]|Dropped round-trip marker attribute data-djot-src on <em>',
                    'attribute-preserved|info|preserved|exact|/p[1]/output[6]|Preserved round-trip marker attribute data-carve-src on <output> in the raw HTML this element is kept as',
                    'raw-preserved|warning|degraded|exact|/p[1]/output[6]|Preserved unsupported <output> element as raw HTML',
                    'attribute-preserved|info|preserved|exact|/form[3]|Preserved round-trip marker attribute data-carve-src on <form> in the raw HTML this element is kept as',
                    'raw-preserved|warning|degraded|exact|/form[3]|Preserved unsupported <form> element as raw HTML',
                    'attribute-preserved|info|preserved|exact|/form[3]/cite[1]|Preserved cite on <cite> inside the raw HTML <form> is kept as: the semantic span\'s marker owns that key',
                    'attribute-dropped|info|dropped|exact|/table[4]|Dropped round-trip marker attribute data-carve-src on <table>',
                    'attribute-dropped|info|dropped|exact|/table[4]/tr[1]/td[1]|Dropped round-trip marker attribute data-djot-src on <td>',
                ],
            ],
            'h safe' => [
                'safe',
                '<p>a <span data-x="1" data-carve-src="i">in</span> <em data-djot-src="e">em</em> <output data-carve-src="o">out</output></p>
<form data-carve-src="x"><cite cite="u">text</cite></form><table data-carve-src="t"><tr><td data-djot-src="c">x</td></tr></table>',
                'a [in]{data-x=1} /em/ out

[text]{cite}

| x |
',
                [
                    'attribute-dropped|info|dropped|exact|/p[1]/span[2]|Dropped round-trip marker attribute data-carve-src on <span>',
                    'attribute-dropped|info|dropped|exact|/p[1]/em[4]|Dropped round-trip marker attribute data-djot-src on <em>',
                    'element-unwrapped|info|degraded|exact|/p[1]/output[6]|Unwrapped unsupported <output> element',
                    'attribute-dropped|info|dropped|exact|/p[1]/output[6]|Dropped round-trip marker attribute data-carve-src on <output>',
                    'element-unwrapped|info|degraded|exact|/form[3]|Unwrapped unsupported <form> element',
                    'attribute-dropped|info|dropped|exact|/form[3]|Dropped round-trip marker attribute data-carve-src on <form>',
                    'attribute-dropped|warning|dropped|exact|/form[3]/cite[1]|Dropped cite on <cite>: the semantic span\'s marker owns that key',
                    'attribute-dropped|info|dropped|exact|/table[4]|Dropped round-trip marker attribute data-carve-src on <table>',
                    'attribute-dropped|info|dropped|exact|/table[4]/tr[1]/td[1]|Dropped round-trip marker attribute data-djot-src on <td>',
                ],
            ],
            'h semantic' => [
                'semantic',
                '<p>a <span data-x="1" data-carve-src="i">in</span> <em data-djot-src="e">em</em> <output data-carve-src="o">out</output></p>
<form data-carve-src="x"><cite cite="u">text</cite></form><table data-carve-src="t"><tr><td data-djot-src="c">x</td></tr></table>',
                'a [in]{data-x=1} /em/ out

[text]{cite}

| x |
',
                [
                    'attribute-dropped|info|dropped|exact|/p[1]/span[2]|Dropped round-trip marker attribute data-carve-src on <span>',
                    'attribute-dropped|info|dropped|exact|/p[1]/em[4]|Dropped round-trip marker attribute data-djot-src on <em>',
                    'element-unwrapped|info|degraded|exact|/p[1]/output[6]|Unwrapped unsupported <output> element',
                    'attribute-dropped|info|dropped|exact|/p[1]/output[6]|Dropped round-trip marker attribute data-carve-src on <output>',
                    'element-unwrapped|info|degraded|exact|/form[3]|Unwrapped unsupported <form> element',
                    'attribute-dropped|info|dropped|exact|/form[3]|Dropped round-trip marker attribute data-carve-src on <form>',
                    'attribute-dropped|warning|dropped|exact|/form[3]/cite[1]|Dropped cite on <cite>: the semantic span\'s marker owns that key',
                    'attribute-dropped|info|dropped|exact|/table[4]|Dropped round-trip marker attribute data-carve-src on <table>',
                    'attribute-dropped|info|dropped|exact|/table[4]/tr[1]/td[1]|Dropped round-trip marker attribute data-djot-src on <td>',
                ],
            ],
            'sanitized image fallback roundtrip' => [
                'roundtrip',
                '<p><img src="x" alt="[x]" onclick="x()" data-carve-src="stored"> and <img src="y" alt="[y]" data-djot-src="s2"></p>',
                '`<img src="x" alt="[x]">`{=html} and `<img src="y" alt="[y]">`{=html}
',
                [
                    'attribute-dropped|warning|dropped|exact|/p[1]/img[1]|Dropped event-handler attribute onclick on <img>',
                    'attribute-dropped|info|dropped|exact|/p[1]/img[1]|Dropped round-trip marker attribute data-carve-src on <img>',
                    'attribute-dropped|info|dropped|exact|/p[1]/img[3]|Dropped round-trip marker attribute data-djot-src on <img>',
                ],
            ],
        ];
    }

    /**
     * @param string $mode
     * @param string $html
     * @param string $source
     * @param array<string> $expectedRows
     */
    #[DataProvider('cases')]
    public function testOracle(string $mode, string $html, string $source, array $expectedRows): void
    {
        $importer = new HtmlToCarve(importMode: $mode);
        $result = $importer->convertWithReport($html);
        $this->assertSame($source, $result->value);
        foreach ([$result->report(), $importer->convertToAstWithReport($html)->report()] as $report) {
            $rows = [];
            foreach ($report['diagnostics'] as $row) {
                $rows[] = implode('|', [
                    $row['code'],
                    $row['severity'],
                    $row['fidelity'],
                    $row['confidence'],
                    $row['path'],
                    $row['message'],
                ]);
            }
            $this->assertSame($expectedRows, $rows);
        }
    }
}
