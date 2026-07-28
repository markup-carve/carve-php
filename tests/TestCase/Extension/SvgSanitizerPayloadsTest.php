<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Extension;

use MarkupCarve\Carve\Extension\SvgSanitizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Faithful port of carve-js `test/svg-sanitize-payloads.test.ts`.
 *
 * A curated corpus of known SVG-based XSS / resource-fetch vectors, drawn from
 * the PortSwigger XSS SVG cheatsheet, cure53 mXSS research, and the OWASP SVG
 * payloads. Each is fed through {@see SvgSanitizer::sanitize()} and the OUTPUT
 * is asserted inert: no active markup, event handlers, dangerous schemes, or
 * external references survive. Run under both default and maximally-permissive
 * opts, since the opt flags widen the allowlist and must not open a hole.
 *
 * This is a string-level guard (fast, dependency-free, CI-default).
 */
class SvgSanitizerPayloadsTest extends TestCase
{
    /**
     * The XSS-relevant capability flags, all on. None of these must open a hole
     * for the payloads. `allowExternalImages` is deliberately excluded: it exists
     * precisely to permit an external `<image href>` fetch, so it is a documented
     * privacy opt, not an inertness guarantee — the default-opts run asserts
     * external images are blocked by default.
     *
     * @var array<string, bool>
     */
    private const ALL_ON = [
        'allowStyle' => true,
        'allowLinks' => true,
        'allowAnimation' => true,
    ];

    /**
     * @return array<string, array{0: string}>
     */
    public static function payloadProvider(): array
    {
        $payloads = [
            // -- script / event handlers --
            '<svg onload="alert(1)"><rect/></svg>',
            '<svg><script>alert(1)</script></svg>',
            '<svg><script href="data:,alert(1)"/></svg>',
            '<svg><script xlink:href="data:,alert(1)"/></svg>',
            '<svg><rect onclick="alert(1)" onmouseover="alert(1)" width="1" height="1"/></svg>',
            '<svg><rect fill=a onload=alert(1) width=1 height=1></rect></svg>',
            '<svg><g onfocus="alert(1)" tabindex="1"><rect/></g></svg>',
            // -- javascript: / dangerous schemes on links --
            '<svg><a xlink:href="javascript:alert(1)"><text>x</text></a></svg>',
            '<svg><a href="javascript:alert(1)"><rect width="1" height="1"/></a></svg>',
            '<svg><a href="ms-msdt:x"><rect width="1" height="1"/></a></svg>',
            '<svg><a href="vbscript:msgbox(1)"><rect width="1" height="1"/></a></svg>',
            // -- entity / escape obfuscated schemes --
            '<svg><a href="jav&#x61;script:alert(1)"><rect width="1" height="1"/></a></svg>',
            '<svg><a href="javascript&colon;alert(1)"><rect width="1" height="1"/></a></svg>',
            '<svg><a href="&#106;avascript:alert(1)"><rect width="1" height="1"/></a></svg>',
            // -- SMIL animation retargeting --
            '<svg><a id="x"><rect width="1" height="1"/></a><animate xlink:href="#x" attributeName="href" values="javascript:alert(1)"/></svg>',
            '<svg><set attributeName="href" to="javascript:alert(1)"/></svg>',
            '<svg><animate attributeName="href" values="#a;https://evil.example/x#b"/></svg>',
            '<svg><animate attributeName="href" values="#a;//evil.example/x#b"/></svg>',
            '<svg><discard begin="0s" href="javascript:alert(1)"/></svg>',
            // -- foreignObject / embedded HTML --
            '<svg><foreignObject><iframe src="javascript:alert(1)"></iframe></foreignObject></svg>',
            '<svg><foreignObject><img src=x onerror=alert(1)></foreignObject></svg>',
            '<svg><foreignObject><body onload="alert(1)"/></foreignObject></svg>',
            // -- external resource fetches --
            '<svg><use href="https://evil.example/x.svg#a"/></svg>',
            '<svg><use xlink:href="//evil.example/x.svg#a"/></svg>',
            '<svg><image href="https://evil.example/x.png" width="1" height="1"/></svg>',
            '<svg><feImage href="https://evil.example/x.png"/></svg>',
            '<svg><rect fill="url(https://evil.example/p.svg#x)" width="1" height="1"/></svg>',
            '<svg><rect filter="url(https://evil.example/f.svg#x)" width="1" height="1"/></svg>',
            '<svg><rect fill=\'url("https://evil.example/a)b.svg#x")\' width=\'1\' height=\'1\'/></svg>',
            '<svg><rect clip-path="url(//evil.example/c)" width="1" height="1"/></svg>',
            // -- style element / attribute --
            '<svg><style>@import url(\'https://evil.example/x.css\');</style><rect/></svg>',
            '<svg><style>* { background: url(javascript:alert(1)) }</style><rect/></svg>',
            '<svg><rect style="background:url(javascript:alert(1))" width="1" height="1"/></svg>',
            '<svg><rect style="fill:u\72l(https://evil.example/x)" width="1" height="1"/></svg>',
            // -- handler / listener elements --
            '<svg><handler xmlns:ev="http://www.w3.org/2001/xml-events" ev:event="load">alert(1)</handler></svg>',
            '<svg><listener event="load" handler="#h"/><rect/></svg>',
            // -- comments / CDATA / PI / doctype tricks --
            '<svg><!--<script>alert(1)</script>--><rect width="1" height="1"/></svg>',
            '<svg><![CDATA[<script>alert(1)</script>]]><rect width="1" height="1"/></svg>',
            '<?xml-stylesheet type="text/xsl" href="javascript:alert(1)"?><svg><rect/></svg>',
            '<!DOCTYPE svg [<!ENTITY x "y">]><svg><rect width="1" height="1"/></svg>',
            // -- mutation-ish reparse candidates --
            '<svg><title><style><img src=1 onerror=alert(1)></style></title><rect width="1" height="1"/></svg>',
            '<svg><desc><![CDATA[</desc><script>alert(1)</script>]]></desc><rect width="1" height="1"/></svg>',
            '<svg><![CDATA[]><svg onload=alert(1)>]]><rect width="1" height="1"/></svg>',
        ];

        $cases = [];
        foreach ($payloads as $i => $payload) {
            $cases['payload #' . $i] = [$payload];
        }

        return $cases;
    }

    /**
     * Assert the sanitized output carries nothing executable or externally-fetching.
     */
    protected function assertInert(string $rawOut): void
    {
        // The forced canonical xmlns (`http://www.w3.org/2000/svg`) and the xlink
        // namespace decl legitimately contain a w3.org http URL that is NOT a
        // fetch; strip namespace declarations before the external-URL scan.
        $out = preg_replace('~\bxmlns(:[\w-]+)?\s*=\s*"[^"]*"~i', '', $rawOut) ?? $rawOut;
        $this->assertDoesNotMatchRegularExpression('~<script[\s/>]~i', $out);
        $this->assertDoesNotMatchRegularExpression('~<foreignObject~i', $out);
        $this->assertDoesNotMatchRegularExpression('~<handler\b~i', $out);
        $this->assertDoesNotMatchRegularExpression('~<iframe~i', $out);
        // no event-handler attributes (on… =)
        $this->assertDoesNotMatchRegularExpression('~\son[a-z]+\s*=~i', $out);
        // no dangerous URL schemes anywhere in the output
        $this->assertDoesNotMatchRegularExpression('~javascript:~i', $out);
        $this->assertDoesNotMatchRegularExpression('~vbscript:~i', $out);
        $this->assertDoesNotMatchRegularExpression('~ms-msdt:~i', $out);
        // no external absolute or protocol-relative references
        $this->assertDoesNotMatchRegularExpression('~https?://~i', $out);
        $this->assertDoesNotMatchRegularExpression('~url\(\s*[\'"]?\s*//~i', $out);
        $this->assertStringNotContainsString('evil.example', $out);
        // no active CSS constructs
        $this->assertDoesNotMatchRegularExpression('~@import~i', $out);
        $this->assertDoesNotMatchRegularExpression('~url\(\s*[\'"]?\s*(?!#)~i', $out);
    }

    #[DataProvider('payloadProvider')]
    public function testPayloadIsInertWithDefaultOpts(string $payload): void
    {
        $result = SvgSanitizer::sanitize($payload);
        // Either it was rejected (ok:false → caller shows source), or the emitted
        // SVG is inert. A rejected payload emits no svg string.
        if ($result['ok']) {
            $this->assertInert($result['svg']);
        } else {
            $this->assertSame('', $result['svg']);
        }
    }

    #[DataProvider('payloadProvider')]
    public function testPayloadIsInertWithEveryOptEnabled(string $payload): void
    {
        $result = SvgSanitizer::sanitize($payload, self::ALL_ON);
        if ($result['ok']) {
            $this->assertInert($result['svg']);
        } else {
            $this->assertSame('', $result['svg']);
        }
    }

    #[DataProvider('payloadProvider')]
    public function testPayloadStaysInertAfterReSanitizing(string $payload): void
    {
        $once = SvgSanitizer::sanitize($payload, self::ALL_ON);
        if (!$once['ok']) {
            $this->assertSame('', $once['svg']);

            return;
        }
        $twice = SvgSanitizer::sanitize($once['svg'], self::ALL_ON);
        $this->assertSame($once['svg'], $twice['svg']); // idempotent
        $this->assertInert($twice['svg']);
    }
}
