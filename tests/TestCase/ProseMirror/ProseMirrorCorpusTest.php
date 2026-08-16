<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\ProseMirror;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\ProseMirror\ProseMirrorRenderer;
use MarkupCarve\Carve\ProseMirror\ProseMirrorToCarve;
use MarkupCarve\Carve\ProseMirror\SchemaMap;
use MarkupCarve\Carve\Test\TestCase\CorpusPopulation;
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
     * RAISED FROM 374 TO THE MEASUREMENT, 493 (carve-php#1018). At 374 against
     * an actual 493 the floor sat 119 documents below the real value, so 119
     * documents could stop round-tripping losslessly before it noticed - a
     * ratchet that has slipped that far no longer ratchets. It was left behind
     * by the improvements the comments above record; each raised the actual and
     * none moved the floor.
     *
     * Rose from 493 to 782 when the bridge stopped dropping authored types:
     * figures with their captions, line blocks, comments, raw blocks and
     * inlines, literals, symbols, substitutions, inline footnotes, crossrefs,
     * citation groups, link reference definitions - and with the definitions
     * carried, `[text][label]` references (image references included) keep
     * their spelling instead of degrading to the inline form.
     *
     * Rose from 782 to 794 when the copy of the published map caught up with
     * carve-grammars and the bridge grew a composite figure: `figure_group` was
     * declared unmapped in a map copy eleven commits behind, so nine documents
     * reported the type dropped and came back as a plain div.
     *
     * @var int
     */
    private const MINIMUM_LOSSLESS = 794;

    /**
     * Documents that survive the round trip, COVERED OR NOT.
     *
     * `MINIMUM_LOSSLESS` counts only fully-covered documents, so it moves for
     * two unrelated reasons: the bridge losing fidelity, and a document
     * acquiring a degraded type it did not have before. The second is not a
     * regression. The floor was already unmet on main at 342 against 344 before
     * this change, so it had stopped guarding anything - and the escaped-pipe
     * change was wrongly suspected of causing that, because two documents
     * (`09-tables-4` and `56-table-cell-escaped-pipe`) legitimately acquired a
     * degraded type and left the count. Both still round-trip losslessly.
     *
     * This number cannot be moved that way, so it is the one that guards
     * fidelity. Keep both: coverage is worth tracking too, just not as a proxy
     * for correctness.
     *
     * RAISED FROM 419 TO THE MEASUREMENT, 631 (carve-php#1018). 212 documents
     * below the actual, on the number this docblock itself calls "the one that
     * guards fidelity" - so a fifth of the corpus could stop surviving the round
     * trip and the gate would still be green.
     *
     * A FLOOR, deliberately, not an equality: this count is meant to rise as the
     * bridge improves, and a gate that fails on improvement is its own defect.
     * It is the POPULATION that gets an equality
     * ({@see self::expectedCorpusSize()}), because that one may not drift at
     * all.
     *
     * Rose from 631 to 843 with the authored-type coverage the lossless
     * docblock above records.
     *
     * Rose from 843 to 855 with the composite figure the lossless docblock
     * above records.
     *
     * @var int
     */
    private const MINIMUM_SURVIVING = 855;

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
     * Now ZERO, and comparing canonical Carve rather than HTML - which is a
     * strictly harder bar than the 5 it replaces, since it also sees the
     * re-spellings HTML hides. A covered document that changes is either a
     * bridge bug or a missing declaration; there is no third case to leave
     * headroom for (carve-php#519).
     *
     * @var int
     */
    private const MAXIMUM_COVERED_BUT_DIFFERING = 0;

    public function testTheCorpusSurvivesTheBridge(): void
    {
        $files = glob(dirname(__DIR__, 3) . '/tests/spec/tests/corpus/*.crv') ?: [];
        // THE WHOLE CORPUS, not "enough of it". This was
        // `assertGreaterThan(400, ...)` against an actual 810, so 410 documents
        // could vanish and the sweep would still report success on the half that
        // remained - and every count below it would fall with them while staying
        // over its own floor. A population guard is the one number here that
        // must not have slack: the sweep either ran on the corpus or it did not.
        //
        // DERIVED from the pinned spec rather than written down, because a
        // literal goes stale the moment the submodule moves and the next bump
        // would either fail for no reason or be widened back into a floor.
        $this->assertSame(
            self::expectedCorpusSize(),
            count($files),
            'the corpus is not the one tests/spec pins: run `git submodule update --init`',
        );

        $renderer = new ProseMirrorRenderer();
        $converter = new ProseMirrorToCarve();

        $lossless = 0;
        $survives = 0;
        $coveredButDiffering = [];
        $threw = [];

        foreach ($files as $file) {
            $source = (string)file_get_contents($file);
            $name = basename($file, '.crv');

            try {
                $expected = (new CarveConverter())->render((new CarveConverter())->parse($source));
                // Canonical Carve, from its OWN parse. Rendering a document
                // mutates it - ids get assigned, references resolved - so
                // reusing one parse here fed the bridge a post-render tree and
                // made three link documents look like losses they are not.
                $expectedCarve = CarveConverter::carve()->render((new CarveConverter())->parse($source));

                $proseMirror = $renderer->render((new CarveConverter())->parse($source));
                $covered = $renderer->droppedTypes() === [] && $renderer->degradedTypes() === [];

                $back = $converter->convert($proseMirror);
                $actualCarve = CarveConverter::carve()->render($back);
                $actual = (new CarveConverter())->render((new CarveConverter())->parse($actualCarve));

                // Counted whether or not the document is covered, because
                // FIDELITY and COVERAGE are different things and the lossless
                // count below conflates them: a change that legitimately gives a
                // document a degraded type moves it out of `$lossless` without
                // changing whether it survives, which reads as a bridge
                // regression and is not one.
                if ($actual === $expected) {
                    $survives++;
                }

                if (!$covered) {
                    continue;
                }

                if ($actual === $expected) {
                    $lossless++;
                }

                // The silent-loss test, and the reason this compares CARVE
                // rather than HTML: a re-spelling renders byte-identically, so
                // an HTML comparison here cannot fail for the whole class of
                // defect this corpus exists to catch - which is how 55 of them
                // went unnoticed (carve-php#519). A covered document must come
                // back spelled as it was written, or the bridge has to say so.
                if ($actualCarve !== $expectedCarve) {
                    $coveredButDiffering[] = $name;
                }
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

        $this->assertGreaterThanOrEqual(
            self::MINIMUM_SURVIVING,
            $survives,
            sprintf('documents surviving the round trip dropped to %d; the bridge lost fidelity', $survives),
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

    /**
     * How many corpus documents the pinned spec should yield.
     *
     * The corpus is GENERATED from the spec's `resources/examples/*.md`: its
     * `scripts/generate-corpus.mjs` emits one `.crv` / `.html` pair per
     * `::: compare` block, so the block count in that submodule's own examples
     * is the population, and it moves with the pin instead of going stale
     * against it.
     *
     * The two counts are read from opposite ends of the same generator - the
     * blocks that go in, the files that come out - so a corpus that was
     * generated short, checked out partially, or bumped without regenerating
     * disagrees with its own source. Counting the files twice would agree with
     * itself no matter what, which is the check that cannot fail (see
     * markup-carve/carve#755).
     *
     * Counted by CorpusPopulation rather than here. A second copy of the same
     * glob is how this file kept its own path after the spec moved the
     * examples out of `docs/`, and one of the two would have to be found
     * again next time.
     */
    private static function expectedCorpusSize(): int
    {
        return CorpusPopulation::expectedSize();
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
