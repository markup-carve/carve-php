<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\Parser\BlockParser;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use PHPUnit\Framework\TestCase;

class TrailingStateCompatibilityTest extends TestCase
{
    public function testAnOverriddenCollectorKeepsItsArraySignature(): void
    {
        $parser = new class extends BlockParser {
            public int $calls = 0;

            protected function collectPlainListItemContinuation(
                array $lines,
                int $i,
                int $count,
                int $baseIndent,
                int $contentIndent,
                array &$itemLines,
                array &$itemLineMap,
                array $trailingState,
                bool $leadIsBareContinuationMarker = false,
                array &$authoredBaseEligible = [],
            ): array {
                $this->calls++;
                $trailingState['extension-state'] = 'kept';
                $result = parent::collectPlainListItemContinuation($lines, $i, $count, $baseIndent, $contentIndent, $itemLines, $itemLineMap, $trailingState, $leadIsBareContinuationMarker, $authoredBaseEligible);
                TestCase::assertSame('kept', $result[1]['extension-state']);

                return $result;
            }
        };
        $parser->parse("- first\n  second\n  third\n\n- fourth\n");

        $this->assertGreaterThan(0, $parser->calls);
    }

    public function testTheLegacyFenceMetadataSurvivesItsCloser(): void
    {
        $parser = new class extends BlockParser {
            public function closedFence(): array
            {
                $state = $this->advanceTrailingBlockState(self::INITIAL_TRAILING_BLOCK_STATE, '```');
                $state['extension-state'] = 'kept';

                return $this->advanceTrailingBlockState($state, '```');
            }
        };
        $state = $parser->closedFence();

        $this->assertFalse($state['inFence']);
        $this->assertSame('`', $state['fenceChar']);
        $this->assertSame(3, $state['fenceLength']);
        $this->assertSame('kept', $state['extension-state']);
    }

    public function testEachTrailingStateOverrideRunsDuringParsing(): void
    {
        $parsers = [
            new class extends BlockParser {
                public int $calls = 0;

                protected function advanceTrailingBlockState(array $state, string $line, bool $atContentColumn = false): array
                {
                    $this->calls++;

                    return parent::advanceTrailingBlockState($state, $line, $atContentColumn);
                }
            },
            new class extends BlockParser {
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
            },
            new class extends BlockParser {
                public int $calls = 0;

                protected function attachedBlockHasEnded(string $kind, string $line, array $lines, int $index, array $trailingState): bool
                {
                    $this->calls++;

                    return parent::attachedBlockHasEnded($kind, $line, $lines, $index, $trailingState);
                }
            },
        ];
        $sources = [
            'list' => ["- first\n  second\n\n- third\n", [0, 1]],
            'definition' => [":: term\n: first\n  second\nthird\n", [0, 1]],
            'quote' => ["> - first\n>   second\ncontinued\n", [0, 1]],
            'footnote' => ["[^note]: first\n  second\n\n[^note]\n", [0]],
            'attached block' => ["- first\n  second\n\n> quoted\n+\nparagraph\ncontinued\n# next\n", [0, 1, 2]],
        ];
        $renderer = new HtmlRenderer();
        foreach ($parsers as $hook => $parser) {
            foreach ($sources as $name => [$source, $requiredHooks]) {
                $expected = $renderer->render((new BlockParser())->parse($source));
                $parser->calls = 0;
                self::assertSame($expected, $renderer->render($parser->parse($source)), $name);
                if (in_array($hook, $requiredHooks, true)) {
                    self::assertGreaterThan(0, $parser->calls, $name);
                }
                $before = $parser->calls;
                $copy = clone $parser;
                self::assertSame($expected, $renderer->render($copy->parse($source)), $name);
                self::assertSame($before, $parser->calls, $name);
                if (in_array($hook, $requiredHooks, true)) {
                    self::assertGreaterThan($before, $copy->calls, $name);
                }
            }
        }
    }
}
