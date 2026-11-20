<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\ProseMirror;

use InvalidArgumentException;
use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Json\TreeEquality;
use MarkupCarve\Carve\ProseMirror\ProseMirrorRenderer;
use MarkupCarve\Carve\ProseMirror\ProseMirrorToCarve;
use MarkupCarve\Carve\Writer\CarveWriteError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Documents a Tiptap editor makes, the inputs of the 0.1 bridge tests, and destinations the editor must not resolve.
 */
final class EditorDocumentsTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function legacySources(): array
    {
        $data = json_decode((string)file_get_contents(dirname(__DIR__) . '/fixtures/prosemirror/legacy-0.1-sources.json'), true);
        $out = [];
        foreach ($data['sources'] as $i => $case) {
            $out[$case['from'] . ' ' . $i] = [$case['carve']];
        }

        return $out;
    }

    #[DataProvider('legacySources')]
    public function testEveryInputOfTheOldBridgeTestsRoundTrips(string $source): void
    {
        $tree = Carve::parse($source);
        $json = (new ProseMirrorRenderer())->renderJson($tree);
        $converter = new ProseMirrorToCarve();

        $this->assertTrue(TreeEquality::equal(
            json_decode(Carve::encodeTree($tree), true),
            json_decode((string)json_encode($converter->toTree($json)), true),
        ));
        try {
            $direct = Carve::write($tree);
        } catch (CarveWriteError) {
            return;
        }
        $this->assertSame($direct, $converter->convert($json));
    }

    public function testStockMarksBecomeCarve(): void
    {
        $doc = self::doc([
            self::p(
                self::t('Plain '),
                self::t('bold', ['bold']),
                self::t(' and '),
                self::t('both', ['bold', 'italic']),
                self::t(' then ', ['italic']),
                self::t('code', ['code']),
                self::t(', '),
                [

                    'type' => 'text',
                    'text' => 'a link',
                    'marks' => [
                        ['type' => 'link', 'attrs' => ['href' => 'https://example.com', 'target' => '_blank']],
                    ],
                ],
                ['type' => 'hardBreak'],
                self::t('next line'),
            ),
        ]);

        $this->assertSame(
            "Plain *bold* and {/*both* then /}`code`, [a link](https://example.com)\\\nnext line\n",
            (new ProseMirrorToCarve())->convert($doc),
        );
    }

    public function testStockBlocksBecomeCarve(): void
    {
        $cell = static fn (string $type, array $content): array => [
            'type' => $type,
            'attrs' => ['colspan' => 1, 'rowspan' => 1, 'colwidth' => null],
            'content' => $content,
        ];
        $doc = self::doc([
            ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [self::t('Title')]],
            ['type' => 'bulletList', 'content' => [self::item(self::p(self::t('one'))), self::item(self::p(self::t('two')))]],
            ['type' => 'orderedList', 'attrs' => ['start' => 3], 'content' => [self::item(self::p(self::t('three')))]],
            [

                'type' => 'taskList',
                'content' => [
                    ['type' => 'taskItem', 'attrs' => ['checked' => true], 'content' => [self::p(self::t('done'))]],
                    ['type' => 'taskItem', 'attrs' => ['checked' => false], 'content' => [self::p(self::t('open'))]],
                ],
            ],
            ['type' => 'blockquote', 'content' => [self::p(self::t('quoted'))]],
            ['type' => 'codeBlock', 'attrs' => ['language' => 'php'], 'content' => [self::t('echo 1;')]],
            ['type' => 'horizontalRule'],
            self::p(['type' => 'image', 'attrs' => ['src' => '/a.png', 'alt' => 'A', 'title' => null]]),
            [

                'type' => 'table',
                'content' => [
                    [

                        'type' => 'tableRow',
                        'content' => [
                            $cell('tableHeader', [self::p(self::t('h1'))]),
                            $cell('tableHeader', [self::p(self::t('h2'))]),
                        ],
                    ],
                    ['type' => 'tableRow', 'content' => [$cell('tableCell', [self::p(self::t('a'))]), $cell('tableCell', [self::p()])]],
                ],
            ],
            self::p(),
        ]);

        $this->assertSame(
            "== Title\n\n- one\n- two\n\n{start=3}\n. three\n\n- [x] done\n- [ ] open\n\n> quoted\n\n```php\necho 1;\n```\n\n***\n\n"
            . "![A](/a.png)\n\n|= h1 |= h2 |\n| a | |\n",
            (new ProseMirrorToCarve())->convert($doc),
        );
    }

    public function testAnEditKeepsTheLoadedBlocksAndAddsTheNewOnes(): void
    {
        $pm = (new ProseMirrorRenderer())->renderSource("{.lead}\nFirst *one*.\n\n- a\n- b\n");
        $pm['content'][1]['content'][] = self::item(self::p(self::t('c')));
        $pm['content'][] = self::p(self::t('Added.'));
        $converter = new ProseMirrorToCarve();

        $this->assertSame("{.lead}\nFirst *one*.\n\n- a\n- b\n- c\n\nAdded.\n", $converter->convert($pm));
        $this->assertSame([0, 1, null], $converter->toTreeWithOrigins($pm)['origins']);
    }

    public function testJsonAndArrayInputAgree(): void
    {
        $source = "# Title\n\nSome _text_ with [a link](/x){.c}.\n";
        $renderer = new ProseMirrorRenderer();
        $json = $renderer->renderJson(Carve::parse($source));
        $converter = new ProseMirrorToCarve();

        $this->assertSame(json_decode($json, true), $renderer->renderSource($source));
        $this->assertSame($converter->convert($json), $converter->convert(json_decode($json, true)));
        $this->assertSame(Carve::format($source), $converter->convert($json));
    }

    public function testAnUnknownNodeIsAnError(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('no Carve node for the ProseMirror node "calloutBox"');

        (new ProseMirrorToCarve())->convert(self::doc([['type' => 'calloutBox', 'content' => [self::p(self::t('x'))]]]));
    }

    public function testAnUnknownMarkIsAnError(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('no Carve node for the ProseMirror mark "textStyle"');

        (new ProseMirrorToCarve())->convert(self::doc([self::p(self::t('x', ['textStyle']))]));
    }

    public function testANonDocumentIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new ProseMirrorRenderer())->render(['type' => 'paragraph', 'children' => []]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function deniedProvider(): array
    {
        return [
            'link' => ['Siehe [x](javascript:alert(1)) und mehr.'],
            'mixed case' => ['[x](JaVaScRiPt:x)'],
            'vbscript' => ['[x](vbscript:x)'],
            'file' => ['[x](file:///etc/passwd)'],
            'data image' => ['![a](data:image/png;base64,AA)'],
            'reference link' => ["[x][r]\n\n[r]: javascript:x"],
            'in a list item' => ['- [x](javascript:x)'],
            'in a quote' => ['> ![a](data:text/html,x)'],
            'under emphasis' => ['*[x](javascript:x)*'],
        ];
    }

    #[DataProvider('deniedProvider')]
    public function testADeniedDestinationNeverReachesALiveAttribute(string $source): void
    {
        $tree = Carve::parse($source);
        $renderer = new ProseMirrorRenderer();
        $pm = $renderer->render($tree);

        $this->assertNotSame([], $renderer->blanked());
        foreach (self::liveDestinations($pm) as $url) {
            $this->assertNull($url);
        }
        $this->assertTrue(TreeEquality::equal(
            json_decode(Carve::encodeTree($tree), true),
            json_decode((string)json_encode((new ProseMirrorToCarve())->toTree($pm)), true),
        ));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function allowedProvider(): array
    {
        return [
            'relative' => ['[x](/ok) ![a](a.png)'],
            'https' => ['[x](https://a.b/?q=javascript:x)'],
            'scheme-like word' => ['[x](javascriptx:y)'],
            'mailto' => ['[x](mailto:a@b.c)'],
        ];
    }

    #[DataProvider('allowedProvider')]
    public function testAnAllowedDestinationIsKept(string $source): void
    {
        $renderer = new ProseMirrorRenderer();
        $live = self::liveDestinations($renderer->renderSource($source));

        $this->assertSame([], $renderer->blanked());
        $this->assertNotContains(null, $live);
    }

    public function testTheHostUrlPolicyApplies(): void
    {
        $renderer = new ProseMirrorRenderer(['urlPolicy' => ['allow' => ['https']]]);
        $renderer->renderSource('[a](https://a.b) [b](http://a.b)');

        $this->assertSame(['http://a.b'], $renderer->blanked());
    }

    /**
     * The `href` of every link mark and the `src` of every image.
     *
     * @param array<string, mixed> $node
     *
     * @return array<string|null>
     */
    private static function liveDestinations(array $node): array
    {
        $out = [];
        if (($node['type'] ?? null) === 'image') {
            $out[] = $node['attrs']['src'];
        }
        foreach ($node['marks'] ?? [] as $mark) {
            if ($mark['type'] === 'link') {
                $out[] = $mark['attrs']['href'];
            }
        }
        foreach ($node['content'] ?? [] as $child) {
            array_push($out, ...self::liveDestinations($child));
        }

        return $out;
    }

    /**
     * @param array<array<string, mixed>> $content
     *
     * @return array<string, mixed>
     */
    private static function doc(array $content): array
    {
        return ['type' => 'doc', 'content' => $content];
    }

    /**
     * @return array<string, mixed>
     */
    private static function p(array ...$content): array
    {
        return $content === [] ? ['type' => 'paragraph'] : ['type' => 'paragraph', 'content' => $content];
    }

    /**
     * @param string $text
     * @param array<string> $marks
     *
     * @return array<string, mixed>
     */
    private static function t(string $text, array $marks = []): array
    {
        $node = ['type' => 'text', 'text' => $text];
        if ($marks !== []) {
            $node['marks'] = array_map(static fn (string $mark): array => ['type' => $mark], $marks);
        }

        return $node;
    }

    /**
     * @param array<string, mixed> $paragraph
     *
     * @return array<string, mixed>
     */
    private static function item(array $paragraph): array
    {
        return ['type' => 'listItem', 'content' => [$paragraph]];
    }
}
