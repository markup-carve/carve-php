<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\CodeBlock;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Node\Node;
use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AttachedFenceStructuralBlankTest extends TestCase
{
    /**
     * Payload cases at host boundaries.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function cases(): iterable
    {
        yield 'ordered item' => ["> a\n+\n1. ```\n   x\n+\ny\n", "x\n"];
        yield 'nested item' => ["> a\n+\n- b\n  - ~~~\n    x\n+\n- y\n", "x\n"];
        yield 'empty item' => ["> a\n+\n- ``` js\n+\n- y\n", ''];
        yield 'item with code' => ["> a\n+\n- ``` js\n  x\n+\n- y\n", "x\n"];
        yield 'description item' => ["> :: t\n+\n: - ``` js\n> q\n", ''];
        yield 'authored blank' => ["- ```\n  x\n  \n- y\n", "x\n\n"];
    }

    #[DataProvider('cases')]
    public function testFencePayloadKeepsOnlyAuthoredLines(string $source, string $expected): void
    {
        $document = CarveConverter::create()->parse($source);
        $contents = [];
        $visit = static function (Node $node) use (&$visit, &$contents): void {
            if ($node instanceof CodeBlock) {
                $contents[] = $node->getContent();
            }
            foreach ($node->getChildren() as $child) {
                $visit($child);
            }
        };
        $visit($document);
        self::assertSame([$expected], $contents);
    }

    public function testSourceLessBlockContentKeepsItsBlankPayloadLine(): void
    {
        $document = new Document();
        (new BlockParser())->parseBlockContent($document, ['```', 'a', '', 'b', '```']);
        $code = $document->getChildren()[0];
        self::assertInstanceOf(CodeBlock::class, $code);
        self::assertSame("a\n\nb\n", $code->getContent());
    }
}
