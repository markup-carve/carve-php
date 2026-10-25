<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\TestCase;

final class CanonicalLiteralBackslashTest extends TestCase
{
    public function testBareBackslashesBeforeNonPunctuation(): void
    {
        foreach (['\\a', 'a\\b', '\\«', '\\é'] as $value) {
            self::assertSame($value . "\n", $this->format($value . "\n"));
        }
    }

    public function testEveryPunctuationNeighborPreservesTheParse(): void
    {
        $converter = new CarveConverter();
        foreach (str_split('!"#$%&\'()*+,-./:;<=>?@[\\]^_`{|}~') as $char) {
            $source = 'x\\' . $char . "y\n";
            $written = $this->format($source);
            self::assertSame($converter->convert($source), $converter->convert($written), $char);
            self::assertSame($written, $this->format($written), $char);
        }
    }

    public function testTerminalAndDoubledBackslashesPreserveTheParse(): void
    {
        $converter = new CarveConverter();
        foreach (["a\\\\b\n", "a\\\\\n", "a\\\n"] as $source) {
            $written = $this->format($source);
            self::assertSame($converter->convert($source), $converter->convert($written));
            self::assertSame($written, $this->format($written));
        }
    }

    public function testPaddingMakesTheHeaderMarkerEscapeIdle(): void
    {
        self::assertSame("| = a |\n", $this->format("|\\= a |\n"));
    }

    private function format(string $source): string
    {
        return (new CarveRenderer())->render((new CarveConverter())->parse($source));
    }
}
