<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Extension\ExtensionDefinition;
use MarkupCarve\Carve\Json\TreeEquality;
use MarkupCarve\Carve\Writer\Writer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * C2-W-002, C2-W-117: a supplied tree is read back, so a write that reads as another tree reports a loss.
 */
final class SuppliedTreeReadBackTest extends TestCase
{
    /**
     * @return array<string, array{array<mixed>, string}>
     */
    public static function lossyTrees(): array
    {
        $definition = ['type' => 'link_reference_definition', 'key' => 'b', 'namespace' => 'link', 'href' => '/u'];
        $link = [
            'type' => 'link',
            'refForm' => 'shortcut',
            'key' => 'b',
            'namespace' => 'link',
            'href' => '/u',
            'children' => [['type' => 'text', 'value' => 'a']],
        ];

        return [
            'shortcut link whose text is not its key' => [
                self::document([['type' => 'paragraph', 'children' => [$link]], $definition]),
                "[a]\n\n[b]: /u\n",
            ],
            'shortcut link without punctuation in its strings' => [
                self::document([
                    ['type' => 'paragraph', 'children' => [['href' => 'u'] + $link]],
                    ['href' => 'u'] + $definition,
                ]),
                "[a]\n\n[b]: u\n",
            ],
            'shortcut link before a bracket a line comment closes' => [
                self::document([
                    [

                        'type' => 'paragraph',
                        'children' => [
                            $link,
                            ['type' => 'text', 'value' => '[c'],
                            ['type' => 'comment', 'form' => 'line', 'value' => 'd]'],
                        ],
                    ],
                    $definition,
                ]),
                "[a]\\[c %% d]\n\n[b]: /u\n",
            ],
            'blank before a line comment' => [
                self::document([
                    [

                        'type' => 'paragraph',
                        'children' => [
                            ['type' => 'text', 'value' => 'a [b '],
                            ['type' => 'comment', 'form' => 'line', 'value' => 'c]'],
                        ],
                    ],
                ]),
                "a \\[b  %% c]\n",
            ],
        ];
    }

    /**
     * @param array<mixed> $tree
     * @param string $expected
     */
    #[DataProvider('lossyTrees')]
    public function testAWriteThatReadsAsAnotherTreeReportsALoss(array $tree, string $expected): void
    {
        $result = Writer::writeWithDiagnostics($tree);
        $this->assertSame($expected, $result->source);
        $this->assertFalse(TreeEquality::equal($tree, self::read($result->source)));
        $this->assertNotSame([], $result->codes());
    }

    public function testATreeWithARequiredExtensionTheWriteImplementsStillReportsALoss(): void
    {
        $tree = self::lossyTrees()['shortcut link whose text is not its key'][0];
        $tree['extensions'] = [['name' => 'org.example.x', 'version' => '1', 'required' => true, 'slots' => []]];
        $result = Writer::writeWithDiagnostics($tree, null, ['extensions' => [new ExtensionDefinition('org.example.x', '1')]]);

        $this->assertNotSame([], $result->codes());
    }

    public function testATreeThatReadsBackReportsNothing(): void
    {
        $tree = self::document([
            [

                'type' => 'paragraph',
                'children' => [
                    [

                        'type' => 'link',
                        'refForm' => 'shortcut',
                        'key' => 'a',
                        'namespace' => 'link',
                        'href' => '/u',
                        'children' => [['type' => 'text', 'value' => 'a']],
                    ],
                    ['type' => 'text', 'value' => ' b, c.'],
                ],
            ],
            ['type' => 'link_reference_definition', 'key' => 'a', 'namespace' => 'link', 'href' => '/u'],
        ]);
        $result = Writer::writeWithDiagnostics($tree);
        $this->assertSame("[a] b, c.\n\n[a]: /u\n", $result->source);
        $this->assertSame([], $result->codes());
        $this->assertTrue(TreeEquality::equal($tree, self::read($result->source)));
    }

    /**
     * @param array<mixed> $children
     *
     * @return array<mixed>
     */
    private static function document(array $children): array
    {
        return ['type' => 'document', 'astVersion' => '2.0', 'children' => $children];
    }

    /**
     * @return array<mixed>
     */
    private static function read(string $source): array
    {
        $tree = json_decode(Carve::toJson($source), true);
        self::assertIsArray($tree);

        return $tree;
    }
}
