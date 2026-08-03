<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Inline\Image;
use MarkupCarve\Carve\Node\Inline\Link;
use MarkupCarve\Carve\Node\Inline\Text;
use MarkupCarve\Carve\Node\Node;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class UnresolvedReferenceShapeTest extends TestCase
{
    /**
     * @param string $source
     * @param array<int, array<string, mixed>> $expectedNodes
     * @param string $html
     */
    #[DataProvider('oracleProvider')]
    public function testUnresolvedReferencesKeepTheirNodeShape(string $source, array $expectedNodes, string $html): void
    {
        $converter = new CarveConverter();
        $document = $converter->parse($source);
        $nodes = $this->publishedInlineNodes($document);

        $this->assertSame($html, $converter->render($document));
        $this->assertCount(count($expectedNodes), $nodes);

        foreach ($expectedNodes as $index => $expected) {
            $node = $nodes[$index];
            $this->assertSame($expected['type'], $node->getType());

            if ($node instanceof Link) {
                $this->assertSame($expected['ref'], $node->getReferenceLabel());
                $this->assertSame($expected['rawRef'], $node->getRawReferenceLabel());
                $this->assertSame('', $node->getDestination());
                $this->assertSame($expected['children'], $this->childTypes($node));
            } elseif ($node instanceof Image) {
                $this->assertSame($expected['ref'], $node->getReferenceLabel());
                $this->assertSame($expected['rawRef'], $node->getRawReferenceLabel());
                $this->assertSame('', $node->getSource());
                $this->assertSame($expected['alt'], $node->getAlt());
                $this->assertSame([], $node->getChildren());
            } elseif ($node instanceof Text) {
                $this->assertSame($expected['value'], $node->getContent());
            }

            if (isset($expected['id'])) {
                $this->assertSame($expected['id'], $node->getAttribute('id'));
            }
            if (isset($expected['class'])) {
                $this->assertSame($expected['class'], $node->getAttribute('class'));
            }
        }
    }

    /**
     * @return array<string, array{source: string, expectedNodes: array<int, array<string, mixed>>, html: string}>
     */
    public static function oracleProvider(): array
    {
        return [
            'explicit missing reference' => [
                'source' => '[missing][nope]',
                'expectedNodes' => [
                    ['type' => 'link', 'ref' => 'nope', 'rawRef' => '[missing][nope]', 'children' => ['text']],
                ],
                'html' => "<p>[missing][nope]</p>\n",
            ],
            'collapsed missing reference' => [
                'source' => '[a][]',
                'expectedNodes' => [
                    ['type' => 'link', 'ref' => 'a', 'rawRef' => '[a][]', 'children' => ['text']],
                ],
                'html' => "<p>[a][]</p>\n",
            ],
            'collapsed keeps case and spacing' => [
                'source' => '[A B][]',
                'expectedNodes' => [
                    ['type' => 'link', 'ref' => 'A B', 'rawRef' => '[A B][]', 'children' => ['text']],
                ],
                'html' => "<p>[A B][]</p>\n",
            ],
            'explicit keeps authored label' => [
                'source' => '[a][NoPe]',
                'expectedNodes' => [
                    ['type' => 'link', 'ref' => 'NoPe', 'rawRef' => '[a][NoPe]', 'children' => ['text']],
                ],
                'html' => "<p>[a][NoPe]</p>\n",
            ],
            'children stay parsed' => [
                'source' => '[*x*][nope]',
                'expectedNodes' => [
                    ['type' => 'link', 'ref' => 'nope', 'rawRef' => '[*x*][nope]', 'children' => ['strong']],
                ],
                'html' => "<p>[*x*][nope]</p>\n",
            ],
            'attributes attach to unresolved link' => [
                'source' => '[a][nope]{#i .c}',
                'expectedNodes' => [
                    ['type' => 'link', 'ref' => 'nope', 'rawRef' => '[a][nope]{#i .c}', 'children' => ['text'], 'id' => 'i', 'class' => 'c'],
                ],
                'html' => "<p>[a][nope]{#i .c}</p>\n",
            ],
            'explicit missing image' => [
                'source' => '![alt][nope]',
                'expectedNodes' => [
                    ['type' => 'image', 'ref' => 'nope', 'rawRef' => '![alt][nope]', 'alt' => 'alt'],
                ],
                // An unresolved reference image is NOT a block image: it
                // renders as its literal source, so the paragraph stays.
                'html' => "<p>![alt][nope]</p>\n",
            ],
            'collapsed missing image' => [
                'source' => '![a][]',
                'expectedNodes' => [
                    ['type' => 'image', 'ref' => 'a', 'rawRef' => '![a][]', 'alt' => 'a'],
                ],
                'html' => "<p>![a][]</p>\n",
            ],
            'trailing bracket run stays text' => [
                'source' => '[a][b][c]',
                'expectedNodes' => [
                    ['type' => 'link', 'ref' => 'b', 'rawRef' => '[a][b]', 'children' => ['text']],
                    ['type' => 'text', 'value' => '[c]'],
                ],
                'html' => "<p>[a][b][c]</p>\n",
            ],
            'literal source is html escaped' => [
                'source' => '[a&b][no<pe>]',
                'expectedNodes' => [
                    ['type' => 'link', 'ref' => 'no<pe>', 'rawRef' => '[a&b][no<pe>]', 'children' => ['text']],
                ],
                'html' => "<p>[a&amp;b][no&lt;pe&gt;]</p>\n",
            ],
        ];
    }

    /**
     * @return array<int, \MarkupCarve\Carve\Node\Node>
     */
    protected function publishedInlineNodes(Node $document): array
    {
        return $document->getChildren()[0]->getChildren();
    }

    /**
     * @return array<int, string>
     */
    protected function childTypes(Node $node): array
    {
        return array_map(
            static fn (Node $child): string => $child->getType(),
            $node->getChildren(),
        );
    }
}
