<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Json\TreeEncoder;
use MarkupCarve\Carve\Writer\Writer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * C2-W-090, C2-W-099: a hyphen run is escaped as one run of the source line, whatever text node each hyphen comes from.
 */
final class HyphenRunAcrossTextNodesTest extends TestCase
{
    /**
     * @return array<string, array{list<array<string, string>>, string}>
     */
    public static function cases(): array
    {
        $t = static fn (string $v): array => ['type' => 'text', 'value' => $v];
        $em = ['type' => 'smart_punctuation', 'kind' => 'em_dash', 'glyph' => "\u{2014}", 'source' => '---'];
        $en = ['type' => 'smart_punctuation', 'kind' => 'en_dash', 'glyph' => "\u{2013}", 'source' => '--'];

        return [
            'a run ending one node and starting the next' => [[$t('a -'), $t('-:')], "a -\\-:\n"],
            'two single hyphens' => [[$t('-'), $t('-')], "-\\-\n"],
            'one and two hyphens' => [[$t('-'), $t('--')], "-\\--\n"],
            'four single hyphens' => [[$t('-'), $t('-'), $t('-'), $t('-')], "-\\--\\-\n"],
            'two and three hyphens' => [[$t('x--'), $t('--y')], "x-\\--\\-y\n"],
            'a split run before an em dash' => [[$t('-'), $t('--'), $em, $t('-a')], "-\\-\\----\\-a\n"],
            'a split run after an en dash' => [[$t('x'), $en, $t('--'), $t('---a')], "x--\\--\\--\\-a\n"],
            'a run between two en dashes' => [[$t('x'), $en, $t('--'), $en], "x--\\-\\---\n"],
            'three hyphens opening the line' => [[$t('---')], "-\\--\n"],
            'four hyphens opening the line' => [[$t('----x')], "-\\--\\-x\n"],
        ];
    }

    /**
     * @param list<array<string, string>> $children
     * @param string $source
     *
     * @return void
     */
    #[DataProvider('cases')]
    public function testTheRunIsEscapedAsOneRun(array $children, string $source): void
    {
        $tree = ['type' => 'document', 'astVersion' => '2.0', 'children' => [['type' => 'paragraph', 'children' => $children]]];
        $result = Writer::writeWithDiagnostics($tree);
        $this->assertSame($source, $result->source);
        $this->assertSame([], $result->diagnostics);
        $read = TreeEncoder::decoded(Carve::parse($source));
        $paragraph = is_array($read['children'] ?? null) && is_array($read['children'][0] ?? null) ? $read['children'][0] : [];
        $text = self::flat(is_array($paragraph['children'] ?? null) ? $paragraph['children'] : []);
        $expected = self::flat($children);
        $this->assertSame($expected, $text);
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
