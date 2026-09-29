<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Renderer\AnsiRenderer;
use PHPUnit\Framework\TestCase;

class AnsiCodePayloadLinesTest extends TestCase
{
    public function testEveryPayloadLineSurvives(): void
    {
        $converter = new CarveConverter();
        $renderer = new AnsiRenderer();
        foreach (['', "\n", "\n\n", "a\n", "a\n\n", "a\n\n\n", "\na\n\n"] as $payload) {
            $source = "```\n" . $payload . "```\n";
            $expected = '';
            if ($payload !== '') {
                foreach (explode("\n", substr($payload, 0, -1)) as $line) {
                    $expected .= "\033[97m  " . $line . "\033[0m\n";
                }
            }
            $this->assertSame($expected ?: "\n", $renderer->render($converter->parse($source)), $source);
        }
    }
}
