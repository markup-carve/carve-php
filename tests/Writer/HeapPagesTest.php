<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use PHPUnit\Framework\TestCase;

final class HeapPagesTest extends TestCase
{
    /**
     * A long-lived host keeps the partly used chunks of a large call. Later calls that take no new chunk skip the
     * walk over the free lists; a call that grows the heap again releases.
     */
    public function testReleasesOnlyAfterTheHeapTakesNewChunks(): void
    {
        $autoload = var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true);
        $program = 'require ' . $autoload . '; '
            . '$fill = static function (int $n): array { $kept = []; $all = []; '
            . 'for ($i = 0; $i < $n; $i++) { $all[] = str_repeat("x", 40) . $i; if ($i % 64 === 0) { $kept[] = $all[$i]; } } '
            . 'return $kept; }; '
            . '$kept = $fill(400000); '
            . '$first = \MarkupCarve\Carve\Writer\HeapPages::release(); '
            . '$small = str_repeat("y", 1000); '
            . '$again = \MarkupCarve\Carve\Writer\HeapPages::release(); '
            . '$more = $fill(1600000); '
            . '$grown = \MarkupCarve\Carve\Writer\HeapPages::release(); '
            . 'echo json_encode([$first, $again, $grown]);';

        $process = proc_open([PHP_BINARY, '-d', 'memory_limit=1G', '-r', $program], [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        self::assertSame(0, proc_close($process), (string)$stderr);
        self::assertSame('[true,false,true]', $stdout);
    }
}
