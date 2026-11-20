<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\ProseMirror;

use InvalidArgumentException;
use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Json\TreeEquality;
use MarkupCarve\Carve\ProseMirror\Kit;
use MarkupCarve\Carve\ProseMirror\ProseMirrorRenderer;
use MarkupCarve\Carve\ProseMirror\ProseMirrorToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The `mapping` option: a host's own ProseMirror nodes and marks, both directions.
 */
final class MappingTest extends TestCase
{
    /**
     * @var array
     */
    private const MAPPING = [
        'nodes' => [
            'placeholderToken' => ['as' => 'span', 'class' => 'placeholder', 'attributes' => ['name']],
            'poll' => ['as' => 'div', 'class' => 'poll', 'attributes' => ['question', 'votes']],
        ],
        'marks' => ['textStyle' => ['as' => 'span', 'class' => 'text-style', 'attributes' => ['color']]],
    ];

    /**
     * @var string
     */
    private const SOURCE = "Dear []{.placeholder name=first}, [red]{.text-style color=#f00} and *[bold red]{.text-style color=#f00}*\n\n"
        . "{question=Lunch? votes=3}\n::: poll\n\n:::\n";

    /**
     * @return array<string, mixed>
     */
    private static function hostDoc(): array
    {
        return [

            'type' => 'doc',
            'content' => [
                [

                    'type' => 'paragraph',
                    'content' => [
                        ['type' => 'text', 'text' => 'Dear '],
                        ['type' => 'placeholderToken', 'attrs' => ['name' => 'first']],
                        ['type' => 'text', 'text' => ', '],
                        ['type' => 'text', 'text' => 'red', 'marks' => [['type' => 'textStyle', 'attrs' => ['color' => '#f00']]]],
                        ['type' => 'text', 'text' => ' and '],
                        [

                            'type' => 'text',
                            'text' => 'bold red',
                            'marks' => [
                                ['type' => 'bold'],
                                ['type' => 'textStyle', 'attrs' => ['color' => '#f00']],
                            ],
                        ],
                    ],
                ],
                ['type' => 'poll', 'attrs' => ['question' => 'Lunch?', 'votes' => 3]],
            ],
        ];
    }

    public function testADeclaredMappingSavesAndLoadsTheHostTypes(): void
    {
        $bridge = new ProseMirrorToCarve(['mapping' => self::MAPPING]);
        $this->assertSame(self::SOURCE, $bridge->convert(self::hostDoc()));
        $this->assertSame([], $bridge->ignoredAttributes());

        $loaded = (new ProseMirrorRenderer(['mapping' => self::MAPPING]))->renderSource(self::SOURCE);
        $paragraph = $loaded['content'][0]['content'];
        $this->assertSame(['type' => 'placeholderToken', 'attrs' => ['name' => 'first']], $paragraph[1]);
        $this->assertSame([['type' => 'textStyle', 'attrs' => ['color' => '#f00']]], $paragraph[3]['marks']);
        $this->assertSame('bold', $paragraph[5]['marks'][0]['type']);
        $this->assertSame(['type' => 'textStyle', 'attrs' => ['color' => '#f00']], $paragraph[5]['marks'][1]);
        // A key/value pair holds text, so a number loads back as a string.
        $this->assertSame(['type' => 'poll', 'attrs' => ['question' => 'Lunch?', 'votes' => '3']], $loaded['content'][1]);
        $this->assertSame(self::SOURCE, $bridge->convert($loaded));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function otherShapes(): array
    {
        return [
            'two classes' => ["[x]{.text-style .big color=red}\n"],
            'an id' => ["[x]{.text-style #id}\n"],
            'an undeclared key' => ["[x]{.text-style size=2}\n"],
            'an atom with text' => ["[x]{.placeholder name=a}\n"],
            'an atom with blocks' => ["::: poll\nx\n:::\n"],
        ];
    }

    #[DataProvider('otherShapes')]
    public function testASpanOfAnotherShapeStaysAKitNode(string $source): void
    {
        $json = (new ProseMirrorRenderer(['mapping' => self::MAPPING]))->renderJson(Carve::parse($source));
        $this->assertStringNotContainsString('"type":"textStyle"', $json);
        $this->assertStringNotContainsString('"type":"placeholderToken"', $json);
        $this->assertStringNotContainsString('"type":"poll"', $json);
        $back = (new ProseMirrorToCarve(['mapping' => self::MAPPING]))->toTree($json);
        $this->assertTrue(TreeEquality::equal(self::arrays(Kit::decode(Carve::encodeTree(Carve::parse($source)))), self::arrays($back)));
    }

    public function testADeclaredNodeWithContentHoldsItsBlocks(): void
    {
        $mapping = ['nodes' => ['note' => ['as' => 'div', 'class' => 'note', 'content' => true]]];
        $doc = [

            'type' => 'doc',
            'content' => [
                ['type' => 'note', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'x']]]]],
            ],
        ];
        $source = (new ProseMirrorToCarve(['mapping' => $mapping]))->convert($doc);
        $this->assertSame("{.note}\n:::\nx\n:::\n", $source);
        $this->assertSame('note', (new ProseMirrorRenderer(['mapping' => $mapping]))->renderSource($source)['content'][0]['type']);

        $this->expectExceptionMessage('the ProseMirror node "note" has content; map it with content: true');
        (new ProseMirrorToCarve(['mapping' => ['nodes' => ['note' => ['as' => 'div', 'class' => 'note']]]]))->convert($doc);
    }

    public function testAHostBlockKeepsItsPlaceOnlyWithTheCarveAttribute(): void
    {
        $source = "{.poll question=Lunch?}\n:::\n:::\n\nText.\n";
        foreach ([true => [0, 1], false => [null, 1]] as $carve => $origins) {
            $mapping = ['nodes' => ['poll' => ['as' => 'div', 'class' => 'poll', 'attributes' => ['question'], 'carve' => (bool)$carve]]];
            $doc = (new ProseMirrorRenderer(['mapping' => $mapping]))->renderSource($source);
            $this->assertSame($carve ? ['$index' => 0] : null, $doc['content'][0]['attrs']['carve'] ?? null);
            $this->assertSame($origins, (new ProseMirrorToCarve(['mapping' => $mapping]))->toTreeWithOrigins($doc)['origins']);
        }
    }

    public function testAnUndeclaredAttributeIsReported(): void
    {
        $bridge = new ProseMirrorToCarve(['mapping' => self::MAPPING]);
        $bridge->convert([

            'type' => 'doc',
            'content' => [
                ['type' => 'paragraph', 'content' => [['type' => 'placeholderToken', 'attrs' => ['name' => 'a', 'size' => 2]]]],
            ],
        ]);
        $this->assertSame(
            [['path' => '/content/0/content/0', 'type' => 'placeholderToken', 'attribute' => 'size', 'value' => 2]],
            $bridge->ignoredAttributes(),
        );
    }

    /**
     * @return array<string, array{array<string, mixed>, array<string, mixed>, string}>
     */
    public static function errors(): array
    {
        $mark = [

            'type' => 'doc',
            'content' => [
                [

                    'type' => 'paragraph',
                    'content' => [
                        ['type' => 'text', 'text' => 'x', 'marks' => [['type' => 'textStyle']]],
                    ],
                ],
            ],
        ];

        return [
            'unregistered node' => [self::hostDoc(), [], 'no Carve node for the ProseMirror inline "placeholderToken"'],
            'unregistered mark' => [$mark, [], 'no Carve node for the ProseMirror mark "textStyle"'],
            'bad node kind' => [
                self::hostDoc(),
                ['nodes' => ['poll' => ['as' => 'section', 'class' => 'x']]],
                'mapping: the node "poll" needs',
            ],
            'bad mark kind' => [$mark, ['marks' => ['textStyle' => ['as' => 'div', 'class' => 'x']]], 'mapping: the mark "textStyle"'],
            'span as a block' => [
                ['type' => 'doc', 'content' => [['type' => 'placeholderToken', 'attrs' => ['name' => 'a']]]],
                self::MAPPING,
                '"placeholderToken" is mapped to a span and cannot stand as a block',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $doc
     * @param array<string, mixed> $mapping
     * @param string $message
     */
    #[DataProvider('errors')]
    public function testAnUnmappedOrBadlyMappedTypeThrows(array $doc, array $mapping, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        (new ProseMirrorToCarve(['mapping' => $mapping]))->convert($doc);
    }

    public function testCallbacksConvertBothWays(): void
    {
        $mapping = [
            'nodes' => [
                'mention' => [
                    'toTree' => static fn (array $pm): array => [
                        'type' => 'span',
                        'attrs' => ['classes' => ['mention'], 'keyValues' => [['data-id', $pm['attrs']['id']]]],
                        'children' => [['type' => 'text', 'value' => $pm['attrs']['label']]],
                    ],
                    'fromTree' => static fn (array $node, array $helpers): ?array => $helpers['position'] === 'inline'
                        && $node['type'] === 'span' && ($node['attrs']['classes'][0] ?? null) === 'mention'
                        ? [

                            'type' => 'mention',
                            'attrs' => [
                                'id' => $node['attrs']['keyValues'][0][1],
                                'label' => $node['children'][0]['value'],
                            ],
                        ]
                        : null,
                ],
                'callout' => [
                    'toTree' => static fn (array $pm, array $helpers): array => [
                        'type' => 'div',
                        'attrs' => ['classes' => ['callout']],
                        'children' => $helpers['blocks']($pm['content']),
                    ],
                    'fromTree' => static fn (array $node, array $helpers): ?array => $helpers['position'] === 'block'
                        && $node['type'] === 'div' && ($node['attrs']['classes'][0] ?? null) === 'callout'
                        ? ['type' => 'callout', 'content' => $helpers['blocks']($node['children'])]
                        : null,
                ],
            ],
            'marks' => [
                'marker' => [
                    'toTree' => static fn (array $mark): array => ['type' => 'highlight'],
                    'fromTree' => static fn (array $node): ?array => $node['type'] === 'highlight' ? ['type' => 'marker'] : null,
                ],
            ],
        ];
        $doc = [

            'type' => 'doc',
            'content' => [
                [

                    'type' => 'paragraph',
                    'content' => [
                        ['type' => 'text', 'text' => 'Hi '],
                        ['type' => 'mention', 'attrs' => ['id' => '7', 'label' => 'Ann']],
                        ['type' => 'text', 'text' => ' '],
                        ['type' => 'text', 'text' => 'hot', 'marks' => [['type' => 'marker']]],
                    ],
                ],
                ['type' => 'callout', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Note']]]]],
            ],
        ];
        $source = "Hi [Ann]{.mention data-id=7} {=hot=}\n\n::: callout\nNote\n:::\n";
        $this->assertSame($source, (new ProseMirrorToCarve(['mapping' => $mapping]))->convert($doc));
        $loaded = (new ProseMirrorRenderer(['mapping' => $mapping]))->renderSource($source);
        $this->assertSame(['type' => 'mention', 'attrs' => ['id' => '7', 'label' => 'Ann']], $loaded['content'][0]['content'][1]);
        $this->assertSame([['type' => 'marker']], $loaded['content'][0]['content'][3]['marks']);
        $this->assertSame('callout', $loaded['content'][1]['type']);
        $this->assertSame($source, (new ProseMirrorToCarve(['mapping' => $mapping]))->convert($loaded));
    }

    private static function arrays(mixed $value): mixed
    {
        return json_decode(Kit::encode(['v' => $value]), true)['v'];
    }
}
