<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Renderer\MarkdownRenderer;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

class MarkdownEscapeMemoryTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testDenseEscapeCandidatesDoNotAllocateAnArrayPerMatch(): void
    {
        $source = str_repeat('!', 200000);
        $document = CarveConverter::create()->parse($source);
        $renderer = new MarkdownRenderer();
        memory_reset_peak_usage();
        $initial = memory_get_usage(true);
        $output = $renderer->render($document);
        $extra = memory_get_peak_usage(true) - $initial;
        $this->assertSame($source . "\n", $output);
        $this->assertLessThan(32 * 1024 * 1024, $extra);
    }
}
