<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A `+` join inside a code span, raw inline or inline math has no room for a
 * `cell_wrap` node, so the payload carries `cellWraps` with the codepoint
 * offset of each joining space (CARVE-P12-069). Shapes from the spec's
 * acceptance rows.
 */
class AJoinInsideAVerbatimSpanIsCarriedTest extends TestCase
{
    /**
     * @return array<string, array{string, array<string, mixed>}>
     */
    public static function provideSpans(): array
    {
        return [
            'code span' => [
                "| `a | z |\n+ b` | w |\n",
                ['type' => 'code', 'value' => 'a | z b', 'cellWraps' => [['offset' => 5, 'line' => 1]]],
            ],
            'raw inline' => [
                "| `<b | z |\n+ c>`{=html} | w |\n",
                ['type' => 'raw_inline', 'content' => '<b | z c>', 'format' => 'html', 'cellWraps' => [['offset' => 6, 'line' => 1]]],
            ],
            'inline math' => [
                "| $`a | z |\n+ b` | w |\n",
                ['type' => 'math', 'content' => 'a | z b', 'display' => false, 'cellWraps' => [['offset' => 5, 'line' => 1]]],
            ],
            'an empty plus line is skipped' => [
                "| `a |\n+ |\n+ b` |\n",
                ['type' => 'code', 'value' => 'a b', 'cellWraps' => [['offset' => 1, 'line' => 2]]],
            ],
            'offsets count codepoints' => [
                "| `é |\n+ b` |\n",
                ['type' => 'code', 'value' => 'é b', 'cellWraps' => [['offset' => 1, 'line' => 1]]],
            ],
            'a join stripped as padding after the opener' => [
                "| ` |\n+ b ` |\n",
                ['type' => 'code', 'value' => 'b', 'cellWraps' => [['offset' => 0, 'line' => 1]]],
            ],
            'a join stripped as padding before the closer' => [
                "| ` a |\n+ ` |\n",
                ['type' => 'code', 'value' => 'a', 'cellWraps' => [['offset' => 1, 'line' => 1]]],
            ],
            'an interior join beside padding names its space' => [
                "| ` a |\n+ b ` |\n",
                ['type' => 'code', 'value' => 'a b', 'cellWraps' => [['offset' => 1, 'line' => 1]]],
            ],
            'a rowspan origin names the row' => [
                "| `a | x |\n| ^ | y |\n+ b` | z |\n",
                ['type' => 'code', 'value' => 'a | x b', 'cellWraps' => [['offset' => 5, 'line' => 1, 'row' => 1]]],
            ],
        ];
    }

    /**
     * @param string $source
     * @param array<string, mixed> $expected
     */
    #[DataProvider('provideSpans')]
    public function testThePayloadCarriesTheJoin(string $source, array $expected): void
    {
        $ast = (new AstCodec())->encode((new CarveConverter())->parse($source));
        $node = $ast['children'][0]['rows'][0]['cells'][0]['children'][0];
        unset($node['pos']);
        ksort($node);
        ksort($expected);

        $this->assertSame($expected, $node);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideRoundTrips(): array
    {
        return [
            'code span' => ["| `a |\n+ b` |\n"],
            'with text around' => ["| x `a |\n+ b` y |\n"],
            'math' => ["| $`a |\n+ |\n+ b` |\n"],
            'raw inline' => ["| `<b |\n+ c>`{=html} |\n"],
            'padding after the opener' => ["| ` |\n+ b ` |\n"],
            'padding before the closer' => ["| ` a |\n+ ` |\n"],
        ];
    }

    #[DataProvider('provideRoundTrips')]
    public function testTheWriterSplitsThePayloadBack(string $source): void
    {
        $this->assertSame(preg_replace('/\n\z/', '', $source), (new CarveConverter())->toCarve($source));
    }

    public function testOutsideATableTheJoinIsASpace(): void
    {
        $ast = (new AstCodec())->encode((new CarveConverter())->parse("| `a |\n+ b` |\n"));
        $code = $ast['children'][0]['rows'][0]['cells'][0]['children'][0];
        $document = (new AstCodec())->decode(['type' => 'document', 'children' => [['type' => 'paragraph', 'children' => [$code]]]] + ['srcByteLength' => 0]);

        $this->assertSame("`a b`\n", (new CarveRenderer())->render($document));
    }
}
