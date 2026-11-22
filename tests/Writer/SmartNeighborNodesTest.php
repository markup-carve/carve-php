<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Json\TreeEncoder;
use MarkupCarve\Carve\Writer\Writer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * C2-W-078: a smart punctuation node whose run neighbor is another node reads back as other nodes, which is reported
 * at the paths carve-js gives.
 */
final class SmartNeighborNodesTest extends TestCase
{
    /**
     * @return array<string, array{array<mixed>, string, list<string>}>
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
        $right = $smart('rightwards_arrow', "\u{2192}", '-->');
        $left = $smart('leftwards_arrow', "\u{2190}", '<--');
        $lessEqual = $smart('less_than_or_equal', "\u{2264}", '<=');
        $greaterEqual = $smart('greater_than_or_equal', "\u{2265}", '>=');
        $plusMinus = $smart('plus_minus', "\u{00B1}", '+-');
        $p = static fn (array ...$children): array => ['type' => 'paragraph', 'children' => $children];
        $structure = 'structure-unspellable /children/0/children/1';

        return [
            'an en dash before a rightwards arrow' => [
                $p($t('x'), $en, $right, $t('y')),
                "x---->y\n",
                ['field-unspellable /children/0/children/2/kind', 'field-unspellable /children/0/children/3/value'],
            ],
            'less than or equal before greater than or equal' => [
                $p($t('x'), $lessEqual, $greaterEqual, $t('y')),
                "x<=>=y\n",
                ['field-unspellable /children/0/children/1/kind', 'structure-unspellable /children/0/children/2'],
            ],
            'a leftwards arrow before greater than or equal' => [
                $p($t('x'), $left, $greaterEqual, $t('y')),
                "x<-->=y\n",
                ['field-unspellable /children/0/children/1/kind', 'structure-unspellable /children/0/children/2'],
            ],
            'an en dash before an em dash' => [
                $p($t('x'), $en, $em, $t('y')),
                "x-----y\n",
                ['field-unspellable /children/0/children/1/kind', 'field-unspellable /children/0/children/2/kind'],
            ],
            'plus-minus behind a hyphen' => [$p($t('x-'), $plusMinus, $t('y')), "x-+-y\n", [$structure]],
            'plus-minus behind a plus' => [$p($t('x+'), $plusMinus, $t('y')), "x++-y\n", [$structure]],
            'in a heading' => [
                ['type' => 'heading', 'level' => 2, 'children' => [$t('x'), $lessEqual, $greaterEqual, $t('y')]],
                "== x<=>=y\n",
                ['field-unspellable /children/0/children/1/kind', 'structure-unspellable /children/0/children/2'],
            ],
            'in emphasis' => [
                $p(['type' => 'emphasis', 'children' => [$t('x'), $en, $em, $t('y')]]),
                "/x-----y/\n",
                ['field-unspellable /children/0/children/0/children/1/kind', 'field-unspellable /children/0/children/0/children/2/kind'],
            ],
            'two en dashes read back' => [$p($t('x'), $en, $en, $t('y')), "x----y\n", []],
            'an em dash before an en dash reads back' => [$p($t('x'), $em, $en, $t('y')), "x-----y\n", []],
            'a space between two nodes' => [$p($t('x'), $lessEqual, $t(' '), $greaterEqual, $t('y')), "x<= >=y\n", []],
        ];
    }

    /**
     * @param array<mixed> $block
     * @param string $source
     * @param list<string> $losses
     *
     * @return void
     */
    #[DataProvider('cases')]
    public function testTheNeighborsAreReported(array $block, string $source, array $losses): void
    {
        $tree = ['type' => 'document', 'astVersion' => '2.0', 'children' => [$block]];
        $result = Writer::writeWithDiagnostics($tree);
        $this->assertSame($source, $result->source);
        $this->assertSame($losses, array_map(static fn ($d): string => $d->code . ' ' . $d->path, $result->diagnostics));
    }

    /**
     * @return array<string, array{string, int, int}>
     */
    public static function definedCases(): array
    {
        return [
            'an abbreviation' => ["*[HTML]: Hyper Text\n\nHTML x-----y\n", 1, 2],
            'a reference link' => ["[a][r] x-----y\n\n[r]: /u\n", 0, 2],
            'a footnote reference' => ["x[^1]-----y\n\n[^1]: n\n", 0, 2],
        ];
    }

    /**
     * A node defined elsewhere in the document reads back as itself, and the run behind it is still judged.
     *
     * @param string $source
     * @param int $block The paragraph.
     * @param int $at Its em dash, followed by an en dash.
     *
     * @return void
     */
    #[DataProvider('definedCases')]
    public function testANodeDefinedElsewhereReadsBack(string $source, int $block, int $at): void
    {
        $tree = TreeEncoder::decoded(Carve::parse($source));
        $this->assertSame([], Writer::writeWithDiagnostics($tree)->diagnostics);
        // An en dash before an em dash is written the same and reads back as an em dash and an en dash.
        $leaf = &$tree['children'][$block]['children'];
        [$leaf[$at], $leaf[$at + 1]] = [$leaf[$at + 1], $leaf[$at]];
        unset($leaf);
        $result = Writer::writeWithDiagnostics($tree);
        $path = "/children/$block/children/";
        $this->assertSame($source, $result->source);
        $paths = array_map(static fn ($d): string => $d->path, $result->diagnostics);
        $this->assertSame([$path . $at . '/kind', $path . ($at + 1) . '/kind'], $paths);
    }

    /**
     * @return array<string, array{string, list<string>}>
     */
    public static function leafCases(): array
    {
        $kinds = static fn (string $leaf, int $at): array => ["$leaf/children/$at/kind", "$leaf/children/" . ($at + 1) . '/kind'];

        return [
            'a caption' => ["![a](i.png)\n^ x-----y\n", $kinds('/children/0/caption/children/0', 1)],
            'a hard-break block' => ["::: \\\na\nx-----y\n:::\n", $kinds('/children/0/children/0', 3)],
            'a line block' => ["::: |\na\nx-----y\n:::\n", $kinds('/children/0/children/1', 1)],
            'an indented line' => ["::: |\n  x-----y\n:::\n", $kinds('/children/0/children/0', 1)],
            'a numbered caption' => ["![a](i.png)\n^ # x-----y\n", $kinds('/children/0/caption/children/0', 2)],
            'a table cell' => ["| a | x-----y |\n", $kinds('/children/0/children/0/children/0/children/1/children/0', 1)],
        ];
    }

    /**
     * Each leaf that holds inline content is judged, read back in the context it needs.
     *
     * @param string $source
     * @param list<string> $paths
     *
     * @return void
     */
    #[DataProvider('leafCases')]
    public function testARunInAnyLeafIsJudged(string $source, array $paths): void
    {
        $tree = TreeEncoder::decoded(Carve::parse($source));
        $this->assertSame([], Writer::writeWithDiagnostics($tree)->diagnostics);
        // An en dash before an em dash is written the same and reads back as an em dash and an en dash.
        self::swapDashes($tree);
        $result = Writer::writeWithDiagnostics($tree);
        $this->assertSame($source, $result->source);
        $this->assertSame($paths, array_map(static fn ($d): string => $d->path, $result->diagnostics));
    }

    /**
     * @param array<mixed> $node
     *
     * @return void
     */
    private static function swapDashes(array &$node): void
    {
        foreach ($node as &$value) {
            if (is_array($value)) {
                self::swapDashes($value);
            }
        }
        unset($value);
        $children = $node['children'] ?? null;
        if (!is_array($children) || !array_is_list($children)) {
            return;
        }
        $last = count($children) - 1;
        for ($at = 0; $at < $last; $at++) {
            if (($children[$at]['kind'] ?? '') === 'em_dash' && ($children[$at + 1]['kind'] ?? '') === 'en_dash') {
                [$children[$at], $children[$at + 1]] = [$children[$at + 1], $children[$at]];
            }
        }
        $node['children'] = $children;
    }
}
