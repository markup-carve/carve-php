<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

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
            'double hyphen' => ['<p>x<?a--b>y</p>', "x{% ?a--b %}y\n"],
            'trailing hyphen' => ['<p>x<?a->y</p>', "x{% ?a- %}y\n"],
            'unclosed instruction' => ['<p>x<?foo', "x{% ?foo %}\n"],
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
        $expected = 'before <?x> after' . ($tag === 'plaintext' ? '</plaintext>' : '');
        $this->assertSame($expected, $element?->textContent);
        $this->assertSame(XML_TEXT_NODE, $element?->firstChild?->nodeType);
    }

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
