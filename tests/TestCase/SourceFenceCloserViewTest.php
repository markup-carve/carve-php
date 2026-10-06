<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\Parser\BlockContinuationScanner;
use MarkupCarve\Carve\Parser\BlockParser;
use MarkupCarve\Carve\Parser\CollectedFenceView;
use MarkupCarve\Carve\Parser\DefinitionListBuilder;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class SourceFenceCloserViewTest extends TestCase
{
    public function testIndexedAnswersMatchTheCompleteScan(): void
    {
        $parser = new BlockParser();
        /** @var \MarkupCarve\Carve\Parser\BlockContinuationScanner $scanner */
        $scanner = (new ReflectionMethod($parser, 'continuationsMapper'))->invoke($parser);
        $this->assertInstanceOf(BlockContinuationScanner::class, $scanner);
        $lines = ['text', '```', '  ~~~~~', "\t```", ' ```', '    ~~~', '  ````', '```x', '  ```', '~~~~'];
        foreach (range(0, count($lines)) as $index) {
            foreach ([0, 1, 2, 3, 4, 8] as $column) {
                foreach (['`', '~'] as $char) {
                    foreach ([3, 4, 5, 8] as $length) {
                        $opener = ['char' => $char, 'fence' => str_repeat($char, $length), 'length' => $length];
                        $this->assertSame(
                            $scanner->hasFenceCloserInView($lines, $index, $opener, $column),
                            $scanner->sourceFenceCloserInView($lines, $index, $opener, $column),
                        );
                    }
                }
            }
        }
    }

    public function testCollectedCloserRechecksAnExtendedTail(): void
    {
        $parser = new BlockParser();
        $builder = (new ReflectionMethod($parser, 'definitionsBuilder'))->invoke($parser);
        $method = new ReflectionMethod($builder, 'descriptionBodyLeadFenceStaysOpen');
        $scan = new CollectedFenceView();
        $body = ['- ```', '  ```'];
        $this->assertFalse($method->invoke($builder, $body, $scan));
        $body[1] .= "\ntext";
        $this->assertTrue($method->invoke($builder, $body, $scan));
        $body[] = '  body';
        $this->assertTrue($method->invoke($builder, $body, $scan));
        $body[] = '  ```';
        $this->assertFalse($method->invoke($builder, $body, $scan));
        $body[] = 'text';
        $this->assertFalse($method->invoke($builder, $body, $scan));
    }

    public function testParagraphContinuationKeepsItsFirstNonemptyLine(): void
    {
        $method = new ReflectionMethod(DefinitionListBuilder::class, 'firstBodyLine');
        foreach (['', "\n", 'text', "\n\ntext\nmore", " text\n", "\x00L\x00x\nmore"] as $entry) {
            $this->assertSame(strtok($entry, "\n"), $method->invoke(null, $entry));
        }
    }
}
