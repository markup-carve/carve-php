<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A long container prefix on ONE line does not take the process down.
 *
 * PART 9 §25 forbids "whatever the host language raises when the stack runs
 * out" as an answer to depth. The trailing-block tracker used to descend a
 * line's prefix one call frame per element, so `> - ` repeated 18000 times
 * segfaulted where 16000 parsed - no document, no exception, exit 139
 * (markup-carve/carve-php#1456). The prefix is measured against the nesting cap
 * before the tracker descends it now, and past the cap it degrades to paragraph
 * text, which is what carve-js and carve-rs already produced for the same line.
 *
 * IN A SUBPROCESS, because an overflow is not catchable: an in-process
 * assertion about it takes the runner with it and reports nothing. The child's
 * exit code is the whole measurement, and 139 is the failure this guards.
 *
 * IN THE DEFAULT SUITE, not in the `scaling` group. The first spelling of this
 * check sat in `QuotedMarkerLineScaleTest`, which is excluded from a plain
 * `phpunit` run because its neighbours read a clock. This one reads no clock -
 * the crash is depth - so hiding it behind the wall-clock exclusion meant an
 * ordinary run could not see the regression it exists to catch.
 *
 * MORE THAN ONE SHAPE, because more than one shape crashed. With the cap check
 * mutated to `return false`, four of the alternations below exit 139 and the
 * rest do not: a run of one marker kind is peeled by a loop and never descended
 * per element (markup-carve/carve-php#1426, markup-carve/carve-php#1442), and
 * two of the spacings do not alternate at all. The five that never crashed stay
 * in the table as intended survivors - a bound that starts refusing them is on
 * the wrong axis, and this is where that shows.
 */
class AnAlternatingContainerPrefixDoesNotCrashTest extends TestCase
{
    /**
     * Past the 16000 that parsed on an 8 MB stack and at the 18000 that did
     * not, so the child is asked the question the ticket asked.
     *
     * @var int
     */
    private const ELEMENTS = 18000;

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function prefixShapes(): array
    {
        return [
            // Alternations. Each crashes with the cap check mutated away.
            'quote and bullet' => ['> - ', true],
            'bullet and quote' => ['- > ', true],
            'quote and ordered marker' => ['> 1. ', true],
            'quote, bullet and star' => ['> - * ', true],
            // Never at risk, and here to stay that way.
            'quote markers alone' => ['> ', false],
            'bullets alone' => ['- ', false],
            'quote and plus bullet' => ['> + ', false],
            'quote and bullet, widely spaced' => ['>  -  ', false],
            'quote and bullet, tab between' => [">\t- ", false],
        ];
    }

    /**
     * @param string $element Repeated to build the line's prefix.
     * @param bool $descends Whether this shape descends the tracker per
     *   element, and therefore whether the cap check is what keeps it alive.
     */
    #[DataProvider('prefixShapes')]
    public function testALongPrefixParsesRatherThanCrashing(string $element, bool $descends): void
    {
        $result = $this->parseInChild(str_repeat($element, self::ELEMENTS) . "x\n");

        $this->assertSame(0, $result['exit'], sprintf(
            '%s: the child exited %d%s; stderr: %s',
            $descends ? 'this shape descends the tracker per element' : 'this shape was never at risk',
            $result['exit'],
            $result['exit'] === 139 ? ' (SIGSEGV, the failure this guards)' : '',
            $result['err'],
        ));
        $this->assertSame('ok', $result['out']);
    }

    /**
     * Parse $source in a child process; the exit code is the measurement.
     *
     * @return array{out: string, err: string, exit: int}
     */
    private function parseInChild(string $source): array
    {
        $script = 'require ' . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ';'
            . '(new MarkupCarve\Carve\CarveConverter())->parse(file_get_contents("php://stdin"));'
            . 'echo "ok";';
        $process = proc_open(
            [PHP_BINARY, '-d', 'memory_limit=1G', '-r', $script],
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
