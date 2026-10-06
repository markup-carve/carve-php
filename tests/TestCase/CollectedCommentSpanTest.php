<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\Parser\Block\FencedBlockParser;
use MarkupCarve\Carve\Parser\BlockParser;
use MarkupCarve\Carve\Parser\CollectedCommentSpan;
use PHPUnit\Framework\TestCase;

class CollectedCommentSpanTest extends TestCase
{
    public function testCollectorsDoNotRecheckEveryEarlierCommentLine(): void
    {
        foreach (["[^a]: %%%\n", ":: t\n: - %%%\n", "- head\n\n    %%%\n"] as $lead) {
            foreach ([50, 100, 200] as $size) {
                $fences = new class extends FencedBlockParser {
                    public int $checks = 0;

                    public function isFencedCommentCloser(string $line, int $fenceLength): bool
                    {
                        $this->checks++;

                        return parent::isFencedCommentCloser($line, $fenceLength);
                    }
                };
                $parser = new class ($fences) extends BlockParser {
                    public function __construct(FencedBlockParser $fences)
                    {
                        parent::__construct();
                        $this->fencedBlockParser = $fences;
                    }
                };
                $parser->parse($lead . str_repeat("%% c\n", $size));
                $this->assertGreaterThanOrEqual($size - 1, $fences->checks);
                $this->assertLessThanOrEqual(8 * $size, $fences->checks);
            }
        }
    }

    public function testOverriddenCommentSpanHookStillRuns(): void
    {
        $parser = new class extends BlockParser {
            public int $queries = 0;

            protected function linesLeaveACommentSpanOpen(array $lines): bool
            {
                $this->queries++;

                return parent::linesLeaveACommentSpanOpen($lines);
            }
        };
        $parser->parse("[^a]: %%%\n%% c\n%% c\n");
        $this->assertGreaterThan(0, $parser->queries);
    }

    public function testAppendedEntriesAndExtendedTailKeepTheCompleteScanAnswer(): void
    {
        $parser = new class extends BlockParser {
            public function check(array $lines, CollectedCommentSpan $scan): bool
            {
                $result = $this->scanCollectedCommentSpan($lines, $scan);
                TestCase::assertSame($this->linesLeaveACommentSpanOpen($lines), $result);

                return $result;
            }
        };
        foreach (['%%% open', '- %%% open', "\x00L\x00%%% open"] as $lead) {
            $scan = new CollectedCommentSpan();
            $lines = [$lead];
            $this->assertTrue($parser->check($lines, $scan));
            $lines[0] .= "\n%% payload\n%%%% wrong width";
            $this->assertTrue($parser->check($lines, $scan));
            $this->assertTrue($parser->check($lines, $scan));
            $lines[0] .= "\n%% next";
            $lines[] = '';
            $lines[] = "\x00L\x00%% c";
            $this->assertTrue($parser->check($lines, $scan));
            $lines[] = ' %%%';
            $this->assertFalse($parser->check($lines, $scan));
            $lines[] = '```';
            $lines[] = '%%% opaque';
            $this->assertFalse($parser->check($lines, $scan));
            $lines[] = '```';
            $lines[] = '%%% reopened';
            $this->assertTrue($parser->check($lines, $scan));
        }
    }
}
