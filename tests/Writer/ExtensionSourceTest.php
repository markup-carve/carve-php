<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Extension\Tabs;
use MarkupCarve\Carve\Test\Support\WriterGate;
use MarkupCarve\Carve\Writer\Writer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ExtensionSourceTest extends TestCase
{
    /**
     * @return array<string, array{array<string, string>, string, array<string>}>
     */
    public static function blockCases(): array
    {
        return [
            'C2-X-027 colon-run div' => [['form' => 'div', 'name' => 'foo', 'body' => "a\n"], "::: foo\na\n:::\n", []],
            'C2-X-027 empty div body' => [['form' => 'div', 'name' => 'foo', 'body' => ''], "::: foo\n:::\n", []],
            'C2-X-027 admonition name' => [['form' => 'div', 'name' => 'note', 'body' => "a\n"], "::: note\na\n:::\n", []],
            'C2-X-027 longer run than a div inside' => [
                ['form' => 'div', 'name' => 'tabs', 'body' => "::: tab \"A\"\nB\n:::\n"],
                ":::: tabs\n::: tab \"A\"\nB\n:::\n::::\n",
                [],
            ],
            'C2-X-027 fence' => [['form' => 'fence', 'name' => 'php', 'body' => "x\n"], "```php\nx\n```\n", []],
            'C2-X-023 a fence body without its line end reads back as another value' => [
                ['form' => 'fence', 'name' => 'php', 'body' => 'x'],
                "F\n",
                ['structure-unspellable'],
            ],
            'C2-X-023 a body that is not canonical' => [
                ['form' => 'div', 'name' => 'foo', 'body' => "-   a\n"],
                "F\n",
                ['structure-unspellable'],
            ],
            'C2-X-025 a raw format name' => [['form' => 'fence', 'name' => '=html', 'body' => "x\n"], "F\n", ['structure-unspellable']],
        ];
    }

    /**
     * @param array<string, string> $source
     * @param string $expected
     * @param array<string> $codes
     *
     * @return void
     */
    #[DataProvider('blockCases')]
    public function testBlockSourceForm(array $source, string $expected, array $codes): void
    {
        $result = Writer::writeWithDiagnostics(self::doc([
            [
                'type' => 'block_extension',
                'name' => 'org.example.k',
                'version' => '1',
                'children' => [],
                'fallback' => [['type' => 'paragraph', 'children' => [['type' => 'text', 'value' => 'F']]]],
                'source' => $source,
            ],
        ]));

        $this->assertSame($expected, $result->source);
        $this->assertSame($codes, $result->codes());
    }

    public function testInlineSourceForm(): void
    {
        $node = static fn (array $source): array => self::doc([
            [

                'type' => 'paragraph',
                'children' => [
                    ['type' => 'text', 'value' => 'x '],
                    [
                        'type' => 'inline_extension',
                        'name' => 'org.example.k',
                        'version' => '1',
                        'children' => [],
                        'fallback' => [['type' => 'text', 'value' => 'F']],
                        'source' => $source,
                    ],
                ],
            ],
        ]);

        // C2-X-027
        $this->assertSame("x :kbd[A *b*]\n", Carve::write($node(['form' => 'inline', 'name' => 'kbd', 'body' => 'A *b*'])));
        // C2-X-028: a qualified name is no inline extension name of the core.
        $result = Writer::writeWithDiagnostics($node(['form' => 'inline', 'name' => 'org.x', 'body' => 'A']));
        $this->assertSame("x F\n", $result->source);
        $this->assertSame(['structure-unspellable'], $result->codes());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function parsedInlineCases(): array
    {
        return [
            'wiki and cite with attributes' => [":wiki[Page] and :cite[doe]{locator=\"p. 4\"}\n"],
            'emphasis in the body' => [":wiki[*a* /b/] x\n"],
            'escaped bracket in the body' => [":wiki[a \\] b]\n"],
            'two keys' => [":cite[doe; roe]\n"],
            'inside a link' => ["[:wiki[Page]](u)\n"],
        ];
    }

    /**
     * C2-X-023, C2-X-026: a parsed inline extension records its body, and a writer without the extension writes it back
     * with no loss.
     */
    #[DataProvider('parsedInlineCases')]
    public function testParsedInlineSourceFormWritesItsBody(string $source): void
    {
        $tree = Carve::parse($source, ['extensions' => ['carve.wiki', 'carve.cite']]);
        $result = Carve::writeWithDiagnostics($tree);
        $this->assertSame($source, $result->source);
        $this->assertSame([], $result->codes());
        $withExtensions = Carve::writeWithDiagnostics($tree, null, ['extensions' => ['carve.wiki', 'carve.cite']]);
        $this->assertSame($source, $withExtensions->source);
        $this->assertSame([], $withExtensions->codes());
    }

    public function testC2X049TabsReadBackThroughTheTransform(): void
    {
        $source = ":::: tabs\n::: tab \"A\"\nB\n:::\n::::\n";
        $tree = WriterGate::tree($source);
        $tree['children'][0] = [
            'type' => 'block_extension',
            'name' => 'carve.tabs',
            'version' => '1',
            'children' => [],
            'fallback' => [['type' => 'paragraph', 'children' => [['type' => 'text', 'value' => 'F']]]],
            'source' => ['form' => 'div', 'name' => 'tabs', 'body' => "::: tab \"A\"\nB\n:::\n"],
        ];

        // A record whose node the transform does not give back is written as its fallback.
        $result = Writer::writeWithDiagnostics($tree, null, ['extensions' => Tabs::extensions()]);
        $this->assertSame("F\n", $result->source);
        $this->assertSame(['structure-unspellable'], $result->codes());
        // Without the extension the core reading decides (C2-X-023).
        $this->assertSame($source, Carve::write($tree));
    }

    public function testWriteBlocks(): void
    {
        // C2-X-026
        $this->assertSame('', Writer::writeBlocks([]));
        $blocks = WriterGate::tree("= A\n\n-   b\n")['children'];
        $this->assertSame("= A\n\n- b\n", Writer::writeBlocks(is_array($blocks) ? $blocks : []));
    }

    public function testTabsSourceUsesDocumentLinkKeys(): void
    {
        foreach (['see [r0].', '\\[r0] lit'] as $body) {
            $source = "[r0]: http://x/0\n\n:::: tabs\n::: tab \"T0\"\n$body\n:::\n::::\n";
            $result = Writer::formatWithDiagnostics($source, ['extensions' => ['carve.tabs']]);
            $this->assertSame($source, $result->source);
            $this->assertSame([], $result->codes());
            $again = Writer::formatWithDiagnostics($result->source, ['extensions' => ['carve.tabs']]);
            $this->assertSame($source, $again->source);
            $this->assertSame([], $again->codes());
        }
    }

    public function testInlineExtensionBesideDefinitionKeepsItsSource(): void
    {
        $source = "[r0]: http://x/0\n\n:wiki[Page]\n";
        $tree = Carve::parse($source, ['extensions' => ['carve.wiki']]);
        $result = Carve::writeWithDiagnostics($tree, null, ['extensions' => ['carve.wiki']]);
        $this->assertSame($source, $result->source);
        $this->assertSame([], $result->codes());
    }

    public function testTabsShortcutWithEscapedReferenceKeyKeepsItsSource(): void
    {
        $source = "[a\\\\b]: /u\n\n:::: tabs\n::: tab \"T\"\nsee [a\\\\b].\n:::\n::::\n";
        $result = Writer::formatWithDiagnostics($source, ['extensions' => ['carve.tabs']]);
        $this->assertSame($source, $result->source);
        $this->assertSame([], $result->codes());
    }

    public function testIncludedTabsUseDefinitionsOfTheirOwnFile(): void
    {
        $resolver = static fn (): array => [
            'text' => ":::: tabs\n::: tab \"T\"\n[r] lit\n:::\n::::\n",
            'id' => 'child',
        ];
        $tree = json_decode(Carve::toJson("[r]: /u\n\n{{ child }}\n", [
            'includes' => $resolver,
            'extensions' => ['carve.tabs'],
        ]), true);
        $source = $tree['children'][1]['children'][0]['source']['body'];
        $this->assertSame("::: tab \"T\"\n[r] lit\n:::\n", $source);
    }

    /**
     * @param array<array<string, mixed>> $children
     *
     * @return array<string, mixed>
     */
    private static function doc(array $children): array
    {
        return [
            'type' => 'document',
            'astVersion' => '2.0',
            'extensions' => [['name' => 'org.example.k', 'version' => '1', 'required' => false, 'slots' => []]],
            'children' => $children,
        ];
    }
}
