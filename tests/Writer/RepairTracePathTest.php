<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Writer\RepairTrace;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RepairTracePathTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function paths(): array
    {
        return [
            'empty' => [''],
            'top level' => ['/children/0'],
            'nested' => ['/children/0/children/12/children/3'],
            'other segments' => ['/children/2/caption/children/0/title'],
            'children twice' => ['/children/children/children/0'],
            'trailing children' => ['/children/0/children'],
            'holds the packed byte' => ["/children/0/\x01/children/1"],
            'starts with the escape byte' => ["\x02/children/0"],
        ];
    }

    #[DataProvider('paths')]
    public function testPackedPathReadsBack(string $path): void
    {
        self::assertSame($path, RepairTrace::unpackPath(RepairTrace::packPath($path)));
    }

    public function testPackedPathsKeepDistinctPathsDistinct(): void
    {
        $packed = [];
        foreach (self::paths() as [$path]) {
            $packed[RepairTrace::packPath($path)] = $path;
        }
        $packed[RepairTrace::packPath("\x01")] = "\x01";
        $packed[RepairTrace::packPath('/children/')] = '/children/';

        self::assertCount(count(self::paths()) + 2, $packed);
    }

    public function testDigitsAppendToAPackedPath(): void
    {
        foreach (['/children/0/children/', '/children/0/', "\x02/children/"] as $parent) {
            self::assertSame(RepairTrace::packPath($parent . '17'), RepairTrace::packPath($parent) . '17');
        }
    }

    public function testCandidateNodesHoldPackedPaths(): void
    {
        $trace = new RepairTrace([], true, "\x01m\x03");
        $marked = 'a' . $trace->inlineNode('*b*', '/children/0/children/1', 'strong');
        $candidate = $trace->decode($marked);

        self::assertSame('a*b*', $candidate['source']);
        self::assertSame([[RepairTrace::packPath('/children/0/children/1'), 'strong', 1, 4]], array_values($candidate['nodes']));
    }
}
