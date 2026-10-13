<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use MarkupCarve\Carve\Renderer\MarkdownRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class NestedDefinitionCarrierDepthTest extends TestCase
{
    /**
     * @return array<string, array{string, int}>
     */
    public static function nestedLists(): array
    {
        return [
            'one nested level' => [":: Outer\n: body\n\n  :: Inner\n  : inner body\n", 2],
            'two nested levels' => [":: Outer\n: body\n\n  :: Middle\n  : middle body\n\n    :: Inner\n    : inner body\n", 3],
        ];
    }

    #[DataProvider('nestedLists')]
    public function testDepthMarkersRoundTripNestedDefinitions(string $source, int $depth): void
    {
        $renderer = new MarkdownRenderer();
        $renderer->setRoundTripMode(true);
        $report = (new CarveConverter(renderer: $renderer))->convertWithReport($source);
        self::assertSame([], $report->losses);
        for ($level = 2; $level <= $depth; $level++) {
            self::assertStringContainsString('<!-- carve:' . $level . ' :: ', $report->value);
            self::assertStringContainsString('<!-- carve:' . $level . ' : -->', $report->value);
            self::assertStringContainsString('<!-- carve:' . $level . ' -->', $report->value);
        }
        $importer = new MarkdownToCarve();
        $imported = $importer->convert($report->value);
        self::assertFalse($importer->hadDamagedCarrierMarkers());
        self::assertSame($source, $imported);
        $tree = (new AstCodec())->encode((new CarveConverter())->parse($imported));
        self::assertSame($depth, substr_count(json_encode($tree, JSON_THROW_ON_ERROR), '"type":"definition_list"'));
    }

    public function testDepthTwoWithoutOuterListIsDamaged(): void
    {
        $markdown = "<!-- carve:2 :: Inner -->\n**Inner**\n\n<!-- carve:2 : -->\ninner body\n\n<!-- carve:2 -->\n";
        $importer = new MarkdownToCarve();
        $imported = $importer->convert($markdown);
        self::assertTrue($importer->hadDamagedCarrierMarkers());
        self::assertStringContainsString('<!-- carve:2 :: Inner -->', $imported);
    }

    public function testDepthOnOtherMarkerDropsThatMarkerAlone(): void
    {
        foreach (['<!-- carve:2 {.x} -->', '<!-- carve:2 [ -->'] as $invalid) {
            $markdown = $invalid . "\n<!-- carve: :: T -->\n**T**\n\n<!-- carve: : -->\nbody\n\n<!-- carve: -->\n";
            $result = (new MarkdownToCarve())->convertWithFidelityReport($markdown);
            self::assertStringContainsString(':: T', $result->value);
            self::assertContains('carrier-marker-dropped', array_column($result->diagnostics, 'code'));
            self::assertNotContains('carrier-markers-damaged', array_column($result->diagnostics, 'code'));
        }
    }
}
