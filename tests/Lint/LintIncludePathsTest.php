<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Lint;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Diagnostics\Diagnostic;
use PHPUnit\Framework\TestCase;

final class LintIncludePathsTest extends TestCase
{
    public function testSectionFindingNamesTheBlockUnderTheInclude(): void
    {
        $found = $this->lint("{{ b.crv #K }}\n", ['b.crv' => "a ~~b~~ c\n\n= K\n\nd **e** f\n"], 'markdown-strong-double-star');

        self::assertCount(1, $found);
        self::assertSame('/children/0/children/1', $found[0]->path);
        self::assertSame([18, 23], [$found[0]->startOffset, $found[0]->endOffset]);
    }

    public function testSectionFindingKeepsItsDepthUnderTheInclude(): void
    {
        $found = $this->lint("{{ s.crv #S }}\n", ['s.crv' => "= S\n\n> [a]: /u\n"], 'definition-in-container');

        self::assertCount(1, $found);
        self::assertSame('/children/0/children/1/children/0', $found[0]->path);
    }

    public function testWholeFileFindingKeepsTheRulePath(): void
    {
        $found = $this->lint("{{ b.crv }}\n", ['b.crv' => "a **b** c\n"], 'markdown-strong-double-star');

        self::assertCount(1, $found);
        self::assertSame('/children/0/children/0', $found[0]->path);
    }

    public function testPerColumnFindingsThatShareARangeAreAllKept(): void
    {
        $found = $this->lint("{{ t.crv }}\n", ['t.crv' => "{aligns=left,right}\n|={<} a |={>} b |\n"], 'table-column-overlap');

        self::assertCount(2, $found);
        self::assertStringStartsWith('Column 1 ', $found[0]->message);
        self::assertStringStartsWith('Column 2 ', $found[1]->message);
    }

    public function testFileIncludedTwiceReportsItsFindingOnce(): void
    {
        $found = $this->lint("{{ b.crv }}\n\n{{ b.crv }}\n", ['b.crv' => "a **b** c\n"], 'markdown-strong-double-star');

        self::assertCount(1, $found);
    }

    /**
     * @param string $source
     * @param array<string, string> $files
     * @param string $code
     *
     * @return array<\MarkupCarve\Carve\Diagnostics\Diagnostic>
     */
    private function lint(string $source, array $files, string $code): array
    {
        $resolver = static fn (array $request): ?array => isset($files[$request['path']])
            ? ['text' => $files[$request['path']], 'id' => $request['path']]
            : null;
        $found = array_filter(Carve::lint($source, ['includes' => $resolver]), static fn (Diagnostic $d): bool => $d->code === $code);

        return array_values($found);
    }
}
