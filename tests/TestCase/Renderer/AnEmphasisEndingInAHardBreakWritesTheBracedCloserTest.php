<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A trailing hard break puts the closer at the start of the next line, where a
 * bare closer does not close (markup-carve/carve-php#2047).
 */
class AnEmphasisEndingInAHardBreakWritesTheBracedCloserTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function sourceProvider(): array
    {
        return [
            'strike' => ["{~x\\\n~}\n"],
            'emphasis' => ["{/x\\\n/}\n"],
            'strong' => ["{*x\\\n*}\n"],
            'underline' => ["{_x\\\n_}\n"],
            'highlight' => ["{=x\\\n=}\n"],
            'only a break' => ["{~\\\n~}\n"],
            'two breaks' => ["{~x\\\ny\\\n~}\n"],
            'between words' => ["a {~x\\\n~} b\n"],
            'in a list item' => ["- {*x\\\n  *}\n"],
        ];
    }

    #[DataProvider('sourceProvider')]
    public function testFormattingKeepsTheTree(string $source): void
    {
        $this->assertSame($this->ast($source), $this->ast(CarveConverter::toCarve($source)));
    }

    #[DataProvider('sourceProvider')]
    public function testFormattingIsIdempotent(string $source): void
    {
        $formatted = CarveConverter::toCarve($source);

        $this->assertSame($formatted, CarveConverter::toCarve($formatted));
    }

    /**
     * Control: a break with text after it leaves the closer on a word, so the
     * bare form stays.
     */
    public function testABreakBeforeTextKeepsTheBareCloser(): void
    {
        $this->assertSame("~x\\\ny~\n", CarveConverter::toCarve("{~x\\\ny~}\n"));
    }

    /**
     * @return array<string, mixed>
     */
    protected function ast(string $source): array
    {
        $ast = (new AstCodec())->encode(CarveConverter::create()->parse($source));
        unset($ast['srcByteLength']);

        return $ast;
    }
}
