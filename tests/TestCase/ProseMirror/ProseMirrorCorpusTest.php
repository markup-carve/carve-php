<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\ProseMirror;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\ProseMirror\ProseMirrorRenderer;
use MarkupCarve\Carve\ProseMirror\ProseMirrorToCarve;
use MarkupCarve\Carve\ProseMirror\SchemaMap;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Sweeps the whole spec corpus through the bridge.
 *
 * The strict gate is deliberately narrow: a document whose types the editor
 * model fully covers - nothing dropped, nothing degraded to text - must return
 * byte-identical HTML. Documents that do lose something are allowed to differ,
 * because they must: a soft break becomes a space, a comment is gone. What is
 * NOT allowed is an exception, or a silent loss the renderer failed to report.
 *
 * The two ceilings below are ratchets. They may fall, and a rise means the bridge
 * regressed.
 */
class ProseMirrorCorpusTest extends TestCase
{
    /**
     * Documents the bridge carries with no loss at all. Raise only with a reason.
     *
     * Fell from 336 to 329 when the renderer began reporting state the editor
     * model cannot hold - an autolink, an alphabetic list, attributes on inline
     * code. Those seven documents did not start round-tripping worse; they
     * stopped being counted as intact while quietly losing their authored form.
     * A count that only moves when the measurement is honest is the point of
     * the ratchet, so this is a correction, not a regression.
     *
     * Rose to 344 when the span-placeholder walk stopped flattening a rowspan
     * that shared a row with a colspan (carve-php#565).
     *
     * @var int
     */
    private const MINIMUM_LOSSLESS = 344;

    /**
     * Fully-covered documents that still differ. Every one is a fidelity bug
     * worth fixing, so this is a ceiling that should keep falling.
     *
     * Pulled from 29 to the actual 5. At 29 against an actual 6 this could not
     * detect a regression until the bridge was five times worse than it was -
     * which is how carve-php#557 shipped two of them (the row attributes fixed
     * in carve-php#564 and the flattened rowspan in carve-php#565) with CI
     * green. A ceiling far above the measurement is not a ratchet.
     *
     * @var int
     */
    private const MAXIMUM_COVERED_BUT_DIFFERING = 5;

    public function testTheCorpusSurvivesTheBridge(): void
    {
        $files = glob(dirname(__DIR__, 3) . '/tests/spec/tests/corpus/*.crv') ?: [];
        $this->assertGreaterThan(400, count($files), 'the corpus was not found');

        $renderer = new ProseMirrorRenderer();
        $converter = new ProseMirrorToCarve();

        $lossless = 0;
        $coveredButDiffering = [];
        $threw = [];

        foreach ($files as $file) {
            $source = (string)file_get_contents($file);
            $name = basename($file, '.crv');

            try {
                $document = (new CarveConverter())->parse($source);
                $expected = (new CarveConverter())->render($document);

                $proseMirror = $renderer->render($document);
                $covered = $renderer->droppedTypes() === [] && $renderer->degradedTypes() === [];

                $actual = (new CarveConverter())->render($converter->convert($proseMirror));

                if (!$covered) {
                    continue;
                }

                if ($actual === $expected) {
                    $lossless++;

                    continue;
                }

                $coveredButDiffering[] = $name;
            } catch (Throwable $e) {
                $threw[$name] = $e->getMessage();
            }
        }

        $this->assertSame([], $threw, 'no corpus document may throw: ' . json_encode(array_slice($threw, 0, 3)));

        $this->assertGreaterThanOrEqual(
            self::MINIMUM_LOSSLESS,
            $lossless,
            sprintf('lossless round-trips dropped to %d; the bridge regressed', $lossless),
        );

        $this->assertLessThanOrEqual(
            self::MAXIMUM_COVERED_BUT_DIFFERING,
            count($coveredButDiffering),
            sprintf(
                'fully-covered documents that differ rose to %d: %s',
                count($coveredButDiffering),
                implode(', ', array_slice($coveredButDiffering, 0, 8)),
            ),
        );
    }

    public function testEveryAstTypeHasAMappedOrUnmappedDecision(): void
    {
        // The vendored map must keep up with this engine's own vocabulary, or a
        // new node type would fall out of the bridge with no diagnostic.
        $undecided = [];
        foreach (array_keys(AstCodec::schema()) as $type) {
            if (SchemaMap::nameFor($type) === null && SchemaMap::unmappedReason($type) === null) {
                $undecided[] = $type;
            }
        }

        $this->assertSame(
            [],
            $undecided,
            'types absent from resources/prosemirror-schema-map.json: ' . implode(', ', $undecided),
        );
    }

    public function testEveryMappedTypeCanBeBuiltBack(): void
    {
        // A name the renderer can emit but the converter cannot instantiate would
        // be a one-way bridge.
        $converter = new ProseMirrorToCarve();
        $unbuildable = [];

        foreach (SchemaMap::mappedTypes() as $type) {
            foreach (SchemaMap::namesFor($type) as $name) {
                if ($name === 'doc' || $name === 'carveEmbed') {
                    continue;
                }
                try {
                    $payload = SchemaMap::isMark($type)
                        ? [

                            'type' => 'doc',
                            'content' => [
                                [

                                    'type' => 'paragraph',
                                    'content' => [
                                        ['type' => 'text', 'text' => 'x', 'marks' => [['type' => $name]]],
                                    ],
                                ],
                            ],
                        ]
                        : ['type' => 'doc', 'content' => [['type' => $name]]];
                    $converter->convert($payload);
                } catch (Throwable $e) {
                    $unbuildable[$name] = $e->getMessage();
                }
            }
        }

        $this->assertSame([], $unbuildable, 'names the converter cannot build: ' . json_encode($unbuildable));
    }
}
