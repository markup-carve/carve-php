<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use MarkupCarve\Carve\Renderer\MarkdownRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MarkdownRoundtripPhaseOneTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function sourceProvider(): array
    {
        $corpus = dirname(__DIR__, 2) . '/spec/tests/corpus';

        return [
            '222-a-tab-as-the-first-character-of-a-definition-term' => [(string)file_get_contents($corpus . '/222-a-tab-as-the-first-character-of-a-definition-term.crv')],
            '227-a-definition-inside-a-definition-list-dd-is-collected-and-the-entry-keeps-no-trace' => [(string)file_get_contents($corpus . '/227-a-definition-inside-a-definition-list-dd-is-collected-and-the-entry-keeps-no-trace.crv')],
            '227-a-definition-inside-a-definition-list-dd-is-collected-and-the-entry-keeps-no-trace-2' => [(string)file_get_contents($corpus . '/227-a-definition-inside-a-definition-list-dd-is-collected-and-the-entry-keeps-no-trace-2.crv')],
            '116-fence-opener-with-a-nested-list-body-inside-a-list-item-6' => [(string)file_get_contents($corpus . '/116-fence-opener-with-a-nested-list-body-inside-a-list-item-6.crv')],
            '279-a-boundary-line-inside-an-open-fence-does-not-end-the-container-5' => [(string)file_get_contents($corpus . '/279-a-boundary-line-inside-an-open-fence-does-not-end-the-container-5.crv')],
            '279-a-boundary-line-inside-an-open-fence-does-not-end-the-container-10' => [(string)file_get_contents($corpus . '/279-a-boundary-line-inside-an-open-fence-does-not-end-the-container-10.crv')],
            '279-a-boundary-line-inside-an-open-fence-does-not-end-the-container-11' => [(string)file_get_contents($corpus . '/279-a-boundary-line-inside-an-open-fence-does-not-end-the-container-11.crv')],
            '160-indented-colon-fence-blocks-stay-literal-4' => [(string)file_get_contents($corpus . '/160-indented-colon-fence-blocks-stay-literal-4.crv')],
            '170-headings-inside-containers-are-not-wrapped' => [(string)file_get_contents($corpus . '/170-headings-inside-containers-are-not-wrapped.crv')],
            'definition-list-term-without-definition' => [":: Carve\n"],
            'definition-list-two-terms-one-definition' => [":: Carve\n:: Djot\n: Markup languages.\n"],
            'reference title' => ["[t][r]\n\n[r]: /u \"A title\"\n"],
            'authored list escape inside div' => ["::: note\na\n\\- m\nb\n:::\n"],
            'entity in fenced code' => ["::: note\n```\n&#45; code\n```\n:::\n"],
            'nested plain div' => [":::\n::::\nText\n::::\n:::\n"],
        ];
    }

    #[DataProvider('sourceProvider')]
    public function testRoundtripPreservesTree(string $source): void
    {
        $renderer = new MarkdownRenderer();
        $renderer->setRoundTripMode(true);
        $converter = CarveConverter::create(renderer: $renderer);
        $markdown = $converter->convert($source);
        $importer = new MarkdownToCarve();
        $report = $importer->convertWithFidelityReport($markdown);
        $imported = $report->value;
        $this->assertNotContains('carrier-markers-damaged', array_column($report->diagnostics, 'code'));
        $this->assertFalse($importer->hadDamagedCarrierMarkers(), $markdown);
        $codec = new AstCodec();
        $this->assertSame(
            $this->withoutPositions($codec->encode($converter->parse($source))),
            $this->withoutPositions($codec->encode($converter->parse($imported))),
            $markdown . "\n" . $imported,
        );
    }

    public function testEntityInIndentedMarkdownCodeStaysLiteral(): void
    {
        $importer = new MarkdownToCarve();
        $source = "<!-- carve: ::: note -->\n\n    &#45; code\n\n<!-- carve: ::: -->\n";
        $imported = $importer->convert($source);
        $this->assertFalse($importer->hadDamagedCarrierMarkers());
        $tree = (new AstCodec())->encode(CarveConverter::create()->parse($imported));
        $this->assertSame('code_block', $tree['children'][0]['children'][0]['type']);
        $this->assertSame("&#45; code\n", $tree['children'][0]['children'][0]['content']);
    }

    /**
     * @param array<string|int, mixed> $tree
     *
     * @return array<string|int, mixed>
     */
    private function withoutPositions(array $tree): array
    {
        unset($tree['pos'], $tree['srcByteLength']);
        foreach ($tree as $key => $value) {
            if (is_array($value)) {
                $tree[$key] = $this->withoutPositions($value);
            }
        }

        return $tree;
    }
}
