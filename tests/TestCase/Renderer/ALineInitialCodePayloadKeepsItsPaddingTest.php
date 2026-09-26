<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Exception\SourceUnspellableException;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ALineInitialCodePayloadKeepsItsPaddingTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function sourceProvider(): array
    {
        return [
            'first in strike' => ["x {~``\n`~}\n"],
            'two-backtick opener' => ["``\n`\n"],
            'braced strike' => ["{~before ``\n`~}\n"],
            'after code' => ["`z` ``\n`\n"],
            'after text' => ["before ``\n`\n"],
            'longer run' => ["before ```\n``\n"],
            'following text' => ["before `` `x` `` after\n"],
            'attributes' => ["before `` `x` ``{.code}\n"],
        ];
    }

    #[DataProvider('sourceProvider')]
    public function testFormattingPreservesTheCodeValue(string $source): void
    {
        $formatted = CarveConverter::toCarve($source);
        $html = CarveConverter::create();
        $this->assertSame($html->convert($source), $html->convert($formatted));
        $this->assertSame($formatted, CarveConverter::toCarve($formatted));
        $this->assertDoesNotMatchRegularExpression('/ +$/m', $formatted);
    }

    public function testAMidRunLeadingNewlineIsRefusedInsteadOfChanged(): void
    {
        $doc = (new AstCodec())->decode([
            'type' => 'document',
            'srcByteLength' => 0,
            'children' => [
                [

                    'type' => 'paragraph',
                    'children' => [
                        ['type' => 'text', 'value' => 'before '],
                        ['type' => 'code', 'value' => "\n`"],
                        ['type' => 'text', 'value' => ' after'],
                    ],
                ],
            ],
        ]);
        $this->expectException(SourceUnspellableException::class);
        (new CarveRenderer())->render($doc);
    }
}
