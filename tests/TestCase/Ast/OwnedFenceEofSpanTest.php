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
                        }

                        yield "$kind $host text=$text LF=$terminated" => [$source, $content, $end];
                    }
                }
            }
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
            foreach (['', '> '] as $prefix) {
                $source = $prefix . $opener . "\n" . $prefix . "x\n" . $prefix . '```';

                yield "$opener $prefix" => [$source . "\n" . $prefix . "\n\n", strlen($source)];
            }
        }
    }
}
