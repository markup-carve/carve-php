<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Extension;

use DOMDocument;
use DOMXPath;
use MarkupCarve\Carve\Extension\SvgSanitizeOptions;
use MarkupCarve\Carve\Extension\SvgSanitizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SvgSanitizerTest extends TestCase
{
    /**
     * @return array<string, array{string, array<string, mixed>, string, bool}>
     */
    public static function parityRows(): array
    {
        $path = dirname(__DIR__) . '/fixtures/svg-sanitize/parity.json';
        /** @var array<array{from: string, src: string, opts: array<string, mixed>, svg: string, ok: bool}> $rows */
        $rows = json_decode((string)file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
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

    public function testMalformedInputIsRefused(): void
    {
        foreach (['', 'x<svg/>', '<svg><rect></svg>', '<svg/><svg/>', '<SVG/>', '<svg><g>', '<svg a="b>'] as $source) {
            self::assertSame(['svg' => '', 'ok' => false], SvgSanitizer::sanitize($source), $source);
        }
    }
}
