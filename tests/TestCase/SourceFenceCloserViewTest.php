<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\Parser\Block\FencedBlockParser;
use MarkupCarve\Carve\Parser\BlockContinuationScanner;
use MarkupCarve\Carve\Parser\BlockGrammar;
use MarkupCarve\Carve\Parser\BlockParser;
use MarkupCarve\Carve\Parser\CollectedFenceView;
use MarkupCarve\Carve\Parser\DefinitionListBuilder;
use MarkupCarve\Carve\Parser\IndexedFenceView;
use MarkupCarve\Carve\Parser\RangeMaximum;
use MarkupCarve\Carve\Parser\Utility\IndentationHelper;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

class SourceFenceCloserViewTest extends TestCase
{
    public function testIndexedAnswersMatchTheCompleteScan(): void
    {
        $parser = new BlockParser();
        /** @var \MarkupCarve\Carve\Parser\BlockContinuationScanner $scanner */
        $scanner = (new ReflectionMethod($parser, 'continuationsMapper'))->invoke($parser);
        $this->assertInstanceOf(BlockContinuationScanner::class, $scanner);
        $lines = ['text', '```', '  ~~~~~', "\t```", ' ```', '    ~~~', '  ````', '```x', '  ```', '~~~~', '  ``` \t', " \t~~~~~\t"];
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

    public function testCollectedIndexedAnswersMatchEveryGrowingPrefix(): void
    {
        $parser = new BlockParser();
        $scanner = (new ReflectionMethod($parser, 'continuationsMapper'))->invoke($parser);
        $view = new IndexedFenceView();
        $lines = [];
        foreach (['text', '  ```', "\ntext", '~~~', '  ````', "\n", '  ~~~~', 'text'] as $entry) {
            if (str_starts_with($entry, "\n")) {
                $lines[array_key_last($lines)] .= $entry;
            } else {
                $lines[] = $entry;
            }
            $view->advance($lines);
            foreach (range(0, count($lines)) as $index) {
                foreach ([0, 2] as $column) {
                    foreach (['`', '~'] as $char) {
                        foreach ([3, 4, 8] as $width) {
                            $opener = ['char' => $char, 'fence' => str_repeat($char, $width), 'length' => $width];
                            $this->assertSame(
                                $scanner->hasFenceCloserInView($lines, $index, $opener, $column),
                                $view->contains($index + 1, count($lines), $column, $char, $width),
                            );
                        }
                    }
                }
            }
        }
    }

    public function testDescriptionBoundariesMatchThePriorForwardScan(): void
    {
        foreach (
            [
                ['text', '  ```php', '  text', '  ```', ':: next', ':  body'],
                ['text', '  ```php', '', '  ```', ':: next'],
                ['text', '  ```php', '', '', '  ```'],
                ['text', '  ```php', '', ' ```'],
                ['text', '  ```php', 'text', ':: next', '  ```'],
                ['text', '  ```php', 'text', '  ````', '', ''],
                ['text', '  ```php', " \t", " \t``` \t", '', '  x', '  ```'],
                ['text', '  ```php', ':: next', '  ```'],
            ] as $lines
        ) {
            $parser = new BlockParser();
            $scanner = (new ReflectionMethod($parser, 'continuationsMapper'))->invoke($parser);
            $fenced = new FencedBlockParser();
            $lineCount = count($lines);
            foreach (range(0, count($lines)) as $index) {
                foreach ([0, 1, 2, 4] as $bodyColumn) {
                    foreach ([0, 1, 2, 4] as $openerColumn) {
                        $expected = false;
                        for ($j = $index + 1; $j < $lineCount; $j++) {
                            $line = $lines[$j];
                            if (preg_match(BlockGrammar::DEFINITION_TERM_LINE_PREFIX, $line) || preg_match(BlockGrammar::DEFINITION_BODY_LINE_PREFIX, $line)) {
                                break;
                            }
                            if (IndentationHelper::isBlankLine($line)) {
                                $look = $j;
                                while ($look < $lineCount && IndentationHelper::isBlankLine($lines[$look])) {
                                    $look++;
                                }
                                $after = $lines[$look] ?? null;
                                if ($look - $j > 1 || $after === null || IndentationHelper::getLeadingColumns($after, $bodyColumn) < $bodyColumn) {
                                    break;
                                }
                                $j = $look - 1;

                                continue;
                            }
                            if (
                                IndentationHelper::getLeadingColumns($line, $openerColumn + 1) === $openerColumn
                                && $fenced->isCodeFenceCloser(IndentationHelper::stripLeadingColumns($line, $openerColumn), '`', 3)
                            ) {
                                $expected = true;

                                break;
                            }
                        }
                        $this->assertSame($expected, $scanner->descriptionBodyCloserAhead($lines, $index, ['fence' => '```', 'length' => 3], $bodyColumn, $openerColumn));
                    }
                }
            }
        }
    }

    public function testCustomFenceParserKeepsItsCloserPredicate(): void
    {
        $parser = new BlockParser();
        $scanner = (new ReflectionMethod($parser, 'continuationsMapper'))->invoke($parser);
        $custom = new class extends FencedBlockParser {
            public int $calls = 0;

            public function isCodeFenceCloser(string $line, string $fenceChar, int $fenceLength): bool
            {
                $this->calls++;

                return false;
            }
        };
        (new ReflectionProperty($scanner, 'getFencedBlockParser'))->setValue($scanner, static fn () => $custom);
        $lines = ['text', '  ```', '  ```'];
        $opener = ['fence' => '```', 'length' => 3];
        $this->assertFalse($scanner->sourceFenceCloserInView($lines, 0, $opener, 2));
        $this->assertFalse($scanner->descriptionBodyCloserAhead($lines, 0, $opener, 2, 2));
        $this->assertGreaterThan(0, $custom->calls);
    }

    public function testDescriptionTrackerKeepsTheLegacyLookaheadHook(): void
    {
        $source = ":: t\n:  text\n     ```php\n     code\n     ```\nx\n";
        $legacy = new class extends BlockParser {
            public int $calls = 0;

            protected function advanceTrailingBlockStateWithFenceLookahead(
                array $state,
                string $line,
                array $lines,
                int $index,
                bool $atContentColumn = false,
                int $stripColumns = 0,
                bool $closerKnownAhead = false,
            ): array {
                $this->calls++;

                return parent::advanceTrailingBlockStateWithFenceLookahead($state, $line, $lines, $index, $atContentColumn, $stripColumns, $closerKnownAhead);
            }
        };
        $expected = (new BlockParser())->parse($source);
        $actual = $legacy->parse($source);
        $this->assertEquals($expected, $actual);
        $this->assertGreaterThan(0, $legacy->calls);
    }
}
