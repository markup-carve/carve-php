<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Json\TreeEncoder;
use MarkupCarve\Carve\Writer\Writer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * C2-W-088, C2-W-090, C2-W-092: a block opener or a symbol is escaped whatever inline nodes its characters come from.
 */
final class OpenerAcrossTextNodesTest extends TestCase
{
    /**
     * @return array<string, array{array<mixed>, string}>
     */
    public static function cases(): array
    {
        $t = static fn (string $v): array => ['type' => 'text', 'value' => $v];
        $p = static fn (array ...$c): array => ['type' => 'paragraph', 'children' => $c];
        $quote = static fn (array ...$b): array => ['type' => 'block_quote', 'children' => $b];
        $list = static fn (array ...$b): array => [
            'type' => 'list',
            'marker' => ['kind' => 'bullet', 'char' => '-'],
            'spacing' => 'tight',
            'children' => [['type' => 'list_item', 'children' => $b]],
        ];
        $en = ['type' => 'smart_punctuation', 'kind' => 'en_dash', 'glyph' => "\u{2013}", 'source' => '--'];
        $em = ['type' => 'smart_punctuation', 'kind' => 'em_dash', 'glyph' => "\u{2014}", 'source' => '---'];

        return [
            'a bullet marker in a quote' => [$quote($p($t('-'), $t(' a'))), "> \\- a\n"],
            'a bullet marker in an item' => [$list($p($t('-'), $t(' a'))), "- \\- a\n"],
            'a bullet marker in a later paragraph of an item' => [$list($p($t('x')), $p($t('-'), $t(' a'))), "- x\n\n  \\- a\n"],
            'a bullet marker in a nested quote' => [$quote($quote($p($t('-'), $t(' a')))), "> > \\- a\n"],
            'a quote marker in a quote' => [$quote($p($t('>'), $t(' a'))), "> \\> a\n"],
            'an ordered marker at the top' => [$p($t('1.'), $t(' a')), "1\\. a\n"],
            'an ordered marker split after its digits' => [$p($t('1'), $t('1. a')), "11\\. a\n"],
            'an ordered marker in a quote' => [$quote($p($t('1.'), $t(' a'))), "> 1\\. a\n"],
            'a letter marker in a quote' => [$quote($p($t('a.'), $t(' b'))), "> a\\. b\n"],
            'a parenthesis marker in an item' => [$list($p($t('1)'), $t(' a'))), "- 1\\) a\n"],
            'a symbol over two text nodes' => [$p($t(':a'), $t(':')), "\\:a:\n"],
            'a symbol over three text nodes' => [$p($t(':'), $t('a'), $t('b:')), "\\:ab:\n"],
            'a symbol name with a dash node' => [$p($t('q :'), $en, $t('x:')), "q \\:--x:\n"],
            'a symbol in a quote' => [$quote($p($t(':a'), $t(':'))), "> \\:a:\n"],
            'an extension name over two text nodes' => [$p($t(':ab'), $t('c[x]')), "\\:abc[x]\n"],
            'a name ending in a hyphen is no symbol' => [$p($t(':a'), $en, $t(':')), ":a--:\n"],
            'a name starting with an underscore is no symbol' => [$p($t(':_a:')), ":_a:\n"],
            'a split name ending in a hyphen is no symbol' => [$p($t(':a-'), $t(':')), ":a-:\n"],
            'a name broken by an em dash node' => [$p($t(':a'), $em, $t(':')), ":a---:\n"],
        ];
    }

    /**
     * @param array<mixed> $block
     * @param string $source
     *
     * @return void
     */
    #[DataProvider('cases')]
    public function testTheOpenerIsEscaped(array $block, string $source): void
    {
        $tree = ['type' => 'document', 'astVersion' => '2.0', 'children' => [$block]];
        $result = Writer::writeWithDiagnostics($tree);
        $this->assertSame($source, $result->source);
        $this->assertSame([], $result->diagnostics);
        $read = TreeEncoder::decoded(Carve::parse($source));
        $this->assertSame(self::shape([$block]), self::shape(is_array($read['children'] ?? null) ? $read['children'] : []));
    }

    /**
     * The block types and the inline text, with neighboring text nodes joined.
     *
     * @param array<mixed> $nodes
     *
     * @return string
     */
    private static function shape(array $nodes): string
    {
        $out = '';
        foreach ($nodes as $node) {
            $node = is_array($node) ? $node : [];
            $type = is_string($node['type'] ?? null) ? $node['type'] : '';
            $value = $node['value'] ?? null;
            if ($type === 'text' && is_string($value)) {
                $out .= $value;

                continue;
            }
            $kind = $node['kind'] ?? '';
            $children = is_array($node['children'] ?? null) ? $node['children'] : [];
            $out .= '<' . $type . (is_string($kind) && $kind !== '' ? ' ' . $kind : '') . '>' . self::shape($children) . '</>';
        }

        return $out;
    }
}
