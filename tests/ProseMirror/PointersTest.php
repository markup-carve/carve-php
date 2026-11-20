<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\ProseMirror;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\ProseMirror\Kit;
use MarkupCarve\Carve\ProseMirror\ProseMirrorRenderer;
use MarkupCarve\Carve\ProseMirror\ProseMirrorToCarve;
use MarkupCarve\Carve\Writer\CarveWriteError;
use PHPUnit\Framework\TestCase;

/**
 * `pointerFor()`: the ProseMirror node a node of the saved tree came from, for lint and export findings.
 */
final class PointersTest extends TestCase
{
    /**
     * The ProseMirror types a tree type comes from when its node has a pointer of its own.
     * An atom (`carveBlock`, `carveInline`) can stand for any of them.
     *
     * @var array<string, array<string>>
     */
    private const SOURCES = [
        'heading' => ['heading'],
        'text' => ['text', 'carveInline'],
        'list_item' => ['listItem', 'taskItem'],
        'table_row' => ['tableRow'],
        'table_cell' => ['tableCell', 'tableHeader'],
        'strong' => ['bold'],
        'emphasis' => ['italic'],
        'link' => ['link'],
    ];

    public function testAHeadingSkipPointsAtTheOffendingHeading(): void
    {
        $doc = [

            'type' => 'doc',
            'content' => [
                ['type' => 'heading', 'attrs' => ['level' => 1], 'content' => [['type' => 'text', 'text' => 'A']]],
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'x']]],
                ['type' => 'heading', 'attrs' => ['level' => 3], 'content' => [['type' => 'text', 'text' => 'C']]],
            ],
        ];
        $bridge = new ProseMirrorToCarve();
        $findings = array_values(array_filter(
            Carve::lint($bridge->convert($doc)),
            static fn ($finding): bool => $finding->code === 'heading-level-skip',
        ));
        $this->assertCount(1, $findings);
        $this->assertSame('/content/2', $bridge->pointerFor((string)$findings[0]->path));
        $this->assertSame('/content/2/content/0', $bridge->pointerFor('/children/2/children/0'));
        $this->assertSame('', $bridge->pointerFor(''));
        $this->assertSame('/content/2', $bridge->toTreeWithOrigins($doc)['pointers']['/children/2']);
    }

    public function testMarksTablesAndListsPointAtTheirEditorNodes(): void
    {
        $doc = (new ProseMirrorRenderer())->renderSource("- a *b*\n\n| x | < |\n| y | z |\n");
        $bridge = new ProseMirrorToCarve();
        $bridge->toTree($doc);
        $this->assertSame('/content/0/content/0', $bridge->pointerFor('/children/0/children/0'));
        $emphasis = '/children/0/children/0/children/0/children/1';
        $this->assertSame('/content/0/content/0/content/0/content/1/marks/0', $bridge->pointerFor($emphasis));
        $this->assertSame('/content/1/content/0/content/0', $bridge->pointerFor('/children/1/children/0/children/0/children/0'));
        // The `<` mark cell has no editor cell; its row stands for it.
        $this->assertSame('/content/1/content/0', $bridge->pointerFor('/children/1/children/0/children/0/children/1'));
        $this->assertSame('/content/1', $bridge->pointerFor('/children/1/children/0'));
    }

    public function testEveryNodeOfEveryConformanceTreeHasAnEditorNode(): void
    {
        $renderer = new ProseMirrorRenderer();
        $nodes = 0;
        $findings = 0;
        foreach (Documents::conformance() as $id => $source) {
            $doc = Kit::decode($renderer->renderJson(Carve::parse($source)));
            $this->assertIsArray($doc);
            $bridge = new ProseMirrorToCarve();
            ['tree' => $tree, 'pointers' => $own] = $bridge->toTreeWithOrigins($doc);
            foreach (self::paths($tree, '') as $path => $node) {
                $pointer = $bridge->pointerFor($path);
                $this->assertNotNull($pointer, "{$id}: {$path}");
                $pm = self::at($doc, $pointer);
                $this->assertIsString($pm['type'] ?? null, "{$id}: {$path} points at {$pointer}");
                if (isset($own[$path], self::SOURCES[$node['type']])) {
                    $sources = [...self::SOURCES[$node['type']], 'carveBlock', 'carveInline'];
                    $this->assertContains($pm['type'], $sources, "{$id}: the {$node['type']} at {$path}");
                }
                $nodes++;
            }
            try {
                $written = Carve::write($tree);
            } catch (CarveWriteError) {
                continue;
            }
            foreach (Carve::lint($written) as $finding) {
                if ($finding->path === null) {
                    continue;
                }
                $this->assertNotNull($bridge->pointerFor($finding->path), "{$id}: {$finding->code} at {$finding->path}");
                $findings++;
            }
        }
        $this->assertGreaterThan(15000, $nodes);
        $this->assertGreaterThan(500, $findings);
    }

    /**
     * @param array<string, mixed> $node
     * @param string $path
     *
     * @return array<string, array<string, mixed>>
     */
    private static function paths(array $node, string $path): array
    {
        $out = [$path => $node];
        foreach ($node as $key => $value) {
            if (!is_array($value)) {
                continue;
            }
            if (!array_is_list($value)) {
                if (is_string($value['type'] ?? null)) {
                    $out += self::paths($value, $path . '/' . $key);
                }

                continue;
            }
            foreach ($value as $index => $item) {
                if (is_array($item) && is_string($item['type'] ?? null)) {
                    $out += self::paths($item, $path . '/' . $key . '/' . $index);
                }
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $doc
     * @param string $pointer
     *
     * @return array<string, mixed>
     */
    private static function at(array $doc, string $pointer): array
    {
        $node = $doc;
        foreach (array_slice(explode('/', $pointer), 1) as $part) {
            $node = is_array($node) ? ($node[ctype_digit($part) ? (int)$part : $part] ?? null) : null;
        }

        return is_array($node) ? $node : [];
    }
}
