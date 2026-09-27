<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use DOMDocument;
use MarkupCarve\Carve\Converter\HtmlDomLoader;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AProcessingInstructionImportsAsACommentTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function instructionProvider(): array
    {
        return [
            'empty instruction' => ['<p>HTML<?></p>', "HTML{% ? %}\n"],
            'named instruction' => ['<p>x <?foo bar?> y</p>', "x {% ?foo bar? %} y\n"],
            'link in cell' => [
                '<table><tr><th>A</th></tr><tr><td><a href="/u">HTML<?><br /># x<?></a></td></tr></table>',
                "|= A |\n| [HTML{% ? %} # x{% ? %}](/u) |\n",
            ],
            'unquoted apostrophe' => ["<p title=it's>x<?x></p>", "{title=\"it's\"}\nx{% ?x %}\n"],
            'first closing angle' => ['<p>x<?foo >bar?>y</p>', "x{% ?foo  %}bar?>y\n"],
        ];
    }

    #[DataProvider('instructionProvider')]
    public function testInstructionTextSurvives(string $html, string $expected): void
    {
        $this->assertSame($expected, (new HtmlToCarve())->convert($html));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function opaqueProvider(): array
    {
        $cases = [
            'double quoted attribute' => ['<p title="<?x>">text</p>'],
            'single quoted attribute' => ["<p title = '<?x>'>text</p>"],
            'existing comment' => ['<p>x<!-- <?x> -->y</p>'],
            'double hyphen' => ['<p>x<?a--b>y</p>'],
            'trailing hyphen' => ['<p>x<?a->y</p>'],
            'unclosed instruction' => ['<p>x<?foo'],
        ];
        foreach (
            [
                'script', 'style', 'textarea', 'title', 'xmp', 'iframe', 'noembed', 'noframes', 'noscript', 'plaintext',
            ] as $tag
        ) {
            $cases[$tag] = ['<' . $tag . '>before <?x> after</' . $tag . '>'];
        }

        return $cases;
    }

    #[DataProvider('opaqueProvider')]
    public function testOpaqueContextsStayUnchanged(string $html): void
    {
        $expected = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $expected->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $this->assertSame($expected->saveHTML(), HtmlDomLoader::load($html)->saveHTML());
    }

    public function testScannerResumesAfterOpaqueContexts(): void
    {
        $html = '<div><script>"<?x>"</script><!-- <?x> --><p title="<?x>">x<?y></p></div>';
        $document = HtmlDomLoader::load($html);
        $paragraph = $document->getElementsByTagName('p')->item(0);
        $this->assertSame('<?x>', $paragraph?->getAttribute('title'));
        $this->assertSame('?y', $paragraph?->lastChild?->nodeValue);
        $this->assertSame(XML_COMMENT_NODE, $paragraph?->lastChild?->nodeType);
    }
}
