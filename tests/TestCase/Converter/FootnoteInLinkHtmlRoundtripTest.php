<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use LogicException;
use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FootnoteInLinkHtmlRoundtripTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function documents(): iterable
    {
        $corpus = dirname(__DIR__, 2) . '/spec/tests/corpus/';
        $documents = [];
        foreach (['310-*', '311-*', '312-*-3', '573-*'] as $pattern) {
            foreach (glob($corpus . $pattern . '.crv') ?: [] as $path) {
                $documents[basename($path)] = [(string)file_get_contents($path)];
            }
        }

        if (count($documents) !== 8) {
            throw new LogicException('Expected the eight pinned footnote-in-link corpus documents');
        }
        yield from $documents;
        yield 'reference outside the link' => ["a [t](/u)[^1] b\n\n[^1]: n\n"];
    }

    #[DataProvider('documents')]
    public function testFootnotesKeepTheirLinkOwner(string $source): void
    {
        $codec = new AstCodec();
        foreach ([false, true] as $roundtrip) {
            $converter = new CarveConverter(roundTripMode: $roundtrip);
            $expected = self::owners($codec->encode($converter->parse($source)));
            $html = $converter->convert($source);
            foreach (['safe', 'semantic', 'roundtrip'] as $mode) {
                $importer = new HtmlToCarve(importMode: $mode);
                self::assertSame($expected, self::owners($importer->convertToAst($html)), $mode . ' AST');
                $back = $codec->encode($converter->parse($importer->convert($html)));
                self::assertSame($expected, self::owners($back), $mode . ' source');
            }
        }
    }

    public function testReferenceLabelsKeepTheirNodesAndAttributes(): void
    {
        $codec = new AstCodec();
        foreach (['t' => 'text', '<em>t</em>' => 'emphasis'] as $label => $kind) {
            $html = '<p><a href="/u" data-carve-ref="r" class="x">' . $label . '</a></p>';
            foreach (['safe', 'semantic', 'roundtrip'] as $mode) {
                $importer = new HtmlToCarve(importMode: $mode);
                $tree = $importer->convertToAst($html);
                $link = $tree['children'][0]['children'][0];
                self::assertSame('r', $link['ref']);
                self::assertArrayNotHasKey('rawRef', $link);
                self::assertSame($kind, $link['children'][0]['type']);
                $back = $codec->encode((new CarveConverter())->parse($importer->convert($html)));
                $link = $back['children'][0]['children'][0];
                self::assertSame('r', $link['ref']);
                self::assertSame($kind, $link['children'][0]['type']);
                self::assertSame(['x'], $link['attrs']['classes']);
            }
        }
    }

    /**
     * @param array<string|int, mixed> $tree
     *
     * @return list<bool>
     */
    private static function owners(array $tree): array
    {
        $owners = [];
        $visit = static function (array $node, bool $inLink = false) use (&$visit, &$owners): void {
            $inLink = $inLink || ($node['type'] ?? null) === 'link';
            if (in_array($node['type'] ?? null, ['footnote_ref', 'inline_footnote'], true)) {
                $owners[] = $inLink;
            }
            foreach ($node as $value) {
                if (is_array($value)) {
                    $visit($value, $inLink);
                }
            }
        };
        $visit($tree);

        return $owners;
    }
}
