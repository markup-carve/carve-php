<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Render;

use MarkupCarve\Carve\Html\HtmlRenderer;
use MarkupCarve\Carve\Html\UrlPolicy;
use MarkupCarve\Carve\Json\TreeDecoder;
use MarkupCarve\Carve\Json\TreeEncoder;
use MarkupCarve\Carve\OptionError;
use MarkupCarve\Carve\Options;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Options (C2-H-137), the URL policy (C2-H-159, C2-H-160, C2-H-173), profiles (C2-H-163 to C2-H-167)
 * and the JSON form of the tree (C2-A-165).
 */
final class OptionsAndPolicyTest extends RenderTestCase
{
    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function badOptions(): array
    {
        return [
            'unknown name' => [['nope' => true]],
            'boolean' => [['sections' => 'yes']],
            'enum' => [['headingIds' => 'LOWER']],
            'labels' => [['labels' => ['a' => 1]]],
            'symbols list' => [['symbols' => ['x']]],
            'both lists' => [['urlPolicy' => ['deny' => [], 'allow' => []]]],
            'policy field' => [['urlPolicy' => ['foo' => []]]],
            'renderers' => [['renderers' => ['a' => 'b']]],
            'social field' => [['social' => ['mention' => 'x']]],
            'extensions' => [['extensions' => [1]]],
            'platforms' => [['platforms' => ['gitlab']]],
        ];
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return void
     */
    #[DataProvider('badOptions')]
    public function testC2H137BadOptionIsRefused(array $options): void
    {
        $this->expectException(OptionError::class);
        Options::from($options);
    }

    /**
     * @return void
     */
    public function testC2H137Defaults(): void
    {
        $o = Options::from(['labels' => ['endnotes' => 'Notes'], 'rules' => [], 'alignTables' => true]);
        $this->assertSame('keep-case', $o->headingIds);
        $this->assertTrue($o->sections);
        $this->assertTrue($o->smartTypography);
        $this->assertFalse($o->rawHtml);
        $this->assertSame('Notes', $o->label('endnotes'));
        $this->assertSame('Back to reference', $o->label('footnoteBacklink'));
        $this->assertFalse($o->allowList);
        $this->assertArrayHasKey('javascript', $o->schemes);
    }

    /**
     * @return void
     */
    public function testC2H159SchemeTest(): void
    {
        $this->assertSame('javascript', UrlPolicy::schemeOf("java\u{0001}scri\u{00A0}pt:x"));
        $this->assertSame('javascript', UrlPolicy::schemeOf(' JavaScript:x'));
        $this->assertNull(UrlPolicy::schemeOf('/a#b:c'));
        $this->assertNull(UrlPolicy::schemeOf('1a:x'));
        $this->assertContains('vscode', UrlPolicy::defaultDenyList());
    }

    /**
     * @return void
     */
    public function testC2H160AllowList(): void
    {
        $o = Options::from(['urlPolicy' => ['allow' => ['HTTPS']]]);
        $this->assertTrue(UrlPolicy::passes('https://x', $o));
        $this->assertFalse(UrlPolicy::passes('http://x', $o));
        $this->assertTrue(UrlPolicy::passes('rel/path', $o));
        $this->assertTrue(UrlPolicy::passes('javascript:x', Options::from(['urlPolicy' => ['deny' => []]])));
    }

    /**
     * @return void
     */
    public function testC2H163CommentProfile(): void
    {
        $tree = self::doc(self::p(self::t('See '), [

            'type' => 'link',
            'href' => 'https://x.org',
            'attrs' => [

                'keyValues'
            => [['rel', 'me']],
            ], 'children' => [self::t('docs')],
        ], self::t(' and '), [

            'type' => 'image',
            'src' => 'i.png',
            'alt' => 'pic',
        ]));
        $this->assertSame(
            '<p>See <a href="https://x.org" rel="me nofollow ugc">docs</a> and [img: pic]</p>',
            self::html($tree, ['profile' => 'comment']),
        );
    }

    /**
     * @return void
     */
    public function testC2H165DegradedBlocks(): void
    {
        $tree = self::doc(
            ['type' => 'heading', 'level' => 2, 'children' => [self::t('Head')]],
            ['type' => 'block_quote', 'children' => [self::p(self::t('q'))]],
            ['type' => 'thematic_break'],
        );
        $this->assertSame("<p>## Head</p>\n<p>&gt; q</p>\n<p>---</p>", self::html($tree, ['profile' => 'minimal']));
    }

    /**
     * @return void
     */
    public function testC2H166NestingBound(): void
    {
        $em = static fn (array $c): array => ['type' => 'emphasis', 'children' => [$c]];
        $tree = self::doc(self::p($em($em($em(self::t('x'))))));
        $this->assertSame('<p><em><em>x</em></em></p>', self::html($tree, ['profile' => 'minimal']));
    }

    /**
     * @return void
     */
    public function testC2H163MaxLengthRefusesTheInput(): void
    {
        $doc = self::node(self::doc());
        [$html, $log] = HtmlRenderer::renderWithLog($doc, Options::from(['profile' => 'minimal']), str_repeat('a', 10001));
        $this->assertSame('', $html);
        $this->assertNotNull($log->error);
    }

    /**
     * @return void
     */
    public function testC2A165JsonRoundTrip(): void
    {
        $source = "# H\n";
        $json = '{"type":"document","astVersion":"2.0","children":[{"type":"heading","level"'
            . ':1,"children":[{"type":"text","value":"H","pos":{"startLine":1,"endLine":1,'
            . '"startColumn":3,"endColumn":4,"startOffset":2,"endOffset":3}}],"generatedId'
            . '":"H","attrs":{"classes":["c"]},"pos":{"startLine":1,"endLine":1,"startColu'
            . 'mn":1,"endColumn":4,"startOffset":0,"endOffset":3}}],"srcByteLength":4,"dat'
            . 'a":{}}';
        $doc = TreeDecoder::json($json);
        $this->assertNotNull($doc);
        $this->assertSame(2, $doc->children[0]->children[0]->start ?? null);
        $this->assertEquals(json_decode($json, true), json_decode(TreeEncoder::json($doc, $source), true));
        $this->assertStringContainsString('"data":{}', TreeEncoder::json($doc, $source));
    }
}
