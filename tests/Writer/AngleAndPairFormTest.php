<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Json\TreeEncoder;
use MarkupCarve\Carve\Json\TreeEquality;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A `<` takes the backslash only where it opens an autolink or a cross-reference (C2-I-073, C2-I-096), and emphasis or
 * strong stays bare when its mark inside a bracket or between spaces closes nothing (C2-I-053). Each expected form is
 * the one carve-js next2 writes.
 */
final class AngleAndPairFormTest extends TestCase
{
    /**
     * @return array<string, array{array<mixed>, string}>
     */
    public static function trees(): array
    {
        $t = static fn (string $value): array => ['type' => 'text', 'value' => $value];
        $em = static fn (array ...$children): array => ['type' => 'emphasis', 'children' => $children];
        $strong = static fn (array ...$children): array => ['type' => 'strong', 'children' => $children];
        $link = static fn (array ...$children): array => ['type' => 'link', 'href' => 'x', 'children' => $children];
        $sup = static fn (string $value): array => ['type' => 'superscript', 'children' => [['type' => 'text', 'value' => $value]]];

        return [
            'angle around no url' => [[$t('a <a.b/c> b')], "a <a.b/c> b\n"],
            'angle around a word' => [[$t('<ab>')], "<ab>\n"],
            'angle with no closer at the end' => [[$t('<a')], "<a\n"],
            'angle before a space' => [[$t('<a b>')], "<a b>\n"],
            'angle slash with no hash' => [[$t('</a>')], "</a>\n"],
            'angle before an arrow tail' => [[$t('<-x>')], "<-x>\n"],
            'angle around a url' => [[$t('<http://x>')], "\\<http://x>\n"],
            'angle around an email' => [[$t('<a@b.cd>')], "\\<a@b.cd>\n"],
            'angle around a cross-reference' => [[$t('</#x>')], "\\</\\#x>\n"],
            'angle starting a smart arrow' => [[$t('<--')], "\\<-\\-\n"],
            'angle closed by the next text' => [[$t('<http://x'), $t('>')], "\\<http://x>\n"],
            'angle around no url closed by the next text' => [[$t('<a.b'), $t('>')], "<a.b>\n"],
            'angle in link text with a url body' => [[$link($t('<http:a')), $t('>')], "[\\<http:a](x)>\n"],
            'angle in link text with an email body' => [[$link($t('<a@b')), $t('.c>')], "[<a@b](x).c>\n"],
            'angle in link text with a cross-reference' => [[$em($link($t('-</#'))), $t('/>')], "{/[-\\</#](x)/}/>\n"],
            'angle before code' => [[$t('<a'), ['type' => 'code', 'value' => 'x'], $t('>')], "<a`x`>\n"],
            'angle in emphasis before its closer' => [[$em($t('<a')), $t('>')], "/<a/>\n"],
            'slash in link text' => [[$em($t('e '), $link($t('a/b')), $t(' f'))], "/e [a/b](x) f/\n"],
            'star in link text' => [[$strong($t('a '), $link($t('b@c*')), $t(' d'))], "*a [b@c*](x) d*\n"],
            'slash in span text' => [
                [$em($t('e '), ['type' => 'span', 'attrs' => ['classes' => ['q']], 'children' => [$t('a/b')]], $t(' f'))],
                "/e [a/b]{.q} f/\n",
            ],
            'slash between words in a braced span' => [[$em($t('e '), $sup('a/b'), $t(' f'))], "/e {^a/b^} f/\n"],
            'slash between spaces' => [[$em($t('a / b'))], "/a / b/\n"],
            'slash between words' => [[$em($t('a/b'))], "{/a/b/}\n"],
            'run of two between words' => [[$em($t('a//b'))], "/a//b/\n"],
            'slash that can close' => [[$em($t('a/ b'))], "{/a/ b/}\n"],
            'slash that can open' => [[$em($t('a /b'))], "{/a /b/}\n"],
            'slash that can close in a braced span' => [[$em($t('e '), $sup('a/ b'), $t(' f'))], "{/e {^a/ b^} f/}\n"],
            'own kind inside a link' => [[$em($t('e '), $link($em($t('b'))), $t(' f'))], "{/e [{/b/}](x) f/}\n"],
        ];
    }

    /**
     * @param array<mixed> $nodes
     * @param string $expected
     */
    #[DataProvider('trees')]
    public function testWritesCarveJsFormThatReadsBack(array $nodes, string $expected): void
    {
        $tree = ['type' => 'document', 'astVersion' => '2.0', 'children' => [['type' => 'paragraph', 'children' => $nodes]]];

        $source = Carve::write($tree);

        $this->assertSame($expected, $source);
        $this->assertTrue(TreeEquality::equal($tree, TreeEncoder::decodedForWriter(Carve::parse($source))));
    }

    public function testKeepsTheBracedFormWhereAnEscapeSplitsARun(): void
    {
        $tree = [

            'type' => 'document',
            'astVersion' => '2.0',
            'children' => [
                [

                    'type' => 'paragraph',
                    'children' => [
                        ['type' => 'strong', 'children' => [['type' => 'text', 'value' => '{**a']]],
                    ],
                ],
            ],
        ];

        $source = Carve::write($tree);

        $this->assertSame("{*\\{*\\*a*}\n", $source);
        $this->assertTrue(TreeEquality::equal($tree, TreeEncoder::decodedForWriter(Carve::parse($source))));
    }
}
