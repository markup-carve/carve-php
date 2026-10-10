<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\Renderer\MarkdownRenderer;
use PHPUnit\Framework\TestCase;

class MarkdownTerminalHardBreakTest extends TestCase
{
    public function testTerminalBreaksSurviveInEveryInlineContext(): void
    {
        $cases = json_decode(file_get_contents(__DIR__ . '/../../fixtures/markdown-terminal-hard-break.json'), true, 512, JSON_THROW_ON_ERROR);
        $codec = new AstCodec();
        $renderer = new MarkdownRenderer();
        foreach ($cases as $case) {
            $this->assertSame($case['markdown'], $renderer->render($codec->decode($case['document'])), $case['template'] . ' ' . $case['name']);
        }
    }
}
