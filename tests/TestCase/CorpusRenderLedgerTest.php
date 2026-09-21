<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use PHPUnit\Framework\TestCase;
use function count;

/**
 * The Markdown, plain-text and ANSI output of EVERY corpus document, pinned.
 *
 * CorpusRenderFixtureTest asserts the reviewed bytes the spec ships, and that
 * population is thin: 39 documents on markdown, 13 on plain and 13 on ansi out
 * of 1740. Every other document reaches these three renderers only through
 * `compare:impls` in the spec repository, which runs on a nightly schedule and
 * reports engine-to-engine DISAGREEMENT, so a regression this engine makes on
 * its own surfaces the next morning, elsewhere, beside whatever else landed.
 *
 * A digest line is not a correctness claim; the reviewed fixtures are. It says
 * the output has not moved since it was recorded, and a change that moves it
 * has to rewrite the line, so the diff names every document affected.
 *
 * Regenerate with `php bin/render-ledger.php`. A moved document is a replaced
 * line and a new one is an added line.
 *
 * A document the ledger has never seen is tolerated while coverage holds. The
 * spec corpus grew by about 13 documents a day over the month to 2026-09-21
 * and the pin moves with it, so failing on an unrecorded document would put
 * most pin bumps red for bookkeeping. A regression moves output on documents
 * already recorded, so the signal survives a few unrecorded ones; a ledger
 * that has stopped describing the corpus does not, which is what
 * COVERAGE_FLOOR bounds.
 */
class CorpusRenderLedgerTest extends TestCase
{
    /**
     * Roughly a week of corpus growth. Below it the ledger needs regenerating.
     *
     * @var float
     */
    private const COVERAGE_FLOOR = 0.95;

    /**
     * @var array<string, array<string, string>>|null
     */
    private static ?array $rendered = null;

    public function testTheLedgerReadsEveryDocumentTheSpecExamplesDerive(): void
    {
        // The floor. Every sweep below asserts that a list came out empty, and
        // an unbuilt or empty submodule produces exactly that.
        $this->assertSame([], CorpusRenderLedger::read()['unparsable'], 'unparsable ledger line(s)');
        $this->assertSame(
            CorpusPopulation::expectedSize(),
            count(CorpusRenderLedger::slugs()),
            'the corpus is not the one tests/spec pins: run `git submodule update --init`',
        );
    }

    public function testTheLedgerStillDescribesTheCorpus(): void
    {
        $recorded = CorpusRenderLedger::read()['rows'];
        $slugs = CorpusRenderLedger::slugs();
        $covered = 0;
        foreach ($slugs as $slug) {
            if (isset($recorded[$slug])) {
                $covered++;
            }
        }

        $this->assertGreaterThanOrEqual(
            self::COVERAGE_FLOOR,
            $covered / count($slugs),
            'the ledger covers ' . $covered . ' of ' . count($slugs)
                . ' corpus documents: run `php bin/render-ledger.php`',
        );
    }

    public function testNoLedgerLineNamesADocumentThatIsGone(): void
    {
        $rendered = self::rendered();
        $stale = [];
        foreach (CorpusRenderLedger::read()['rows'] as $slug => $row) {
            if (!isset($rendered[$slug])) {
                $stale[] = $slug;
            }
        }

        $this->assertSame([], $stale, 'ledger line(s) whose document is gone: run `php bin/render-ledger.php`');
    }

    public function testTheLedgerRecordsTheOutputEveryRendererStillProduces(): void
    {
        $recorded = CorpusRenderLedger::read()['rows'];
        $moved = [];
        foreach (self::rendered() as $slug => $row) {
            if (!isset($recorded[$slug])) {
                continue;
            }
            foreach (CorpusRenderLedger::TARGETS as $target) {
                if ($recorded[$slug][$target] !== $row[$target]) {
                    $moved[] = $slug . ' ' . $target . ': ' . $recorded[$slug][$target] . ' -> ' . $row[$target];
                }
            }
        }

        $this->assertSame(
            [],
            $moved,
            count($moved) . ' recorded output(s) moved. If the change is intended, '
                . 'run `php bin/render-ledger.php` and review the rewritten lines.',
        );
    }

    /**
     * @return array<string, array<string, string>>
     */
    private static function rendered(): array
    {
        return self::$rendered ??= CorpusRenderLedger::render();
    }
}
