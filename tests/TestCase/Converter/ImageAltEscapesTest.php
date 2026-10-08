<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\TestCase;

class ImageAltEscapesTest extends TestCase
{
    public function testFootnoteLabelsKeepTheirIdentityAndOpaqueContent(): void
    {
        $cases = json_decode((string)file_get_contents(__DIR__ . '/../../fixtures/markdown-table-footnotes.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($cases as $case) {
            $html = (new CarveConverter())->convert((new MarkdownToCarve())->convert($case['md']));
            self::assertSame($case['refs'], substr_count($html, 'role="doc-noteref"'), $case['name']);
            self::assertStringContainsString($case['html'], $html, $case['name']);
        }
    }

    public function testUnresolvedReferencePairStaysLiteralInsideAnImageDescription(): void
    {
        $written = (new MarkdownToCarve())->convert("![s [a][b]][r]\n\n[a]: /a\n[r]: /r\n");
        self::assertStringContainsString('alt="s [a][b]"', (new CarveConverter())->convert($written));
    }

    public function testFootnoteTextInAnImageScalarKeepsItsAuthoredLabel(): void
    {
        $written = (new MarkdownToCarve())->convert("![x [^a|b]][i]\n\n[i]: /i\n[^a|b]: Note.\n");
        self::assertStringContainsString('alt="x [^a|b]"', (new CarveConverter())->convert($written));
    }

    public function testReferenceChainsFlattenInsideImageDescriptions(): void
    {
        $written = (new MarkdownToCarve())->convert("![s [a][b][c]][r]\n\n[b]: /b\n[r]: /image\n");
        self::assertStringContainsString('alt="s a[c]"', (new CarveConverter())->convert($written));
    }

    public function testImportedFootnotesKeepReferencesAndAvoidCollisions(): void
    {
        $md = "| Note[^a\\|b] | c |\n|---|---|\n\nText[^a|b].\n\n`[^a|b]`\n\n[^a|b]: Note.\n\n[^carve-import-footnote-1]: Existing.\n";
        $written = (new MarkdownToCarve())->convert($md);
        self::assertSame(3, substr_count($written, '[^carve-import-footnote-2]'));
        self::assertStringContainsString('`[^a|b]`', $written);
        self::assertStringContainsString('[^carve-import-footnote-1]: Existing.', $written);
        $html = (new CarveConverter())->convert($written);
        self::assertSame(2, substr_count($html, 'role="doc-noteref"'));
        self::assertStringContainsString('<code>[^a|b]</code>', $html);
        self::assertStringContainsString('Note.<a href="#fnref1"', $html);
    }

    public function testPipeReferencesStayLiteralOrResolveTheirChainTail(): void
    {
        $cases = [
            ['![p\\|q]', '[c]: /c', '![p|q]'],
            ['![p\\|q][]', '[c]: /c', '![p|q][]'],
            ['![p\\|q][c]', '[c]: /c', 'alt="p|q"'],
            ['[p\\|q][missing]', '[c]: /c', '[p|q][missing]'],
            ['[t][q\\|r][s]', '[q|r]: /q', '<a href="/q">t</a>[s]'],
            ['[t][q\\|r][s]', "[q|r]: /q\n[s]: /s", '<a href="/q">t</a><a href="/s">s</a>'],
            ['[t][q\\|r][s][z]', "[s]: /s\n[z]: /z", '[t]<a href="/s">q|r</a><a href="/z">z</a>'],
            ['[a\\|b][]', '', '[a|b][]'],
            ['[a\\|b][]', '[a\\|b]: /u', '[a|b][]'],
            ['[a\\|b]', '[a\\|b]: /u', '[a|b]'],
            ['[t][q\\|r]', '', '[t][q|r]'],
            ['![t][q\\|r]', '', '![t][q|r]'],
            ['[t][q\\|r][s]', '[s]: /s', '[t]<a href="/s">q|r</a>'],
            ['![t][q\\|r][s]', '[s]: /s', '![t]<a href="/s">q|r</a>'],
            ['[t][a\\|b]', '[a\\|b]: /u', '[t][a|b]'],
            ['![a\\|b][missing]', '', '![a|b][missing]'],
        ];
        foreach ($cases as [$body, $definition, $expected]) {
            $source = (new MarkdownToCarve())->convert("| $body | c |\n|---|---|\n\n$definition\n");
            $html = (new CarveConverter())->convert($source);
            self::assertStringContainsString($expected, $html, $body);
            self::assertSame(2, preg_match_all('/<th\b/', $html), $body);
        }
    }

    public function testImageTitlePipesAndBackticksRoundTripInTables(): void
    {
        $source = "| ![a\\`b](/i \"c\\`d\\|e\") | `x` |\n|---|---|\n";
        $converter = new CarveConverter();
        $html = $converter->convert($source);
        self::assertStringContainsString('alt="a`b" title="c`d|e"', $html);
        self::assertStringContainsString('<code>x</code>', $html);
        self::assertSame($html, $converter->convert((new CarveRenderer())->render($converter->parse($source))));
    }

    public function testPipeBearingEmailAutolinkStaysInOneTableCell(): void
    {
        $source = (new MarkdownToCarve())->convert("| <a\\|b@x.y> | c |\n|---|---|\n");
        $html = (new CarveConverter())->convert($source);
        self::assertStringContainsString('<a href="mailto:a%7Cb@x.y">a|b@x.y</a>', $html);
        self::assertSame(2, preg_match_all('/<th\b/', $html));
    }

    public function testNativeEscapesAndWriterRoundTrips(): void
    {
        $cases = json_decode((string)file_get_contents(__DIR__ . '/../../fixtures/image-alt-escapes.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($cases as $case) {
            $converter = new CarveConverter();
            self::assertSame($case['html'], str_replace('&apos;', '&#039;', trim($converter->convert($case['source']))), $case['name']);
            $image = preg_replace('/^<p>x | y<\/p>$/', '', $case['html']) ?? '';
            self::assertStringContainsString($image, str_replace('&apos;', '&#039;', $converter->convert($case['table'])), $case['name']);
            $writer = new CarveRenderer();
            $tableWritten = $writer->render($converter->parse($case['table']));
            self::assertStringContainsString($image, str_replace('&apos;', '&#039;', $converter->convert($tableWritten)), $case['name']);
            $written = $writer->render($converter->parse($case['source']));
            self::assertSame($case['html'], str_replace('&apos;', '&#039;', trim($converter->convert($written))), $case['name']);
            self::assertSame($written, $writer->render($converter->parse($written)), $case['name']);
        }
    }

    public function testImportedTableImageIsNativeAndSafe(): void
    {
        $result = (new MarkdownToCarve())->convertWithFidelityReport("| ![a\\|b](/i) |\n|---|\n| c |");
        self::assertStringContainsString('![', $result->value);
        self::assertStringNotContainsString('{=html}', $result->value);
        self::assertStringContainsString('<img src="/i" alt="a|b">', (new CarveConverter(safeMode: true))->convert($result->value));
        foreach ($result->diagnostics as $diagnostic) {
            self::assertNotSame('markdown-table-image-alt-pipe', $diagnostic->code);
        }
    }

    public function testImportedImageTablesMatchGfm(): void
    {
        $cases = json_decode((string)file_get_contents(__DIR__ . '/../../fixtures/markdown-table-image-alt-gfm.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($cases as $index => $case) {
            $source = (new MarkdownToCarve())->convert($case['md']);
            $actual = (new CarveConverter())->convert($source);
            $actual = preg_replace('/\s+scope="(?:col|row)"/', '', $actual) ?? $actual;
            $normalize = static fn (string $html): string => preg_replace('/>\s+</', '><', trim(str_replace(['&quot;', ' />', "\n"], ['"', '>', ''], $html))) ?? $html;
            self::assertSame($normalize($case['html']), $normalize($actual), 'fixture ' . $index);
        }
    }
}
