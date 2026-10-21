<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Node\Block\CodeBlock;
use MarkupCarve\Carve\Node\Block\RawBlock;
use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class OwnedFenceEofSpanTest extends TestCase
{
    #[DataProvider('ownedEofLines')]
    public function testTheFenceAndItsHostsIncludeTheirOwnedEofLine(string $source, string $content, int $end): void
    {
        $document = (new BlockParser(trackPositions: true))->parse($source);
        $node = $document;
        while ($node->getChildren() !== []) {
            $node = $node->getChildren()[0];
            self::assertNotNull($node->getPos());
            self::assertSame($end, $node->getPos()->endOffset, $node->getType());
            $owned = mb_substr($source, 0, $end, 'UTF-8');
            $lastBreak = strrpos($owned, "\n");
            self::assertSame(substr_count($owned, "\n") + 1, $node->getPos()->endLine);
            self::assertSame(mb_strlen(substr($owned, $lastBreak === false ? 0 : $lastBreak + 1), 'UTF-8') + 1, $node->getPos()->endColumn);
            if ($node instanceof CodeBlock || $node instanceof RawBlock) {
                self::assertSame($content, $node->getContent());

                return;
            }
        }
        self::fail('No fenced block');
    }

    /**
     * @return iterable<string, array{string, string, int}>
     */
    public static function ownedEofLines(): iterable
    {
        foreach (['code' => '```', 'raw' => '```=html'] as $kind => $opener) {
            foreach (['root' => ['', ' '], 'quote' => ['> ', '> '], 'list' => ['- ', '  '], 'quoted list' => ['> - ', '>   ']] as $host => [$lead, $body]) {
                foreach ([false, true] as $terminated) {
                    foreach ([false, true] as $text) {
                        $source = $lead . $opener . "\n" . ($text ? $body . "x\n" : '') . $body;
                        $content = ($text ? ($host === 'root' ? ' ' : '') . "x\n" : '') . ($host === 'root' ? ' ' : '');
                        $end = strlen($source);
                        if ($terminated) {
                            $source .= "\n";
                            $content .= "\n";
                            $end++;
                        }

                        yield "$kind $host text=$text LF=$terminated" => [$source, $content, $end];
                    }
                }
            }
        }
    }

    public function testTheLastChildExtendsItsHostsWithoutMovingEarlierSiblings(): void
    {
        $source = "é\n\n- a\n- ```\n  ";
        $document = (new BlockParser(trackPositions: true))->parse($source);
        $paragraph = $document->getChildren()[0];
        $list = $document->getChildren()[1];
        self::assertSame(1, $paragraph->getPos()?->endOffset);
        self::assertSame(6, $list->getChildren()[0]->getPos()?->endOffset);
        $item = $list->getChildren()[1];
        $code = $item->getChildren()[0];
        foreach ([$document, $list, $item, $code] as $node) {
            self::assertSame(mb_strlen($source, 'UTF-8'), $node->getPos()?->endOffset);
            self::assertSame(5, $node->getPos()?->endLine);
            self::assertSame(3, $node->getPos()?->endColumn);
        }
    }

    public function testReusingAParserKeepsThePreviousTreeAndTheNewTreeSeparate(): void
    {
        $parser = new BlockParser(trackPositions: true);
        $first = $parser->parse("> ```\n> ");
        $second = $parser->parse("> a\n> ");
        self::assertSame(8, $first->getChildren()[0]->getPos()?->endOffset);
        self::assertSame(3, $second->getChildren()[0]->getPos()?->endOffset);
    }

    public function testSourceLineMappingIncludesALeadingParagraph(): void
    {
        foreach (["> ```\n> ", "> - ```=html\n>   "] as $fence) {
            $source = "é\n\n" . $fence;
            $document = (new BlockParser(trackPositions: true))->parse($source);
            $node = $document->getChildren()[1];
            while (true) {
                self::assertSame(mb_strlen($source, 'UTF-8'), $node->getPos()?->endOffset);
                self::assertSame(4, $node->getPos()?->endLine);
                if ($node instanceof CodeBlock || $node instanceof RawBlock) {
                    break;
                }
                $node = $node->getChildren()[0];
            }
            self::assertSame(1, $document->getChildren()[0]->getPos()?->endOffset);
        }
    }

    public function testAContainerEndedFenceDoesNotClaimLaterDocumentLines(): void
    {
        $document = (new BlockParser(trackPositions: true))->parse("> ```\n> \n\noutside\n");
        $quote = $document->getChildren()[0];
        self::assertSame(9, $quote->getPos()?->endOffset);
        self::assertSame(9, $quote->getChildren()[0]->getPos()?->endOffset);
        self::assertSame('paragraph', $document->getChildren()[1]->getType());
    }

    #[DataProvider('prefixShapes')]
    public function testOwnedPrefixShapes(string $source, int $end): void
    {
        $document = (new BlockParser(trackPositions: true))->parse($source);
        $quote = $document->getChildren()[0];
        $code = $quote->getChildren()[0];
        self::assertSame($end, $code->getPos()?->endOffset);
        self::assertSame($end, $quote->getPos()?->endOffset);
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function prefixShapes(): iterable
    {
        foreach (["> ```\n>", "> ```\n>\n> ", "> ```\r\n> "] as $source) {
            yield $source => [$source, strlen($source)];
        }
    }

    #[DataProvider('closedFences')]
    public function testSpacingAfterACloserStaysOutsideTheFence(string $source, int $end): void
    {
        $document = (new BlockParser(trackPositions: true))->parse($source);
        $node = $document;
        while (!($node instanceof CodeBlock) && !($node instanceof RawBlock)) {
            $node = $node->getChildren()[0];
        }
        self::assertSame($end, $node->getPos()?->endOffset);
        self::assertSame("x\n", $node->getContent());
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function closedFences(): iterable
    {
        foreach (['```', '```=html'] as $opener) {
            foreach ([['', ''], ['> ', '> '], ['- ', '  ']] as [$lead, $prefix]) {
                $source = $lead . $opener . "\n" . $prefix . "x\n" . $prefix . '```';

                yield "$opener $prefix" => [$source . "\n" . $prefix . "\n\n", strlen($source)];
            }
        }
    }
}
