<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Exception\ContainerPrefixDepthExceededException;
use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\TestCase;

/**
 * A line whose container prefix runs past the walk's bound REFUSES.
 *
 * PART 9 §25: "Reaching it MUST produce a typed, documented failure naming the
 * depth bound. NOT silent truncation, not a partial document, and not whatever
 * the host language raises when the stack runs out." The last clause is the one
 * this pins. `BlockParser::advanceTrailingBlockStateAt()` spends a stack frame
 * per prefix element, `zend.max_allowed_stack_size` is `0` on a default build,
 * and a line of 18000 `> - ` pairs therefore took the whole process down with a
 * SIGSEGV: no document, no exception, exit 139 (carve-php#1456).
 *
 * THE FIRST CASE RUNS IN A SUBPROCESS, and that is the whole point of it. An
 * overflow is not catchable here, so an in-process assertion about it would
 * take the test runner with it and report nothing; measured against `main`, the
 * child exits 139 and this file's own expectation of exit 1 is what fails.
 * Asserting the refusal in-process would prove nothing, because the process
 * that had to survive to make the assertion is the one that died.
 *
 * THIS REPLACES `QuotedMarkerLineScaleTest`, and reads no clock. That guard
 * measured the walk's per-byte cost across an 8x range of prefix elements, and
 * the bound removes the axis it measured: the walk now costs at most
 * MAX_LINE_PREFIX_DEPTH steps per line, so the shape is linear in the line with
 * a bounded constant rather than superlinear in the prefix. Below the bound it
 * also cannot discriminate, which was measured rather than assumed - with a
 * copy-per-level mutation standing in for the spelling carve-php#1437 removed,
 * 128 against 512 pairs reads 1.58 where the healthy walk reads 1.65, both
 * under that guard's own 2.0 threshold. Its own docblock said as much: the
 * per-byte cost is still rising until roughly 2000 prefix elements, and 512 is
 * the most the bound now allows. Keeping it would have been a check that cannot
 * fail (markup-carve/carve#755).
 */
class ALongContainerPrefixRefusesTest extends TestCase
{
    /**
     * Pairs whose prefix crashed the process before the bound existed. Above
     * the measured 16000 that still parsed on an 8 MB stack, so the child is
     * asked the question the ticket asked.
     *
     * @var int
     */
    private const OVERFLOWING_PAIRS = 20000;

    /**
     * That the process SURVIVES, with a report the caller can act on.
     *
     * Exit 1 and the message on stderr is what `bin/carve` already does for the
     * render ceiling; before the bound this same input produced exit 139 and an
     * empty stderr, which is the state §25 names explicitly.
     */
    public function testALineWhoseContainerPrefixWouldOverflowTheStackRefuses(): void
    {
        $result = $this->runCarve(str_repeat('> - ', self::OVERFLOWING_PAIRS) . "x\n");

        $this->assertSame(1, $result['exit'], sprintf(
            'expected a refusal, got exit %d (139 is the SIGSEGV this bound exists to end); stderr: %s',
            $result['exit'],
            $result['err'],
        ));
        $this->assertStringContainsString('container prefix nests', $result['err']);
        $this->assertStringContainsString(
            (string)BlockParser::MAX_LINE_PREFIX_DEPTH,
            $result['err'],
            'the refusal names the bound it hit',
        );
        $this->assertSame('', $result['out'], 'a refusal publishes no partial document');
    }

    /**
     * The bound is where the constant says it is - the inside half.
     *
     * Each `> - ` pair costs two walk steps, so the bound halved is the largest
     * pair count that fits. Naming it relative to the constant is what makes
     * this fail if the constant moves rather than silently re-describing it.
     */
    public function testAPrefixWithinTheBoundParses(): void
    {
        $pairs = intdiv(BlockParser::MAX_LINE_PREFIX_DEPTH, 2);

        $html = (new CarveConverter())->convert(str_repeat('> - ', $pairs) . "x\n");

        $this->assertStringContainsString('<blockquote>', $html);
        $this->assertStringContainsString('x', $html);
    }

    /**
     * The other half: past the bound, a typed refusal naming it.
     */
    public function testAPrefixPastTheBoundRefuses(): void
    {
        $this->expectException(ContainerPrefixDepthExceededException::class);
        $this->expectExceptionMessage((string)BlockParser::MAX_LINE_PREFIX_DEPTH);

        (new CarveConverter())->parse(
            str_repeat('> - ', BlockParser::MAX_LINE_PREFIX_DEPTH) . "x\n",
        );
    }

    /**
     * INTENDED SURVIVOR. The deepest document the parser will actually build
     * still parses and still renders its innermost content.
     *
     * A bound placed on the wrong axis - or placed on the right axis and set
     * too low - refuses this, because it is legitimately nested to the parser's
     * own container cap. It stays well inside the prefix bound because a
     * document spends its depth over LINES, where this bound counts one line's
     * markers.
     */
    public function testTheDeepestDocumentTheParserBuildsStillParses(): void
    {
        $lines = [];
        for ($level = 0; $level < BlockParser::MAX_NESTING_DEPTH / 2; $level++) {
            $lines[] = str_repeat('  ', $level) . '- level ' . $level;
        }
        $lines[] = str_repeat('  ', (int)(BlockParser::MAX_NESTING_DEPTH / 2)) . 'deepest';

        $html = (new CarveConverter())->convert(implode("\n", $lines) . "\n");

        $this->assertStringContainsString('level 0', $html);
        $this->assertStringContainsString('deepest', $html);
    }

    /**
     * INTENDED SURVIVOR, on the bound's own axis: a quoted list nested as deep
     * as the parser's container cap allows, spelled on ONE line, is inside the
     * bound and renders.
     */
    public function testAQuotedPrefixAtTheContainerCapStillParses(): void
    {
        $html = (new CarveConverter())->convert(
            str_repeat('> - ', BlockParser::MAX_NESTING_DEPTH) . "deepest\n",
        );

        $this->assertStringContainsString('deepest', $html);
        $this->assertStringContainsString('<blockquote>', $html);
    }

    /**
     * INTENDED SURVIVOR, and the one the bound is most likely to be widened
     * into by mistake: a RUN of list markers costs ONE level, not one per
     * marker, so a line of them far past the bound still parses.
     *
     * `markerWalkOffset()` peels the whole run in a loop - carve-php#1426 and
     * carve-php#1442 - so the run spends no stack and needs no bound here.
     * Counting markers instead of levels would refuse this, which is the shape
     * a reviewer proposes when reading the constant's name rather than the
     * walk.
     */
    public function testARunOfListMarkersCostsOneLevelAndStillParses(): void
    {
        $markers = BlockParser::MAX_LINE_PREFIX_DEPTH * 8;

        $html = (new CarveConverter())->convert(str_repeat('- ', $markers) . "deepest\n");

        $this->assertStringContainsString('deepest', $html);
    }

    /**
     * Run `bin/carve` on $source in a child process.
     *
     * @return array{out: string, err: string, exit: int}
     */
    private function runCarve(string $source): array
    {
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 2) . '/bin/carve'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        $this->assertIsResource($process);
        fwrite($pipes[0], $source);
        fclose($pipes[0]);
        $out = (string)stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $err = (string)stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        return ['out' => $out, 'err' => $err, 'exit' => proc_close($process)];
    }
}
