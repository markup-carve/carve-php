<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Json;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Json\TreeEncoder;
use MarkupCarve\Carve\Node;
use PHPUnit\Framework\TestCase;

final class AbbreviationExpansionBudgetTest extends TestCase
{
    public function testParsedTreeOmitsCopiesBeyondTheBudget(): void
    {
        $source = '*[A]: ' . str_repeat('x', 100000) . "\n\n" . str_repeat('A ', 12);
        $document = Carve::parse($source);
        self::assertNotNull($document->children);
        $paragraph = $document->children[1];
        self::assertNotNull($paragraph->children);
        $uses = array_values(array_filter(
            $paragraph->children,
            static fn (Node $node): bool => $node->type === 'abbreviation',
        ));
        self::assertCount(12, $uses);
        foreach ($uses as $index => $use) {
            self::assertSame($index < 10, isset($use->f['expansion']));
        }
        self::assertSame(Carve::toHtml($source), Carve::render($document));
    }

    public function testEncoderBudgetsExtensionChildrenOutsideTheResolutionView(): void
    {
        $abbreviation = static function (): Node {
            $node = new Node('abbreviation');
            $node->f = ['key' => 'A', 'namespace' => 'abbr', 'expansion' => str_repeat('x', 600000)];

            return $node;
        };
        $extension = new Node('inline_extension', [$abbreviation(), $abbreviation()]);
        $extension->f['fallback'] = [];
        $document = new Node('document', [$extension]);
        $document->f['astVersion'] = '2.0';
        $encoded = TreeEncoder::decoded($document);
        self::assertIsArray($encoded['children'] ?? null);
        $encodedExtension = $encoded['children'][0] ?? null;
        self::assertIsArray($encodedExtension);
        $uses = $encodedExtension['children'] ?? null;
        self::assertIsArray($uses);
        self::assertIsArray($uses[0] ?? null);
        self::assertIsArray($uses[1] ?? null);
        self::assertArrayHasKey('expansion', $uses[0]);
        self::assertArrayNotHasKey('expansion', $uses[1]);
    }

    public function testEncoderChargesRepeatedReferencesToOneNode(): void
    {
        $abbreviation = new Node('abbreviation');
        $abbreviation->f = ['key' => 'A', 'namespace' => 'abbr', 'expansion' => str_repeat('x', 600000)];
        $document = new Node('document', [$abbreviation, $abbreviation]);
        $document->f['astVersion'] = '2.0';
        $encoded = TreeEncoder::decoded($document);
        self::assertIsArray($encoded['children'] ?? null);
        self::assertIsArray($encoded['children'][0] ?? null);
        self::assertIsArray($encoded['children'][1] ?? null);
        self::assertArrayHasKey('expansion', $encoded['children'][0]);
        self::assertArrayNotHasKey('expansion', $encoded['children'][1]);
    }

    public function testEncoderKeepsBodyBeforeCaptionForASharedNode(): void
    {
        $abbreviation = new Node('abbreviation');
        $abbreviation->f = ['key' => 'A', 'namespace' => 'abbr', 'expansion' => str_repeat('x', 600000)];
        $paragraph = new Node('paragraph', [$abbreviation]);
        $paragraph->f['caption'] = $abbreviation;
        $document = new Node('document', [$paragraph]);
        $document->f['astVersion'] = '2.0';
        $encoded = TreeEncoder::decoded($document);
        self::assertIsArray($encoded['children'] ?? null);
        $encodedParagraph = $encoded['children'][0] ?? null;
        self::assertIsArray($encodedParagraph);
        self::assertIsArray($encodedParagraph['children'] ?? null);
        self::assertIsArray($encodedParagraph['children'][0] ?? null);
        self::assertIsArray($encodedParagraph['caption'] ?? null);
        self::assertArrayHasKey('expansion', $encodedParagraph['children'][0]);
        self::assertArrayNotHasKey('expansion', $encodedParagraph['caption']);
    }

    public function testRepeatedExpansionKeepsTreeSizeLinearInSource(): void
    {
        foreach ([8, 16, 32] as $kilobytes) {
            $uses = $kilobytes * 256;
            $source = '*[A]: ' . str_repeat('x', $uses * 2) . "\n\n" . str_repeat('A ', $uses);
            $json = Carve::toJson($source);
            self::assertLessThanOrEqual(
                1000000 + 100 * strlen($source),
                strlen($json),
                "Tree JSON grew too quickly at {$kilobytes} KB",
            );
        }
    }
}
