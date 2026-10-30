<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\Parser\DefinitionListBuilder;
use MarkupCarve\Carve\Parser\IndexedFenceView;
use MarkupCarve\Carve\Parser\RangeMaximum;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class SourceFenceCloserViewTest extends TestCase
{
    public function testCollectedCloserRechecksAnExtendedTail(): void
    {
        $view = new IndexedFenceView();
        $body = ['- ```', '  ```'];
        $view->advance($body);
        $this->assertTrue($view->contains(1, count($body), 2, '`', 3));
        $body[1] .= "\ntext";
        $view->advance($body);
        $this->assertFalse($view->contains(1, count($body), 2, '`', 3));
        $body[] = '  ```';
        $view->advance($body);
        $this->assertTrue($view->contains(1, count($body), 2, '`', 3));
    }

    public function testParagraphContinuationKeepsItsFirstNonemptyLine(): void
    {
        $method = new ReflectionMethod(DefinitionListBuilder::class, 'firstBodyLine');
        foreach (['', "\n", 'text', "\n\ntext\nmore", " text\n", "\x00L\x00x\nmore"] as $entry) {
            $this->assertSame(strtok($entry, "\n"), $method->invoke(null, $entry));
        }
    }

    public function testRangeMaximaSurviveGrowthAndReplacement(): void
    {
        $range = new RangeMaximum();
        $values = [];
        for ($i = 0; $i < 40; $i++) {
            $values[$i] = ($i * 17) % 29;
            $range->set($i, $values[$i]);
            foreach (range(0, $i + 1) as $start) {
                foreach (range($start, $i + 1) as $end) {
                    $slice = array_slice($values, $start, $end - $start);
                    $this->assertSame($slice === [] ? 0 : max($slice), $range->maximum($start, $end));
                }
            }
        }
        foreach (range(0, 39) as $i) {
            $values[$i] = 0;
            $range->set($i, 0);
            $this->assertSame(max($values), $range->maximum(0, 40));
        }
    }
}
