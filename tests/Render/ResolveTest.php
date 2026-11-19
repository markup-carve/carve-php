<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Render;

use MarkupCarve\Carve\Json\TreeEncoder;
use MarkupCarve\Carve\Node;
use MarkupCarve\Carve\Options;
use MarkupCarve\Carve\Resolve\Resolver;
use MarkupCarve\Carve\Resolve\Slug;

/**
 * The resolve stage: ids, slugs, numbers and destinations (C2-A-133, C2-H-022 to C2-H-025, C2-H-087,
 * C2-H-143 to C2-H-151, C2-I-081 to C2-I-084).
 */
final class ResolveTest extends RenderTestCase
{
    /**
     * @param array<string, mixed> $tree
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private static function resolved(array $tree, array $options = []): array
    {
        $doc = self::node($tree);
        Resolver::resolve($doc, null, Options::from($options));

        return (new TreeEncoder())->encode($doc, null);
    }

    /**
     * @param string $text
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private static function h(string $text, array $extra = []): array
    {
        return ['type' => 'heading', 'level' => 1, 'children' => [self::t($text)]] + $extra;
    }

    /**
     * @return void
     */
    public function testC2A008RootFields(): void
    {
        $doc = self::node(['type' => 'document', 'children' => []]);
        Resolver::resolve($doc, 'abc', Options::from([]));
        $this->assertSame(['astVersion' => '2.0', 'srcByteLength' => 3, 'stages' => Resolver::STAGES], $doc->f);
    }

    /**
     * @return void
     */
    public function testC2H024AuthoredIdsReservedFirst(): void
    {
        $out = self::resolved(self::doc(self::h('Dup'), self::h('Dup'), self::h('Other', ['attrs' => ['id' => 'Dup-2']])));
        $this->assertSame('Dup', $out['children'][0]['generatedId']);
        $this->assertSame('Dup-3', $out['children'][1]['generatedId']);
        $this->assertArrayNotHasKey('generatedId', $out['children'][2]);
    }

    /**
     * @return void
     */
    public function testC2H025NoteHeadingsAfterBody(): void
    {
        $tree = self::doc(
            self::p(['type' => 'footnote_ref', 'key' => 'n', 'namespace' => 'footnote']),
            ['type' => 'footnote', 'key' => 'n', 'namespace' => 'footnote', 'children' => [self::h('X')]],
            ['type' => 'footnote', 'key' => 'u', 'namespace' => 'footnote', 'children' => [self::h('X')]],
            self::h('X'),
        );
        $out = self::resolved($tree);
        $this->assertSame('X-2', $out['children'][1]['children'][0]['generatedId']);
        $this->assertArrayNotHasKey('generatedId', $out['children'][2]['children'][0]);
        $this->assertSame('X', $out['children'][3]['generatedId']);
    }

    /**
     * @return void
     */
    public function testC2H131HeadingIdsOff(): void
    {
        $out = self::resolved(self::doc(self::h('A')), ['headingIds' => 'off']);
        $this->assertArrayNotHasKey('generatedId', $out['children'][0]);
    }

    /**
     * @return void
     */
    public function testC2H145ToC2H148Slugs(): void
    {
        $this->assertSame('Hello-World', Slug::of('Hello, World!', 'keep-case'));
        $this->assertSame('Don-t-stop-go', Slug::of("Don\u{2019}t stop \u{2013} go", 'keep-case'));
        $this->assertSame('Café-日本語', Slug::of('Cafe' . "\u{0301}" . ' 日本語', 'keep-case'));
        $this->assertSame('s-2nd-try', Slug::of('2nd try', 'keep-case'));
        $this->assertSame('s-٣', Slug::of('٣', 'keep-case'));
        $this->assertSame('s', Slug::of('***', 'keep-case'));
        $this->assertSame('zerowidth', Slug::of("zero\u{200B}width", 'keep-case'));
    }

    /**
     * @return void
     */
    public function testC2H147LowerPerCharacter(): void
    {
        $this->assertSame('ärger-mit-σασ', Slug::of('Ärger mit ΣΑΣ', 'lower'));
        $this->assertSame("i\u{0307}stanbul-straße", Slug::of('İstanbul Straße', 'lower'));
        $this->assertSame('naive-cafe', Slug::of('naïve café', 'ascii'));
    }

    /**
     * @return void
     */
    public function testC2H087FootnoteNumbering(): void
    {
        $ref = static fn (string $k): array => ['type' => 'footnote_ref', 'key' => $k, 'namespace' => 'footnote'];
        $def = static fn (string $k): array => [

            'type' => 'footnote',
            'key' => $k,
            'namespace' => 'footnote',
            'children'
            => [self::p(self::t($k))],
        ];
        $tree = self::doc(self::p(
            ['type' => 'inline_footnote', 'children' => [$ref('n')]],
            $ref('m'),
            $ref('zz'),
            $ref('m'),
        ), $def('n'), $def('m'));
        $out = self::resolved($tree)['children'][0]['children'];
        $this->assertSame(1, $out[0]['number']);
        $this->assertSame(3, $out[0]['children'][0]['number']);
        $this->assertSame(2, $out[1]['number']);
        $this->assertArrayNotHasKey('number', $out[2]);
        $this->assertSame(2, $out[3]['number']);
    }

    /**
     * @return void
     */
    public function testC2I081DefinitionsBeforeHeadings(): void
    {
        $link = static fn (string $k, string $form = 'collapsed'): array => [

            'type' => 'link',
            'key' => $k,
            'namespace'
            => 'link', 'refForm' => $form,
            'children' => [self::t($k)],
        ];
        $tree = self::doc(
            self::h('Intro'),
            ['type' => 'heading', 'level' => 1, 'children' => [self::t('A '), ['type' => 'emphasis', 'children' => [self::t('b')]]]],
            self::p($link('Intro'), $link('A b'), $link('nope'), [

                'type' => 'image',
                'key' => 'A b',
                'namespace' => 'link',
                'refForm' => 'full',
                'alt' => 'i',
            ]),
            ['type' => 'link_reference_definition', 'key' => 'Intro', 'namespace' => 'link', 'href' => '/def'],
        );
        $out = self::resolved($tree)['children'][2]['children'];
        $this->assertSame('/def', $out[0]['href']);
        $this->assertSame('#A-b', $out[1]['href']);
        $this->assertArrayNotHasKey('href', $out[2]);
        $this->assertArrayNotHasKey('src', $out[3]);
    }

    /**
     * @return void
     */
    public function testC2H095CrossReferenceTargets(): void
    {
        $xref = static fn (string $k): array => ['type' => 'xref', 'key' => $k, 'namespace' => 'heading'];
        $caption = ['type' => 'caption', 'children' => [self::p(self::t('Figure '), ['type' => 'caption_number'])]];
        $tree = self::doc(
            self::h('H'),
            ['type' => 'code_block', 'value' => '', 'attrs' => ['id' => 'f'], 'caption' => $caption],
            ['type' => 'div', 'attrs' => ['id' => 'd'], 'children' => []],
            self::p($xref('H'), $xref('f'), $xref('d')),
        );
        $out = self::resolved($tree);
        $this->assertSame(1, $out['children'][1]['caption']['children'][0]['children'][1]['n']);
        $xrefs = $out['children'][3]['children'];
        $this->assertSame('#H', $xrefs[0]['href']);
        $this->assertSame('#f', $xrefs[1]['href']);
        $this->assertArrayNotHasKey('href', $xrefs[2]);
        $this->assertSame('<a href="#f">Figure 1</a>', substr(self::html($tree), (int)strpos(self::html($tree), '<a href="#f">'), 25));
    }

    /**
     * @return void
     */
    public function testC2H149SequencesByLabelAndC2H066PanelsTakeNone(): void
    {
        $fig = static fn (string $label): array => [
            'type' => 'code_block',
            'value' => '',
            'caption' => ['type' => 'caption', 'children' => [self::p(self::t($label), ['type' => 'caption_number'])]],
        ];
        $group = [
            'type' => 'figure_group',
            'children' => [$fig('Figure ')], 'caption' => [

                'type' => 'caption',
                'children'
            => [self::p(self::t('Figure '), ['type' => 'caption_number'])],
            ],
        ];
        $out = self::resolved(self::doc($fig('Figure '), $fig('Fig. '), $fig('Figure '), $group));
        $n = static fn (array $f): mixed => $f['caption']['children'][0]['children'][1]['n'] ?? null;
        $this->assertSame([1, 1, 2], [$n($out['children'][0]), $n($out['children'][1]), $n($out['children'][2])]);
        $this->assertSame(3, $n($out['children'][3]));
        $this->assertNull($n($out['children'][3]['children'][0]));
    }

    /**
     * @return void
     */
    public function testC2B172SpanCountsAndC2A089ResolvedAlign(): void
    {
        $cell = static fn (array $extra = []): array => ['type' => 'table_cell', 'header' => false, 'children' => []] + $extra;
        $table = [

            'type' => 'table',
            'columns' => [
                [
                    'type' => 'table_column',
                    'align' => 'left',
                ], ['type' => 'table_column'],
            ],
            'children' => [
                [

                    'type' => 'table_head',
                    'children' => [
                        [

                            'type' => 'table_row',
                            'children' => [
                                $cell(['header' => true]),
                                $cell(['header' => true, 'align' => 'right']),
                            ],
                        ],
                    ],
                ],
                ['type' => 'table_body', 'children' => [['type' => 'table_row', 'children' => [$cell(), $cell(['span' => 'colspan'])]]]],
            ],
        ];
        $out = self::resolved(self::doc($table))['children'][0];
        $this->assertSame('left', $out['columns'][0]['resolvedAlign']);
        $this->assertSame('right', $out['columns'][1]['resolvedAlign']);
        $this->assertSame(2, $out['children'][1]['children'][0]['children'][0]['colspan']);
    }

    /**
     * @return void
     */
    public function testC2A133ResolvingTwiceGivesTheSameTree(): void
    {
        $tree = self::doc(self::h('A'), self::h('A'), self::p([

            'type' => 'footnote_ref',
            'key' => 'n',
            'namespace'
            => 'footnote',
        ]), ['type' => 'footnote', 'key' => 'n', 'namespace' => 'footnote', 'children' => []]);
        $doc = self::node($tree);
        Resolver::resolve($doc, null, Options::from([]));
        $once = (new TreeEncoder())->encode($doc, null);
        Resolver::resolve($doc, null, Options::from([]));
        $this->assertSame($once, (new TreeEncoder())->encode($doc, null));
        $this->assertInstanceOf(Node::class, $doc);
    }
}
