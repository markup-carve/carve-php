<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\ProseMirror;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\ProseMirror\ProseMirrorRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * markup-carve/carve#6009. The editor resolves the URLs the bridge writes, so
 * PART 9 section 25 binds the bridge: a denied scheme blanks as it does in
 * HTML, and the report says so.
 */
class ADeniedDestinationIsBlankedInTheEditorTest extends TestCase
{
    public function testTheReproductionBlanksBothDestinations(): void
    {
        $renderer = new ProseMirrorRenderer();
        $pm = $renderer->render((new CarveConverter())->parse(
            'Siehe [x](javascript:alert(1)) und ![b](data:image/png;base64,AA).',
        ));

        $content = $pm['content'][0]['content'];
        $this->assertSame(['href' => ''], $content[1]['marks'][0]['attrs']);
        $this->assertSame(['src' => '', 'alt' => 'b'], $content[3]['attrs']);
        $this->assertArrayHasKey('destination-denied', $renderer->droppedTypes());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function deniedProvider(): array
    {
        return [
            'mixed case' => ['[x](JaVaScRiPt:x)', 'javascript'],
            'vbscript' => ['[x](vbscript:x)', 'vbscript'],
            'file' => ['[x](file:///etc/passwd)', 'file'],
            'OS handler' => ['[x](ms-msdt:x)', 'ms-msdt'],
            'data image' => ['![a](data:image/png;base64,AA)', 'data:'],
            'autolink' => ['<javascript:alert(1)>', '"href":"javascript'],
            'reference link and its definition' => ["[x][r]\n\n[r]: javascript:x", 'javascript'],
            'carried definition line' => ["{.c}\n[r]: javascript:x\n\n[x][r]", 'javascript'],
            'authored attribute on a carried definition line' => ["[x][r]\n\n[r]: /ok {ping=\"javascript:x\"}", 'javascript'],
            'authored ping' => ['[x](/ok){ping="javascript:x"}', 'javascript'],
            'authored srcset candidate' => ['![a](/ok){srcset="a.png 1x, javascript:x 2x"}', 'javascript'],
            'NBSP before an authored value' => ["[x](/ok){data-x=\"\u{00A0}javascript:x\"}", 'javascript'],
            'BOM before an authored value' => ["[x](/ok){data-x=\"\u{FEFF}javascript:x\"}", 'javascript'],
            'in a list item' => ['- [x](javascript:x)', 'javascript'],
            'in a quote' => ['> ![a](data:text/html,x)', 'data:'],
        ];
    }

    #[DataProvider('deniedProvider')]
    public function testADeniedSchemeNeverReachesTheEditor(string $source, string $needle): void
    {
        $renderer = new ProseMirrorRenderer();
        $json = $renderer->renderJson((new CarveConverter())->parse($source), JSON_UNESCAPED_SLASHES);

        $this->assertStringNotContainsString($needle === '"href":"javascript' ? $needle : $needle . ':', $json);
        $this->assertArrayHasKey('destination-denied', $renderer->droppedTypes());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function allowedProvider(): array
    {
        return [
            'https' => ['[x](https://a.b)'],
            'mailto' => ['[x](mailto:a@b.c)'],
            'relative' => ['![a](img/a.png)'],
            'scheme-like word' => ['[x](javascriptx:y)'],
            'denied word after the scheme' => ['[x](https://a.b/?q=javascript:x)'],
            'authored prose attribute' => ['[x](/ok){data-x="see javascript later"}'],
        ];
    }

    #[DataProvider('allowedProvider')]
    public function testAnAllowedDestinationIsKeptAndNotReported(string $source): void
    {
        $document = (new CarveConverter())->parse($source);
        $renderer = new ProseMirrorRenderer();
        $json = $renderer->renderJson($document, JSON_UNESCAPED_SLASHES);

        $this->assertStringNotContainsString('":""', $json);
        $this->assertSame([], $renderer->droppedTypes());
    }
}
