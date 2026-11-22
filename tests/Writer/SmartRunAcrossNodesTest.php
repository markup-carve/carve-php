<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Json\TreeEncoder;
use MarkupCarve\Carve\Writer\Writer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * C2-W-095, C2-I-114: a run smart typography replaces is escaped whatever inline nodes its characters come from.
 */
final class SmartRunAcrossNodesTest extends TestCase
{
    /**
     * @return array<string, array{list<array<string, string>>, string}>
     */
    public static function cases(): array
    {
        $t = static fn (string $v): array => ['type' => 'text', 'value' => $v];
        $smart = static fn (string $kind, string $glyph, string $source): array => [
            'type' => 'smart_punctuation',
            'kind' => $kind,
            'glyph' => $glyph,
            'source' => $source,
        ];
        $en = $smart('en_dash', "\u{2013}", '--');
        $em = $smart('em_dash', "\u{2014}", '---');
        $left = $smart('leftwards_arrow', "\u{2190}", '<--');
        $lessEqual = $smart('less_than_or_equal', "\u{2264}", '<=');
        $ellipsis = $smart('ellipsis', "\u{2026}", '...');
        $greaterEqual = $smart('greater_than_or_equal', "\u{2265}", '>=');

        return [
            'a trademark over two text nodes' => [[$t('x(t'), $t('m)y')], "x\\(tm)y\n"],
            'a trademark over three text nodes' => [[$t('x('), $t('t'), $t('m)y')], "x\\(tm)y\n"],
            'an ellipsis over two text nodes' => [[$t('x.'), $t('..y')], "x\\...y\n"],
            'an ellipsis over three text nodes' => [[$t('x.'), $t('.'), $t('.y')], "x\\...y\n"],
            'dots before an ellipsis node' => [[$t('x..'), $ellipsis, $t('y')], "x\\.\\....y\n"],
            'a dot after an ellipsis node' => [[$t('x'), $ellipsis, $t('.y')], "x....y\n"],
            'a long dot run over two text nodes' => [
                [$t('x' . str_repeat('.', 8)), $t(str_repeat('.', 8) . 'y')],
                'x\\...\\...\\...\\...\\.\\...' . "y\n",
            ],
            'a double arrow over two text nodes' => [[$t('x=='), $t('>y')], "x\\==>y\n"],
            'a double arrow over three text nodes' => [[$t('x='), $t('='), $t('>y')], "x\\==>y\n"],
            'a double arrow into a node' => [[$t('x=='), $greaterEqual, $t('y')], "x\\==>=y\n"],
            'an en dash before a closing angle' => [[$t('x'), $en, $t('>y')], "x--\\>y\n"],
            'a leftwards arrow before a closing angle' => [[$t('x'), $left, $t('>y')], "x<--\\>y\n"],
            'a less than or equal before a closing angle' => [[$t('x'), $lessEqual, $t('>y')], "x<=\\>y\n"],
            'a less than or equal before an equals sign' => [[$t('x'), $lessEqual, $t('=y')], "x<=\\=y\n"],
            'an em dash before a closing angle' => [[$t('x'), $em, $t('>y')], "x--->y\n"],
            'a run inside a node spelling stays' => [[$t('x'), $smart('rightwards_arrow', "\u{2192}", '-->'), $t('=y')], "x-->=y\n"],
        ];
    }

    /**
     * @param list<array<string, string>> $children
     * @param string $source
     *
     * @return void
     */
    #[DataProvider('cases')]
    public function testTheRunIsEscaped(array $children, string $source): void
    {
        $tree = ['type' => 'document', 'astVersion' => '2.0', 'children' => [['type' => 'paragraph', 'children' => $children]]];
        $result = Writer::writeWithDiagnostics($tree);
        $this->assertSame($source, $result->source);
        $this->assertSame([], $result->diagnostics);
        $read = TreeEncoder::decoded(Carve::parse($source));
        $paragraph = is_array($read['children'] ?? null) && is_array($read['children'][0] ?? null) ? $read['children'][0] : [];
        $this->assertSame(self::flat($children), self::flat(is_array($paragraph['children'] ?? null) ? $paragraph['children'] : []));
    }

    /**
     * @param array<mixed> $nodes
     *
     * @return string
     */
    private static function flat(array $nodes): string
    {
        $out = '';
        foreach ($nodes as $node) {
            $node = is_array($node) ? $node : [];
            $value = $node['value'] ?? '';
            $kind = $node['kind'] ?? '';
            $out .= ($node['type'] ?? '') === 'text' && is_string($value) ? $value : '[' . (is_string($kind) ? $kind : '') . ']';
        }

        return $out;
    }
}
