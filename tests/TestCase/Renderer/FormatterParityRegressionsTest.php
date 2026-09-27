<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

class FormatterParityRegressionsTest extends TestCase
{
    public function testFormattingPreservesRenderedContentAndIsIdempotent(): void
    {
        $converter = new CarveConverter();
        foreach (["`\n\t> x\n", "~``` x\n[d]: u ```\n", "`\n``\n", "`\n``x\n", "1. [d]: u\n", "- A\n{x}\n*[A]: }\n"] as $source) {
            $formatted = CarveConverter::toCarve($source);
            $this->assertSame($converter->convert($source), $converter->convert($formatted), $source);
            $this->assertSame($formatted, CarveConverter::toCarve($formatted), $source);
        }
    }
}
