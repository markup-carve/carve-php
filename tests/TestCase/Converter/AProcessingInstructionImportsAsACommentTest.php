<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use DOMDocument;
use MarkupCarve\Carve\Converter\HtmlDomLoader;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhp;
use PHPUnit\Framework\TestCase;

class AProcessingInstructionImportsAsACommentTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function instructionProvider(): array
    {
        return [
            'double hyphen' => ['<p>x<?a--b>y</p>', HtmlDomLoader::usesHtml5() ? "x{% ?a--b %}y\n" : "xy\n"],
            'trailing hyphen' => ['<p>x<?a->y</p>', HtmlDomLoader::usesHtml5() ? "x{% ?a- %}y\n" : "xy\n"],
            'unclosed instruction' => ['<p>x<?foo', HtmlDomLoader::usesHtml5() ? "x{% ?foo %}\n" : "x{% ?foo</carve-import-root %}\n"],
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
        $cases = [];
        foreach (['script', 'style', 'textarea', 'title', 'xmp', 'iframe', 'noembed', 'noframes', 'plaintext'] as $tag) {
            $cases[$tag] = [$tag];
        }

        return $cases;
    }

    #[DataProvider('opaqueProvider')]
    public function testOpaqueContextsKeepInstructionText(string $tag): void
    {
        $document = HtmlDomLoader::load('<' . $tag . '>before <?x> after</' . $tag . '>');
        $element = $document->getElementsByTagName($tag)->item(0);
        if (!HtmlDomLoader::usesHtml5()) {
            $legacy = new DOMDocument();
            $previous = libxml_use_internal_errors(true);
            try {
                $legacy->loadHTML('<?xml encoding="UTF-8"><' . $tag . '>before <?x> after</' . $tag . '>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
            } finally {
                libxml_clear_errors();
                libxml_use_internal_errors($previous);
            }
            $this->assertSame($legacy->saveHTML(), $document->saveHTML());

            return;
        }
        $expected = 'before <?x> after' . ($tag === 'plaintext' ? '</plaintext>' : '');
        $this->assertSame($expected, $element?->textContent);
        $this->assertSame(XML_TEXT_NODE, $element?->firstChild?->nodeType);
    }

    #[RequiresPhp('>=8.4.0')]
    public function testNoscriptUsesTheScriptingDisabledTree(): void
    {
        $document = HtmlDomLoader::fragment('<noscript>before <?x> after</noscript>');
        $element = $document->getElementsByTagName('noscript')->item(0);
        $this->assertSame('before  after', $element?->textContent);
        $this->assertSame('?x', $element?->childNodes->item(1)?->nodeValue);
        $this->assertSame(XML_COMMENT_NODE, $element?->childNodes->item(1)?->nodeType);
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
