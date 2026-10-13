<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

class ATabBeforeATrailingCommentRemainsLabelContentTest extends TestCase
{
    public function testEveryRepinnedTabCommentRowMatchesTheCorpus(): void
    {
        $sources = glob(__DIR__ . '/spec/tests/corpus/518-*.crv');
        $selected = [];
        foreach ($sources as $path) {
            if (preg_match('/-(1|3|5|7|9|12)\.crv$/', $path) === 1 || !preg_match('/-\d+\.crv$/', $path)) {
                $selected[] = $path;
            }
        }
        array_push($selected, ...glob(__DIR__ . '/spec/tests/corpus/516-*-8.crv'));
        self::assertCount(7, $selected);
        foreach ($selected as $path) {
            self::assertSame(trim(file_get_contents(substr($path, 0, -4) . '.html')), trim((new CarveConverter())->convert(file_get_contents($path))), basename($path));
        }
    }
}
