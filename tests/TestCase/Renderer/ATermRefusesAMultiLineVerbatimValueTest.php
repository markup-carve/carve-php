<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Exception\SourceUnspellableException;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A definition term is one line (rule A), so a verbatim value with a line
 * break cannot be written in one (carve-php#4623).
 */
final class ATermRefusesAMultiLineVerbatimValueTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function values(): array
    {
        return [
            'indented quote line' => [":: term\n: body\n", "x\n > y\ntail"],
            'plain line' => [":: term\n: body\n", "x\ny"],
            'indented heading' => [":: term\n: body\n", "x\n  # h"],
            'in an item' => ["- :: term\n  : body\n", "x\n   - y"],
            'in a quote' => ["> :: term\n> : body\n", "x\n > y"],
        ];
    }

    #[DataProvider('values')]
    public function testTheWriterRefuses(string $source, string $value): void
    {
        $this->expectException(SourceUnspellableException::class);
        (new CarveRenderer())->render($this->withTermCode($source, $value));
    }

    public function testAOneLineValueStillWrites(): void
    {
        $written = (new CarveRenderer())->render($this->withTermCode(":: term\n: body\n", 'x y'));
        self::assertSame(":: `x y`\n: body\n", $written);
    }

    private function withTermCode(string $source, string $value): Document
    {
        $codec = new AstCodec();
        $wire = $codec->encode((new CarveConverter())->parse($source));
        $wire = $this->replaceTerm($wire, $value);

        return $codec->decode($wire);
    }

    /**
     * @param array<mixed> $node
     * @param string $value
     *
     * @return array<mixed>
     */
    private function replaceTerm(array $node, string $value): array
    {
        if (($node['type'] ?? null) === 'definition_term') {
            $node['children'] = [['type' => 'code', 'value' => $value]];

            return $node;
        }
        foreach (['children', 'items'] as $key) {
            if (isset($node[$key]) && is_array($node[$key])) {
                foreach ($node[$key] as $index => $child) {
                    if (is_array($child)) {
                        $node[$key][$index] = $this->replaceTerm($child, $value);
                    }
                }
            }
        }

        return $node;
    }
}
