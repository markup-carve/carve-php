<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

class MarkdownWrapperHardBreakTest extends TestCase
{
    public function testWrappersKeepTheirFinalHardBreakInsideTheMarkup(): void
    {
        $cases = json_decode(file_get_contents(__DIR__ . '/../../fixtures/markdown-wrapper-hard-breaks.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach ($cases as $case) {
            $this->assertSame($case['markdown'], CarveConverter::markdown()->convert($case['source']), $case['source']);
        }
    }

    public function testAnEscapedLiteralBackslashIsNotAHardBreak(): void
    {
        $this->assertSame("**word\\\\**\n\ndefinition\n", CarveConverter::markdown()->convert(":: word\\\\\n: definition\n"));
    }
}
