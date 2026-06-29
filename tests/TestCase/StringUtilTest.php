<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\Util\StringUtil;
use PHPUnit\Framework\TestCase;

class StringUtilTest extends TestCase
{
    public function testFindSafeCodeFenceUsesLongestBacktickRun(): void
    {
        $this->assertSame('`', StringUtil::findSafeCodeFence('plain', 1));
        $this->assertSame('````', StringUtil::findSafeCodeFence('a ``` b ``', 1));
        $this->assertSame('```', StringUtil::findSafeCodeFence('a `` b', 3));
    }

    public function testFindSafeCodeFenceScansLongRuns(): void
    {
        $content = str_repeat('`', 10000);

        $this->assertSame(str_repeat('`', 10001), StringUtil::findSafeCodeFence($content, 1));
    }
}
