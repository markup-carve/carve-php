<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\Parser\BlockParser;
use MarkupCarve\Carve\Parser\NestedLeadFenceState;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

class NestedLeadFenceScanTest extends TestCase
{
    use ScalingGuardTrait;

    #[Group('scaling')]
    public function testClosedNestedFenceContinuationScalesLinearly(): void
    {
        $parser = new BlockParser();
        $lead = "- - ```\n    ```\n    y\n";
        $this->assertConversionScalesLinearly(
            static fn (string $source) => $parser->parse($source),
            $lead . str_repeat("x\n", 1000),
            $lead . str_repeat("x\n", 4000),
            'closed nested fence continuation',
            1000,
            4000,
        );
    }

    #[Group('scaling')]
    public function testDescriptionSourceAndCollectedFenceLookaheadsScaleLinearly(): void
    {
        $parser = new BlockParser();
        foreach (
            [
                [":: t\n: para\n", "   ```a\n"],
                [":: t\n: - ```\n", "```\n"],
                [":: t\n: para\n", "    x\n"],
            ] as [$lead, $unit]
        ) {
            $this->assertConversionScalesLinearly(
                static fn (string $source) => $parser->parse($source),
                $lead . str_repeat($unit, 2000),
                $lead . str_repeat($unit, 8000),
                'description fence lookahead and tail',
                2000,
                8000,
            );
        }
    }

    public function testCursorAdvancesThroughEachCollectedLineOnce(): void
    {
        foreach ([50, 100, 200] as $size) {
            $parser = new class extends BlockParser {
                public int $visited = 0;

                protected function advanceNestedLeadFenceState(NestedLeadFenceState $state, array $itemLines): void
                {
                    $before = $state->nextLine;
                    parent::advanceNestedLeadFenceState($state, $itemLines);
                    $this->visited += $state->nextLine - $before;
                }
            };
            $parser->parse("- - ```\n    ```\n    y\n" . str_repeat("x\n", $size));
            $this->assertLessThanOrEqual($size + 2, $parser->visited);
            $this->assertGreaterThan($size, $parser->visited);
        }
    }

    public function testOverriddenCompleteScanHooksStillRun(): void
    {
        $parser = new class extends BlockParser {
            public int $queries = 0;

            protected function nestedLeadFenceClosure(array $itemLines): ?array
            {
                $this->queries++;

                return parent::nestedLeadFenceClosure($itemLines);
            }
        };
        $parser->parse("- - ```\n    ```\n    y\nx\n");
        $this->assertGreaterThan(0, $parser->queries);
    }

    public function testIncrementalAnswersMatchTheCompleteScanAtEveryPrefix(): void
    {
        $parser = new class extends BlockParser {
            public function check(array $lines): void
            {
                $state = new NestedLeadFenceState();
                $prefix = [];
                $this->advanceNestedLeadFenceState($state, $prefix);
                $sawClosed = false;
                $sawParagraph = false;
                foreach ($lines as $line) {
                    $prefix[] = $line;
                    $this->advanceNestedLeadFenceState($state, $prefix);
                    $sawClosed = $sawClosed || ($state->closed && $state->onlyBlankBelow);
                    $sawParagraph = $sawParagraph || ($state->closed && $state->trailing->openParagraph);
                    TestCase::assertSame($this->nestedLeadEndsInAClosedFence($prefix), $state->closed && $state->onlyBlankBelow);
                    TestCase::assertSame($this->nestedLeadParagraphOpenBelowAClosedFence($prefix), $state->closed && $state->trailing->openParagraph);
                }
                if ($lines[0] === '- ```' && $lines[2] === '  ```') {
                    TestCase::assertTrue($sawClosed);
                    TestCase::assertTrue($sawParagraph);
                }
            }
        };
        foreach (['- ```', '- ~~~', '- - ```', '1. ```', '- ~~~html', '- ``` =html', '- prose', '```'] as $lead) {
            $column = str_starts_with($lead, '- - ') ? 4 : (str_starts_with($lead, '1.') ? 3 : 2);
            $indent = str_repeat(' ', $column);
            foreach (['```', '~~~', '``', '~~~~'] as $closer) {
                $parser->check([$lead, $indent . 'body', $indent . $closer, '', $indent . 'paragraph', "\x00L\x00x", ' x', '', $indent . '# heading', $indent . 'paragraph', ' y']);
            }
        }
    }
}
