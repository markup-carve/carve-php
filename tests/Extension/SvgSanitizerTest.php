<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Extension;

use DOMDocument;
use DOMXPath;
use MarkupCarve\Carve\Extension\SvgSanitizeOptions;
use MarkupCarve\Carve\Extension\SvgSanitizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SvgSanitizerTest extends TestCase
{
    /**
     * @throws \RuntimeException
     *
     * @return array<string, array{string, array<string, mixed>, string, bool}>
     */
    public static function parityRows(): array
    {
        $path = dirname(__DIR__) . '/fixtures/svg-sanitize/parity.json';
        /** @var array<array{from: string, src: string, opts: array<string, mixed>, svg: string, ok: bool}> $rows */
        $rows = json_decode((string)file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        // carve-js rows a js-first ruling changed (carve#6763, #6765), each naming its ticket.
        $deltaPath = dirname(__DIR__) . '/fixtures/svg-sanitize/parity-js-first.json';
        /** @var array<array{index: int, tickets: array<string>, src: string, svg: string, ok: bool}> $deltas */
        $deltas = json_decode((string)file_get_contents($deltaPath), true, flags: JSON_THROW_ON_ERROR);
        foreach ($deltas as $delta) {
            $row = $rows[$delta['index']];
            if ($row['src'] !== $delta['src']) {
                throw new RuntimeException('parity-js-first row ' . $delta['index'] . ' names another source');
            }
            $rows[$delta['index']] = ['svg' => $delta['svg'], 'ok' => $delta['ok']] + $row;
        }
        $out = [];
        foreach ($rows as $i => $row) {
            $out[$row['from'] . ' ' . $i] = [$row['src'], $row['opts'], $row['svg'], $row['ok']];
        }

        return $out;
    }

    /**
     * @param string $source
     * @param array<string, mixed> $opts
     * @param bool $ok
     * @param string $svg
     */
    #[DataProvider('parityRows')]
    public function testOutputEqualsCarveJs(string $source, array $opts, string $svg, bool $ok): void
    {
        self::assertSame(['svg' => $svg, 'ok' => $ok], SvgSanitizer::sanitize($source, $opts));
    }

    public function testEveryKeptOutputIsInertInAnHtmlParser(): void
    {
        $checked = 0;
        foreach (self::parityRows() as [$source, $opts]) {
            $result = SvgSanitizer::sanitize($source, $opts);
            if (!$result['ok']) {
                self::assertSame('', $result['svg']);

                continue;
            }
            $checked++;
            $document = new DOMDocument();
            @$document->loadHTML('<body>' . $result['svg'] . '</body>');
            foreach ((new DOMXPath($document))->query('//*') ?: [] as $element) {
                self::assertNotContains(strtolower($element->nodeName), ['script', 'foreignobject', 'style', 'iframe'], $source);
                foreach ($element->attributes ?? [] as $attribute) {
                    self::assertStringStartsNotWith('on', strtolower($attribute->name), $source);
                }
            }
        }
        self::assertGreaterThan(500, $checked);
    }

    public function testScriptIsDroppedWithItsSubtree(): void
    {
        self::assertSame(
            ['svg' => '<svg xmlns="http://www.w3.org/2000/svg"><circle r="5"/></svg>', 'ok' => true],
            SvgSanitizer::sanitize('<svg><script>alert(1)</script><circle r="5"/></svg>'),
        );
    }

    public function testRootXmlnsInsideAValueIsKept(): void
    {
        // carve#6755: the carve-js regex cut the quote here and turned the rect's id text into an onload attribute.
        self::assertSame(
            '<svg xmlns="http://www.w3.org/2000/svg" id="a xmlns=b"><rect id="x onload=alert(1) y"/></svg>',
            SvgSanitizer::sanitize('<svg id="a xmlns=b"><rect id="x onload=alert(1) y"/></svg>')['svg'],
        );
    }

    public function testRootXmlnsAttributesAreReplacedByName(): void
    {
        self::assertSame(
            '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"><rect/></svg>',
            SvgSanitizer::sanitize('<svg xmlns="x" XMLNS="y" xmlns:xlink="http://www.w3.org/1999/xlink"><rect/></svg>')['svg'],
        );
        $valueless = SvgSanitizer::sanitize('<svg xmlns><rect/></svg>')['svg'];
        self::assertSame('<svg xmlns="http://www.w3.org/2000/svg"><rect/></svg>', $valueless);
    }

    public function testStyleWithACssImageFunctionIsDropped(): void
    {
        // carve#6760: C2-H-162 blanks these in the core renderer; carve-js kept them.
        $styles = [
            'cursor:image-set("https://e.example/p.png" 1x),auto',
            'background:-webkit-image-set("https://e.example/p.png" 1x)',
            "background:image('https://e.example/p.png')",
            'background:src("https://e.example/p.png")',
            'background:IMAGE\\2d set("https://e.example/p.png" 1x)',
        ];
        foreach ($styles as $style) {
            $result = SvgSanitizer::sanitize("<svg style='" . $style . "'/>", ['allowStyle' => true]);
            self::assertSame('<svg xmlns="http://www.w3.org/2000/svg"/>', $result['svg'], $style);
        }
        self::assertSame(
            '<svg xmlns="http://www.w3.org/2000/svg" style="fill:red;cursor:pointer"/>',
            SvgSanitizer::sanitize('<svg style="fill:red;cursor:pointer"/>', ['allowStyle' => true])['svg'],
        );
    }

    public function testAnHrefAnimationKeepsOnlyLocalReferences(): void
    {
        $opts = ['allowAnimation' => true];
        self::assertSame(
            '<svg xmlns="http://www.w3.org/2000/svg"><use href="#x"><set attributeName="href"/></use></svg>',
            SvgSanitizer::sanitize('<svg><use href="#x"><set attributeName="href" to="evil.svg#x"/></use></svg>', $opts)['svg'],
        );
        self::assertSame(
            '<svg xmlns="http://www.w3.org/2000/svg"><use href="#x"><animate attributeName="xlink:href" dur="1s"/></use></svg>',
            SvgSanitizer::sanitize(
                '<svg><use href="#x"><animate attributeName="xlink:href" values="#a;evil.svg#x" dur="1s"/></use></svg>',
                $opts,
            )['svg'],
        );
        $local = '<svg xmlns="http://www.w3.org/2000/svg"><use href="#x"><animate attributeName="HREF" values="#a;#b;"/></use></svg>';
        self::assertSame($local, SvgSanitizer::sanitize($local, $opts)['svg']);
    }

    public function testOptionsObjectAndArrayAgree(): void
    {
        $source = '<svg><a href="https://example.com/"><text>x</text></a><a href="mailto:a@b.c"><text>y</text></a></svg>';
        $policy = ['allow' => ['HTTPS']];
        $expected = '<svg xmlns="http://www.w3.org/2000/svg"><a href="https://example.com/"><text>x</text></a>'
            . '<a><text>y</text></a></svg>';

        self::assertSame($expected, SvgSanitizer::sanitize($source, new SvgSanitizeOptions(allowLinks: true, urlPolicy: $policy))['svg']);
        self::assertSame($expected, SvgSanitizer::sanitize($source, ['allowLinks' => true, 'urlPolicy' => $policy])['svg']);
    }

    public function testInvalidUtf8IsReadAsReplacementCharacters(): void
    {
        self::assertSame(
            ['svg' => "<svg xmlns=\"http://www.w3.org/2000/svg\"><text>a\u{FFFD}b</text></svg>", 'ok' => true],
            SvgSanitizer::sanitize("<svg><text>a\xFFb</text></svg>"),
        );
    }

    public function testQuotedLocalUrlReferencesAreKept(): void
    {
        // carve#6763
        self::assertSame(
            '<svg xmlns="http://www.w3.org/2000/svg"><rect fill="url(\'#g\')"/><rect fill="url(&quot;#g&quot;)"/>'
                . '<rect fill="url( #g)"/></svg>',
            SvgSanitizer::sanitize('<svg><rect fill="url(\'#g\')"/><rect fill=\'url("#g")\'/><rect fill="url( #g)"/></svg>')['svg'],
        );
        foreach (["url('https://e.example/p#g')", 'url( "//e.example/x")', "url(' x#g')", "url('#g') url(x)"] as $remote) {
            self::assertSame(
                '<svg xmlns="http://www.w3.org/2000/svg"><rect/></svg>',
                SvgSanitizer::sanitize('<svg><rect fill="' . htmlspecialchars($remote) . '"/></svg>')['svg'],
                $remote,
            );
        }
    }

    public function testOutputHoldsOnlyXmlCharacters(): void
    {
        // carve#6764
        self::assertSame(
            ['svg' => '<svg xmlns="http://www.w3.org/2000/svg"><text>&amp;#0;</text><text>a&amp;#x1;b</text></svg>', 'ok' => true],
            SvgSanitizer::sanitize('<svg><text>&#0;</text><text>a&#x1;b</text></svg>'),
        );
        $kept = '<svg xmlns="http://www.w3.org/2000/svg"><text>&#9;&#x20;&#xD7FF;&#xFFFD;&#x10FFFF;&#00065;</text></svg>';
        self::assertSame($kept, SvgSanitizer::sanitize($kept)['svg']);
        self::assertSame(
            '<svg xmlns="http://www.w3.org/2000/svg"><text>&amp;#xD800;&amp;#xFFFE;&amp;#x110000;&amp;#99999999;</text></svg>',
            SvgSanitizer::sanitize('<svg><text>&#xD800;&#xFFFE;&#x110000;&#99999999;</text></svg>')['svg'],
        );
        self::assertSame(
            '<svg xmlns="http://www.w3.org/2000/svg" id="ab"><text>xyz</text></svg>',
            SvgSanitizer::sanitize("<svg id=\"a\u{1}b\"><text>x\u{0}y\u{FFFE}z</text></svg>")['svg'],
        );
        // Dropped before the style check, so it cannot join `ur` and `l(` after the check.
        self::assertSame(
            '<svg xmlns="http://www.w3.org/2000/svg"/>',
            SvgSanitizer::sanitize("<svg style=\"background:ur\u{1}l(https://e.example/p)\"/>", ['allowStyle' => true])['svg'],
        );
    }

    public function testXmlCharacterVariantsLoadAsXml(): void
    {
        $bad = ['&#0;', '&#x1;', '&#xB;', '&#x1F;', '&#xD800;', '&#xFFFE;', '&#65535;', '&#x110000;'];
        $bad = [...$bad, "\u{0}", "\u{1}", "\u{B}", "\u{1F}", "\u{FFFE}", "\u{FFFF}"];
        $good = ['&#9;', '&#xA;', '&#32;', '&#x1F600;', "\u{1F600}", 'a', '&amp;', '&lt;', '&nbsp;', '&'];
        mt_srand(6764);
        $kept = 0;
        for ($i = 0; $i < 1000; $i++) {
            $text = '';
            for ($n = mt_rand(1, 5); $n > 0; $n--) {
                $pool = mt_rand(0, 1) === 1 ? $bad : $good;
                $text .= $pool[mt_rand(0, count($pool) - 1)];
            }
            $source = mt_rand(0, 1) === 1
                ? '<svg><text>' . $text . '</text></svg>'
                : '<svg><rect id="' . str_replace('"', '&quot;', $text) . '"/></svg>';
            $result = SvgSanitizer::sanitize($source);
            if (!$result['ok']) {
                continue;
            }
            $kept++;
            $document = new DOMDocument();
            self::assertTrue(@$document->loadXML($result['svg']), $source);
        }
        self::assertGreaterThan(900, $kept);
    }

    public function testAnimateTransformAndAnimateMotionAreKeptUnderAllowAnimation(): void
    {
        // carve#6765
        $opts = ['allowAnimation' => true];
        $animation = '<animateTransform attributeName="transform" type="rotate" from="0" to="360" dur="1s"/>';
        self::assertSame(
            '<svg xmlns="http://www.w3.org/2000/svg"><rect>' . $animation . '</rect></svg>',
            SvgSanitizer::sanitize('<svg><rect>' . $animation . '</rect></svg>', $opts)['svg'],
        );
        $motion = '<svg xmlns="http://www.w3.org/2000/svg"><path id="p"/>'
            . '<circle><animateMotion dur="2s"><mpath href="#p"/></animateMotion></circle></svg>';
        self::assertSame($motion, SvgSanitizer::sanitize($motion, $opts)['svg']);
        self::assertSame(
            '<svg xmlns="http://www.w3.org/2000/svg"><use href="#x"><animateMotion/></use></svg>',
            SvgSanitizer::sanitize('<svg><use href="#x"><animateMotion onbegin="alert(1)" href="evil.svg#x"/></use></svg>', $opts)['svg'],
        );
        self::assertSame(
            '<svg xmlns="http://www.w3.org/2000/svg"><rect/></svg>',
            SvgSanitizer::sanitize('<svg><rect><animateTransform attributeName="transform"/></rect></svg>')['svg'],
        );
    }

    public function testMalformedInputIsRefused(): void
    {
        foreach (['', 'x<svg/>', '<svg><rect></svg>', '<svg/><svg/>', '<SVG/>', '<svg><g>', '<svg a="b>'] as $source) {
            self::assertSame(['svg' => '', 'ok' => false], SvgSanitizer::sanitize($source), $source);
        }
    }
}
